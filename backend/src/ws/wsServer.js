/* ============================================================
   WSSERVER.JS  —  WebSocket server
   Handles real-time messaging, presence, typing indicators,
   call signaling
   ============================================================ */

import { WebSocketServer, WebSocket } from 'ws';
import { verifyAccess } from '../utils/jwt.js';
import { query }        from '../db/pool.js';
import { cache }        from '../db/redis.js';
import logger           from '../utils/logger.js';

/* ── Connection store ───────────────────────────────────── */
// Map<userId, Set<WebSocket>>
const userSockets = new Map();

// Map<chatId, Set<userId>>
const chatMembers = new Map();

/* ── Init ───────────────────────────────────────────────── */
export function initWS(server) {
    const wss = new WebSocketServer({
        server,
        path: '/ws',
        perMessageDeflate: {
            zlibDeflateOptions:  { chunkSize: 1024, level: 6 },
            zlibInflateOptions:  { chunkSize: 10 * 1024 },
            serverNoContextTakeover: true,
            clientNoContextTakeover: true,
            concurrencyLimit: 10,
            threshold: 1024,
        },
    });

    wss.on('connection', async (ws, req) => {
        /* ── Authenticate ─────────────────────────────────── */
        let userId;
        try {
            const url   = new URL(req.url, 'ws://localhost');
            const token = url.searchParams.get('token');
            if (!token) throw new Error('No token');
            const payload = verifyAccess(token);
            userId = payload.sub;
        } catch {
            ws.close(4001, 'Unauthorized');
            return;
        }

        /* ── Register connection ──────────────────────────── */
        if (!userSockets.has(userId)) userSockets.set(userId, new Set());
        userSockets.get(userId).add(ws);

        ws.userId = userId;
        ws.isAlive = true;

        logger.debug(`[WS] Connected: ${userId} (${userSockets.get(userId).size} tabs)`);

        /* ── Mark online ──────────────────────────────────── */
        await _setOnline(userId, true);

        /* ── Subscribe to user's chats ────────────────────── */
        const { rows: chats } = await query(
            `SELECT chat_id FROM chat_members
             WHERE user_id = $1 AND left_at IS NULL`,
            [userId]
        );
        for (const { chat_id } of chats) {
            if (!chatMembers.has(chat_id)) chatMembers.set(chat_id, new Set());
            chatMembers.get(chat_id).add(userId);
        }

        /* ── Send initial presence ────────────────────────── */
        const onlineIds = [];
        for (const [uid] of userSockets) {
            if (uid !== userId) onlineIds.push(uid);
        }
        _send(ws, { type: 'PRESENCE_INIT', online: onlineIds });

        /* ── Heartbeat pong ───────────────────────────────── */
        ws.on('pong', () => { ws.isAlive = true; });

        /* ── Message handler ──────────────────────────────── */
        ws.on('message', data => {
            let msg;
            try { msg = JSON.parse(data); }
            catch { return; }
            _handleMessage(ws, userId, msg);
        });

        /* ── Disconnect ───────────────────────────────────── */
        ws.on('close', async () => {
            const sockets = userSockets.get(userId);
            if (sockets) {
                sockets.delete(ws);
                if (!sockets.size) {
                    userSockets.delete(userId);
                    await _setOnline(userId, false);
                    // Remove from chat rooms
                    for (const members of chatMembers.values()) members.delete(userId);
                    // Notify others
                    _broadcastPresence(userId, false);
                }
            }
            logger.debug(`[WS] Disconnected: ${userId}`);
        });

        ws.on('error', err => logger.error('[WS] Socket error:', err));
    });

    /* ── Heartbeat interval ───────────────────────────────── */
    setInterval(() => {
        wss.clients.forEach(ws => {
            if (!ws.isAlive) { ws.terminate(); return; }
            ws.isAlive = false;
            ws.ping();
        });
    }, 30_000);

    logger.info('✅ WebSocket server ready on /ws');
}

/* ── Message handler ────────────────────────────────────── */
async function _handleMessage(ws, userId, msg) {
    switch (msg.type) {

        /* Typing start/stop */
        case 'TYPING_START':
        case 'TYPING_STOP': {
            if (!msg.chat_id) break;
            const { rows } = await query(
                'SELECT 1 FROM chat_members WHERE chat_id = $1 AND user_id = $2 AND left_at IS NULL',
                [msg.chat_id, userId]
            );
            if (!rows.length) break;
            broadcast(msg.chat_id, {
                type:    msg.type,
                chat_id: msg.chat_id,
                user_id: userId,
            }, userId); // exclude sender
            break;
        }

        /* Read receipts */
        case 'READ_RECEIPT': {
            if (!msg.chat_id || !msg.msg_id) break;
            broadcast(msg.chat_id, {
                type:    'READ_RECEIPT',
                chat_id: msg.chat_id,
                msg_id:  msg.msg_id,
                user_id: userId,
            }, userId);
            break;
        }

        /* Call signaling */
        case 'CALL_OFFER':
        case 'CALL_ANSWER':
        case 'CALL_ICE':
        case 'CALL_END': {
            const { to, ...payload } = msg;
            if (!to) break;
            sendToUser(to, { ...payload, from: userId });
            break;
        }

        /* Ping */
        case 'PING': {
            _send(ws, { type: 'PONG', ts: Date.now() });
            break;
        }

        /* Join new chat (after being added) */
        case 'JOIN_CHAT': {
            if (!msg.chat_id) break;
            if (!chatMembers.has(msg.chat_id)) chatMembers.set(msg.chat_id, new Set());
            chatMembers.get(msg.chat_id).add(userId);
            break;
        }
    }
}

/* ── Helpers ────────────────────────────────────────────── */

/** Broadcast to all members of a chat */
export function broadcast(chatId, payload, excludeUserId = null) {
    const members = chatMembers.get(chatId);
    if (!members) return;

    const data = JSON.stringify(payload);
    for (const uid of members) {
        if (uid === excludeUserId) continue;
        sendToUser(uid, null, data);
    }
}

/** Send to specific user (all their tabs) */
export function sendToUser(userId, payload, raw) {
    const sockets = userSockets.get(userId);
    if (!sockets) return;
    const data = raw || JSON.stringify(payload);
    for (const ws of sockets) {
        if (ws.readyState === WebSocket.OPEN) ws.send(data);
    }
}

function _send(ws, payload) {
    if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(payload));
}

async function _setOnline(userId, online) {
    await Promise.all([
        query(
            'UPDATE users SET online = $1, last_seen = NOW() WHERE id = $2',
            [online, userId]
        ),
        cache.set(`online:${userId}`, online ? '1' : '0', 120),
    ]);
    _broadcastPresence(userId, online);
}

function _broadcastPresence(userId, online) {
    const payload = JSON.stringify({
        type:    online ? 'USER_ONLINE' : 'USER_OFFLINE',
        user_id: userId,
        ts:      new Date().toISOString(),
    });
    for (const [uid, sockets] of userSockets) {
        if (uid === userId) continue;
        for (const ws of sockets) {
            if (ws.readyState === WebSocket.OPEN) ws.send(payload);
        }
    }
}

export function getOnlineUsers() {
    return [...userSockets.keys()];
}

export function isOnline(userId) {
    return userSockets.has(userId);
}

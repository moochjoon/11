/* ============================================================
   SOCKET.JS  —  WebSocket client
   Auto-reconnect, message dispatch, presence, typing
   ============================================================ */

import { App, WS_PATH, getToken, emit, on } from './app.js';

/* ──────────────────────────────────────────────────────────
   1. STATE
────────────────────────────────────────────────────────── */
let ws            = null;
let reconnectTimer= null;
let reconnectDelay= 1000;
let missedPings   = 0;
let pingTimer     = null;
let intentionalClose = false;

const MAX_DELAY   = 30_000;
const PING_INTERVAL = 25_000;

/* ──────────────────────────────────────────────────────────
   2. CONNECT
────────────────────────────────────────────────────────── */
export function initSocket() {
    _connect();

    /* Reconnect on network restore */
    window.addEventListener('online',  () => { reconnectDelay = 1000; _connect(); });
    window.addEventListener('offline', () => _setStatus('offline'));

    /* Disconnect on page unload */
    window.addEventListener('beforeunload', () => {
        intentionalClose = true;
        ws?.close();
    });
}

function _connect() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

    const token  = getToken();
    if (!token)  return;

    const proto  = location.protocol === 'https:' ? 'wss' : 'ws';
    const url    = `${proto}://${location.host}${WS_PATH}?token=${encodeURIComponent(token)}`;

    ws           = new WebSocket(url);
    _setStatus('connecting');

    ws.onopen    = _onOpen;
    ws.onmessage = _onMessage;
    ws.onclose   = _onClose;
    ws.onerror   = _onError;
}

/* ──────────────────────────────────────────────────────────
   3. LIFECYCLE
────────────────────────────────────────────────────────── */
function _onOpen() {
    reconnectDelay = 1000;
    missedPings    = 0;
    _setStatus('connected');
    _startPing();
    emit('socket:connected', {});
    console.info('[WS] Connected');
}

function _onClose(e) {
    _stopPing();
    _setStatus('disconnected');
    emit('socket:disconnected', { code: e.code });

    if (intentionalClose || e.code === 4001) return; // unauthorized
    _scheduleReconnect();
}

function _onError(err) {
    console.warn('[WS] Error:', err);
}

function _scheduleReconnect() {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
        reconnectDelay = Math.min(reconnectDelay * 1.5, MAX_DELAY);
        _connect();
    }, reconnectDelay);
    console.info(`[WS] Reconnecting in ${reconnectDelay}ms`);
}

/* ──────────────────────────────────────────────────────────
   4. MESSAGE DISPATCH
────────────────────────────────────────────────────────── */
function _onMessage(e) {
    let msg;
    try { msg = JSON.parse(e.data); }
    catch { return; }

    switch (msg.type) {

        /* ── Chat / Messages ──────────────────────────────── */
        case 'NEW_MESSAGE':
            emit('msg:new',     msg.message);
            break;
        case 'MESSAGE_EDITED':
            emit('msg:edited',  msg.message);
            break;
        case 'MESSAGE_DELETED':
            emit('msg:deleted', msg);
            break;
        case 'MESSAGES_SEEN':
            emit('msg:seen',    msg);
            break;
        case 'READ_RECEIPT':
            emit('msg:receipt', msg);
            break;
        case 'REACTION_UPDATED':
            emit('msg:reaction', msg);
            break;

        /* ── Typing ───────────────────────────────────────── */
        case 'TYPING_START':
            _handleTyping(msg.chat_id, msg.user_id, true);
            break;
        case 'TYPING_STOP':
            _handleTyping(msg.chat_id, msg.user_id, false);
            break;

        /* ── Presence ─────────────────────────────────────── */
        case 'PRESENCE_INIT':
            for (const id of (msg.online || [])) App.onlineUsers.add(id);
            emit('presence:init', msg.online);
            break;
        case 'USER_ONLINE':
            App.onlineUsers.add(msg.user_id);
            emit('presence:online', msg);
            break;
        case 'USER_OFFLINE':
            App.onlineUsers.delete(msg.user_id);
            emit('presence:offline', msg);
            break;

        /* ── Calls ────────────────────────────────────────── */
        case 'CALL_OFFER':
        case 'CALL_ANSWER':
        case 'CALL_ICE':
        case 'CALL_END':
            emit(`call:${msg.type.slice(5).toLowerCase()}`, msg);
            break;

        /* ── System ───────────────────────────────────────── */
        case 'SYNC_COMPLETE':
            emit('sync:complete', msg);
            break;
        case 'PONG':
            missedPings = 0;
            break;
    }
}

/* ──────────────────────────────────────────────────────────
   5. TYPING HANDLER
────────────────────────────────────────────────────────── */
function _handleTyping(chatId, userId, isTyping) {
    const key = `${chatId}:${userId}`;

    if (isTyping) {
        emit('typing:start', { chatId, userId });
        // Auto-stop after 5s of no update
        clearTimeout(App.typingTimers.get(key));
        App.typingTimers.set(key, setTimeout(() => {
            emit('typing:stop', { chatId, userId });
            App.typingTimers.delete(key);
        }, 5000));
    } else {
        clearTimeout(App.typingTimers.get(key));
        App.typingTimers.delete(key);
        emit('typing:stop', { chatId, userId });
    }
}

/* ──────────────────────────────────────────────────────────
   6. SEND HELPERS
────────────────────────────────────────────────────────── */
export function send(payload) {
    if (ws?.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(payload));
        return true;
    }
    return false;
}

/* Typing throttle */
let typingDebounce = null;
let isTypingSent   = false;

export function sendTypingStart(chatId) {
    if (!isTypingSent) {
        send({ type: 'TYPING_START', chat_id: chatId });
        isTypingSent = true;
    }
    clearTimeout(typingDebounce);
    typingDebounce = setTimeout(() => {
        send({ type: 'TYPING_STOP', chat_id: chatId });
        isTypingSent = false;
    }, 3000);
}

export function sendTypingStop(chatId) {
    clearTimeout(typingDebounce);
    if (isTypingSent) {
        send({ type: 'TYPING_STOP', chat_id: chatId });
        isTypingSent = false;
    }
}

export function sendCallOffer(toUserId, sdp, chatId) {
    send({ type: 'CALL_OFFER', to: toUserId, sdp, chat_id: chatId });
}
export function sendCallAnswer(toUserId, sdp) {
    send({ type: 'CALL_ANSWER', to: toUserId, sdp });
}
export function sendIceCandidate(toUserId, candidate) {
    send({ type: 'CALL_ICE', to: toUserId, candidate });
}
export function sendCallEnd(toUserId) {
    send({ type: 'CALL_END', to: toUserId });
}

/* ──────────────────────────────────────────────────────────
   7. HEARTBEAT
────────────────────────────────────────────────────────── */
function _startPing() {
    _stopPing();
    pingTimer = setInterval(() => {
        if (ws?.readyState !== WebSocket.OPEN) return;
        if (missedPings >= 2) { ws.close(); return; }
        send({ type: 'PING', ts: Date.now() });
        missedPings++;
    }, PING_INTERVAL);
}

function _stopPing() {
    clearInterval(pingTimer);
    missedPings = 0;
}

/* ──────────────────────────────────────────────────────────
   8. STATUS INDICATOR
────────────────────────────────────────────────────────── */
function _setStatus(status) {
    emit('socket:status', { status });
    const dot = document.getElementById('connectionDot');
    if (!dot) return;
    dot.className = `connection-dot connection-dot--${status}`;
    dot.title     = { connecting: 'در حال اتصال...', connected: 'متصل', disconnected: 'قطع', offline: 'آفلاین' }[status] || '';
}

export { ws };

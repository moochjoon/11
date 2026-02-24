/* ============================================================
   MESSAGES ROUTES
   GET    /api/v1/messages/:chatId
   POST   /api/v1/messages
   PATCH  /api/v1/messages/:id
   DELETE /api/v1/messages/:id
   POST   /api/v1/messages/:id/react
   POST   /api/v1/messages/seen
   ============================================================ */

import { Router }    from 'express';
import { query, transaction } from '../db/pool.js';
import { auth }      from '../middlewares/auth.js';
import { broadcast } from '../ws/wsServer.js';

const router = Router();
router.use(auth);

/* ── Get messages (cursor-based) ────────────────────────── */
router.get('/:chatId', async (req, res, next) => {
    try {
        const { chatId }           = req.params;
        const { before, limit = 40 } = req.query;
        const lim = Math.min(Number(limit), 100);

        // Check membership
        const { rows: mem } = await query(
            'SELECT 1 FROM chat_members WHERE chat_id = $1 AND user_id = $2 AND left_at IS NULL',
            [chatId, req.user.id]
        );
        if (!mem.length) return res.status(403).json({ error: 'Not a member' });

        const { rows } = await query(
            `SELECT m.*,
                    row_to_json(u) AS sender,
                    (
                        SELECT json_agg(json_build_object('emoji', r.emoji, 'count', r.cnt, 'mine', r.mine))
                        FROM (
                            SELECT emoji,
                                   COUNT(*) AS cnt,
                                   BOOL_OR(user_id = $3) AS mine
                            FROM reactions WHERE msg_id = m.id GROUP BY emoji
                        ) r
                    ) AS reactions,
                    rm.id   AS reply_msg_id,
                    rm.text AS reply_msg_text,
                    ru.name AS reply_sender_name
             FROM   messages m
             LEFT JOIN users u  ON u.id = m.sender_id
             LEFT JOIN messages rm ON rm.id = m.reply_to_id
             LEFT JOIN users   ru ON ru.id = rm.sender_id
             WHERE  m.chat_id   = $1
               AND  m.is_deleted = false
               ${before ? 'AND m.created_at < $4' : ''}
             ORDER  BY m.created_at DESC
             LIMIT  $2`,
            before
                ? [chatId, lim, req.user.id, before]
                : [chatId, lim, req.user.id]
        );

        res.json({
            messages: rows.reverse(),
            has_more: rows.length === lim,
            cursor:   rows[0]?.created_at || null,
        });
    } catch (err) { next(err); }
});

/* ── Send message ───────────────────────────────────────── */
router.post('/', async (req, res, next) => {
    try {
        const {
            chat_id, type = 'text', text,
            media_url, media_thumb, media_mime, media_size,
            media_duration, media_width, media_height,
            file_name, latitude, longitude, location_title,
            reply_to_id, poll_id, is_silent = false,
        } = req.body;

        if (!chat_id) return res.status(400).json({ error: 'chat_id required' });
        if (type === 'text' && !text?.trim())
            return res.status(400).json({ error: 'text required' });

        const { rows: mem } = await query(
            'SELECT 1 FROM chat_members WHERE chat_id = $1 AND user_id = $2 AND left_at IS NULL',
            [chat_id, req.user.id]
        );
        if (!mem.length) return res.status(403).json({ error: 'Not a member' });

        const msg = await transaction(async client => {
            const { rows } = await client.query(
                `INSERT INTO messages
                    (chat_id, sender_id, type, text,
                     media_url, media_thumb, media_mime, media_size,
                     media_duration, media_width, media_height,
                     file_name, latitude, longitude, location_title,
                     reply_to_id, poll_id, is_silent)
                 VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18)
                 RETURNING *`,
                [chat_id, req.user.id, type, text?.trim(),
                    media_url, media_thumb, media_mime, media_size,
                    media_duration, media_width, media_height,
                    file_name, latitude, longitude, location_title,
                    reply_to_id, poll_id, is_silent]
            );
            const newMsg = rows[0];

            // Update chat last message
            await client.query(
                `UPDATE chats SET last_msg_id = $1, last_msg_at = NOW(), msg_count = msg_count + 1
                 WHERE id = $2`,
                [newMsg.id, chat_id]
            );

            // Increment unread for others
            await client.query(
                `UPDATE chat_members
                 SET unread_count = unread_count + 1
                 WHERE chat_id = $1 AND user_id != $2 AND left_at IS NULL`,
                [chat_id, req.user.id]
            );

            return newMsg;
        });

        // Include sender info
        const { rows: full } = await query(
            `SELECT m.*, row_to_json(u) AS sender
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.id = $1`,
            [msg.id]
        );

        // Broadcast via WebSocket
        broadcast(chat_id, { type: 'NEW_MESSAGE', message: full[0] });

        res.status(201).json(full[0]);
    } catch (err) { next(err); }
});

/* ── Edit message ───────────────────────────────────────── */
router.patch('/:id', async (req, res, next) => {
    try {
        const { text } = req.body;
        if (!text?.trim()) return res.status(400).json({ error: 'text required' });

        const { rows } = await query(
            `UPDATE messages
             SET text = $1, is_edited = true, updated_at = NOW()
             WHERE id = $2 AND sender_id = $3 AND type = 'text' AND is_deleted = false
             RETURNING *`,
            [text.trim(), req.params.id, req.user.id]
        );
        if (!rows.length) return res.status(404).json({ error: 'Message not found' });

        broadcast(rows[0].chat_id, { type: 'MESSAGE_EDITED', message: rows[0] });
        res.json(rows[0]);
    } catch (err) { next(err); }
});

/* ── Delete message ─────────────────────────────────────── */
router.delete('/:id', async (req, res, next) => {
    try {
        const { for_everyone = true } = req.query;

        const { rows: msgRows } = await query(
            'SELECT * FROM messages WHERE id = $1 AND is_deleted = false',
            [req.params.id]
        );
        if (!msgRows.length) return res.status(404).json({ error: 'Not found' });

        const msg = msgRows[0];
        const isOwn = msg.sender_id === req.user.id;

        if (!isOwn) {
            // Check if admin
            const { rows: admin } = await query(
                `SELECT 1 FROM chat_members
                 WHERE chat_id = $1 AND user_id = $2 AND role IN ('owner','admin')`,
                [msg.chat_id, req.user.id]
            );
            if (!admin.length) return res.status(403).json({ error: 'Forbidden' });
        }

        await query(
            'UPDATE messages SET is_deleted = true, text = NULL, media_url = NULL WHERE id = $1',
            [msg.id]
        );

        broadcast(msg.chat_id, {
            type:      'MESSAGE_DELETED',
            msg_id:    msg.id,
            chat_id:   msg.chat_id,
        });
        res.json({ deleted: true });
    } catch (err) { next(err); }
});

/* ── React ──────────────────────────────────────────────── */
router.post('/:id/react', async (req, res, next) => {
    try {
        const { emoji } = req.body;
        if (!emoji) return res.status(400).json({ error: 'emoji required' });

        const { rows: msgRows } = await query(
            'SELECT chat_id FROM messages WHERE id = $1', [req.params.id]
        );
        if (!msgRows.length) return res.status(404).json({ error: 'Not found' });

        // Toggle reaction
        const { rows: existing } = await query(
            'SELECT * FROM reactions WHERE msg_id = $1 AND user_id = $2',
            [req.params.id, req.user.id]
        );

        if (existing.length && existing[0].emoji === emoji) {
            await query('DELETE FROM reactions WHERE msg_id = $1 AND user_id = $2',
                [req.params.id, req.user.id]);
        } else {
            await query(
                `INSERT INTO reactions (msg_id, user_id, emoji)
                 VALUES ($1, $2, $3)
                 ON CONFLICT (msg_id, user_id) DO UPDATE SET emoji = $3, created_at = NOW()`,
                [req.params.id, req.user.id, emoji]
            );
        }

        const { rows: reactions } = await query(
            `SELECT emoji, COUNT(*) AS count, BOOL_OR(user_id = $2) AS mine
             FROM reactions WHERE msg_id = $1 GROUP BY emoji`,
            [req.params.id, req.user.id]
        );

        broadcast(msgRows[0].chat_id, {
            type:      'REACTION_UPDATED',
            msg_id:    req.params.id,
            reactions,
        });
        res.json({ reactions });
    } catch (err) { next(err); }
});

/* ── Mark seen ──────────────────────────────────────────── */
router.post('/seen', async (req, res, next) => {
    try {
        const { chat_id } = req.body;
        if (!chat_id) return res.status(400).json({ error: 'chat_id required' });

        await transaction(async client => {
            // Mark all unread messages as read
            const { rows: unread } = await client.query(
                `SELECT m.id FROM messages m
                 LEFT JOIN message_reads mr ON mr.msg_id = m.id AND mr.user_id = $2
                 WHERE m.chat_id = $1 AND m.sender_id != $2 AND mr.msg_id IS NULL
                   AND m.is_deleted = false`,
                [chat_id, req.user.id]
            );

            if (unread.length) {
                const ids = unread.map(r => r.id);
                await client.query(
                    `INSERT INTO message_reads (msg_id, user_id)
                     SELECT unnest($1::uuid[]), $2
                     ON CONFLICT DO NOTHING`,
                    [ids, req.user.id]
                );
            }

            // Reset unread count
            await client.query(
                `UPDATE chat_members SET unread_count = 0, last_read_msg_id = (
                    SELECT id FROM messages WHERE chat_id = $1 ORDER BY created_at DESC LIMIT 1
                 ) WHERE chat_id = $1 AND user_id = $2`,
                [chat_id, req.user.id]
            );
        });

        broadcast(chat_id, {
            type:    'MESSAGES_SEEN',
            chat_id,
            user_id: req.user.id,
        });
        res.json({ ok: true });
    } catch (err) { next(err); }
});

export default router;

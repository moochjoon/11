/* ============================================================
   AUTH ROUTES
   POST /api/v1/auth/send-otp
   POST /api/v1/auth/verify-otp
   POST /api/v1/auth/refresh
   POST /api/v1/auth/logout
   ============================================================ */

import { Router }  from 'express';
import bcrypt      from 'bcryptjs';
import { v4 }      from 'uuid';
import { query, transaction } from '../db/pool.js';
import { cache }   from '../db/redis.js';
import { signAccess, signRefresh, verifyRefresh, hashToken } from '../utils/jwt.js';
import { strictLimiter } from '../middlewares/rateLimiter.js';
import { auth }    from '../middlewares/auth.js';
import logger      from '../utils/logger.js';

const router = Router();

/* ── Send OTP ───────────────────────────────────────────── */
router.post('/send-otp', strictLimiter, async (req, res, next) => {
    try {
        const { phone } = req.body;
        if (!phone || !/^\+?[1-9]\d{7,14}$/.test(phone))
            return res.status(400).json({ error: 'Invalid phone number' });

        // Rate limit per phone
        const tries = await cache.incr(`otp:tries:${phone}`, 3600);
        if (tries > 5)
            return res.status(429).json({ error: 'Too many OTP requests' });

        const code      = Math.floor(100000 + Math.random() * 900000).toString();
        const codeHash  = await bcrypt.hash(code, 10);
        const expiresAt = new Date(Date.now() + 5 * 60 * 1000);

        await query(
            `INSERT INTO otps (phone, code_hash, expires_at)
             VALUES ($1, $2, $3)`,
            [phone, codeHash, expiresAt]
        );

        // In production: send via SMS provider
        if (process.env.NODE_ENV !== 'production') logger.info(`OTP for ${phone}: ${code}`);

        res.json({ message: 'OTP sent', expires_in: 300 });
    } catch (err) { next(err); }
});

/* ── Verify OTP ─────────────────────────────────────────── */
router.post('/verify-otp', strictLimiter, async (req, res, next) => {
    try {
        const { phone, code, name } = req.body;
        if (!phone || !code)
            return res.status(400).json({ error: 'phone and code required' });

        const { rows: otpRows } = await query(
            `SELECT * FROM otps
             WHERE phone = $1 AND used = false AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1`,
            [phone]
        );

        if (!otpRows.length)
            return res.status(400).json({ error: 'OTP expired or not found' });

        const otp = otpRows[0];

        if (otp.attempts >= 3) {
            await query('UPDATE otps SET used = true WHERE id = $1', [otp.id]);
            return res.status(429).json({ error: 'Too many attempts' });
        }

        const valid = await bcrypt.compare(String(code), otp.code_hash);
        if (!valid) {
            await query('UPDATE otps SET attempts = attempts + 1 WHERE id = $1', [otp.id]);
            return res.status(400).json({ error: 'Invalid OTP' });
        }

        await query('UPDATE otps SET used = true WHERE id = $1', [otp.id]);

        const result = await transaction(async client => {
            let { rows } = await client.query(
                'SELECT * FROM users WHERE phone = $1', [phone]
            );
            let isNew = false;

            if (!rows.length) {
                const ins = await client.query(
                    `INSERT INTO users (phone, name, color)
                     VALUES ($1, $2, $3) RETURNING *`,
                    [phone, name || phone, Math.floor(Math.random() * 12)]
                );
                rows  = ins.rows;
                isNew = true;
            } else {
                await client.query(
                    'UPDATE users SET last_seen = NOW(), online = true WHERE id = $1',
                    [rows[0].id]
                );
            }
            return { user: rows[0], isNew };
        });

        const { user, isNew } = result;
        const accessToken  = signAccess({ sub: user.id });
        const refreshToken = signRefresh({ sub: user.id });
        const tokenHash    = hashToken(refreshToken);
        const expiresAt    = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000);

        await query(
            `INSERT INTO refresh_tokens (user_id, token_hash, device_name, device_ip, expires_at)
             VALUES ($1, $2, $3, $4, $5)`,
            [user.id, tokenHash, req.headers['user-agent']?.slice(0, 120), req.ip, expiresAt]
        );

        res.json({
            access_token:  accessToken,
            refresh_token: refreshToken,
            is_new_user:   isNew,
            user: {
                id:       user.id,
                phone:    user.phone,
                name:     user.name,
                username: user.username,
                avatar:   user.avatar,
            },
        });
    } catch (err) { next(err); }
});

/* ── Refresh Token ──────────────────────────────────────── */
router.post('/refresh', async (req, res, next) => {
    try {
        const { refresh_token } = req.body;
        if (!refresh_token)
            return res.status(400).json({ error: 'refresh_token required' });

        const payload  = verifyRefresh(refresh_token);
        const tokenHash= hashToken(refresh_token);

        const { rows } = await query(
            `SELECT * FROM refresh_tokens
             WHERE token_hash = $1 AND expires_at > NOW()`,
            [tokenHash]
        );
        if (!rows.length)
            return res.status(401).json({ error: 'Refresh token invalid or expired' });

        // Rotate
        const newAccess  = signAccess({ sub: payload.sub });
        const newRefresh = signRefresh({ sub: payload.sub });
        const newHash    = hashToken(newRefresh);
        const expiresAt  = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000);

        await transaction(async client => {
            await client.query('DELETE FROM refresh_tokens WHERE token_hash = $1', [tokenHash]);
            await client.query(
                `INSERT INTO refresh_tokens (user_id, token_hash, device_name, device_ip, expires_at)
                 VALUES ($1, $2, $3, $4, $5)`,
                [payload.sub, newHash, req.headers['user-agent']?.slice(0, 120), req.ip, expiresAt]
            );
        });

        res.json({ access_token: newAccess, refresh_token: newRefresh });
    } catch (err) {
        if (err.name === 'JsonWebTokenError')
            return res.status(401).json({ error: 'Invalid refresh token' });
        next(err);
    }
});

/* ── Logout ─────────────────────────────────────────────── */
router.post('/logout', auth, async (req, res, next) => {
    try {
        const { refresh_token } = req.body;
        if (refresh_token) {
            const tokenHash = hashToken(refresh_token);
            await query('DELETE FROM refresh_tokens WHERE token_hash = $1', [tokenHash]);
        }
        await query(
            'UPDATE users SET online = false, last_seen = NOW() WHERE id = $1',
            [req.user.id]
        );
        res.json({ message: 'Logged out' });
    } catch (err) { next(err); }
});

export default router;

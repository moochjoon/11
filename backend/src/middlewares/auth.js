/* ============================================================
   AUTH MIDDLEWARE
   ============================================================ */

import { verifyAccess, extractBearer } from '../utils/jwt.js';
import { query }  from '../db/pool.js';
import { cache }  from '../db/redis.js';

export async function auth(req, res, next) {
    try {
        const token = extractBearer(req);
        if (!token) return res.status(401).json({ error: 'Unauthorized' });

        // Check blacklist
        const blocked = await cache.get(`bl:${token.slice(-16)}`);
        if (blocked) return res.status(401).json({ error: 'Token revoked' });

        const payload = verifyAccess(token);

        // Load user (check if deleted/banned)
        const { rows } = await query(
            'SELECT id, name, avatar, is_deleted FROM users WHERE id = $1',
            [payload.sub]
        );
        if (!rows.length || rows[0].is_deleted)
            return res.status(401).json({ error: 'Account not found' });

        req.user    = rows[0];
        req.user.id = rows[0].id;
        next();
    } catch (err) {
        if (err.name === 'TokenExpiredError')
            return res.status(401).json({ error: 'Token expired' });
        return res.status(401).json({ error: 'Invalid token' });
    }
}

export function optionalAuth(req, res, next) {
    const token = extractBearer(req);
    if (!token) { req.user = null; return next(); }
    auth(req, res, next);
}

/* ============================================================
   JWT.JS  —  Token helpers
   ============================================================ */

import jwt    from 'jsonwebtoken';
import crypto from 'crypto';

const ACCESS_SECRET  = process.env.JWT_SECRET         || 'dev_secret';
const REFRESH_SECRET = process.env.JWT_REFRESH_SECRET || 'dev_refresh';
const ACCESS_EXP     = process.env.JWT_EXPIRES_IN     || '30d';
const REFRESH_EXP    = process.env.JWT_REFRESH_EXPIRES_IN || '90d';

export function signAccess(payload) {
    return jwt.sign(payload, ACCESS_SECRET, { expiresIn: ACCESS_EXP, algorithm: 'HS256' });
}

export function signRefresh(payload) {
    return jwt.sign(payload, REFRESH_SECRET, { expiresIn: REFRESH_EXP, algorithm: 'HS256' });
}

export function verifyAccess(token) {
    return jwt.verify(token, ACCESS_SECRET);
}

export function verifyRefresh(token) {
    return jwt.verify(token, REFRESH_SECRET);
}

export function hashToken(token) {
    return crypto.createHash('sha256').update(token).digest('hex');
}

export function extractBearer(req) {
    const h = req.headers.authorization;
    if (h && h.startsWith('Bearer ')) return h.slice(7);
    return null;
}

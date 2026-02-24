/* ============================================================
   RATE LIMITER
   ============================================================ */

import rateLimit from 'express-rate-limit';

export const rateLimiter = rateLimit({
    windowMs:  Number(process.env.RATE_LIMIT_WINDOW_MS) || 900_000,
    max:       Number(process.env.RATE_LIMIT_MAX)       || 200,
    standardHeaders: true,
    legacyHeaders:   false,
    message: { error: 'Too many requests, please try again later.' },
    skip: req => process.env.NODE_ENV === 'test',
});

export const strictLimiter = rateLimit({
    windowMs: 60_000,
    max:      10,
    message:  { error: 'Rate limit exceeded.' },
});

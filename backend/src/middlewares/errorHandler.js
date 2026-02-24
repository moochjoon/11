/* ============================================================
   ERROR HANDLER
   ============================================================ */

import logger from '../utils/logger.js';

export function errorHandler(err, req, res, _next) {
    const status  = err.statusCode || err.status || 500;
    const message = err.message    || 'Internal Server Error';

    if (status >= 500) logger.error(`[${req.method}] ${req.url}`, err);

    res.status(status).json({
        error:   message,
        ...(process.env.NODE_ENV !== 'production' && { stack: err.stack }),
    });
}

export function notFound(req, res) {
    res.status(404).json({ error: `Route ${req.method} ${req.url} not found` });
}

export class AppError extends Error {
    constructor(message, statusCode = 400) {
        super(message);
        this.statusCode = statusCode;
    }
}

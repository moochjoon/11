/* ============================================================
   SERVER.JS  —  Namak Messenger Backend
   Express HTTP + WebSocket server bootstrap
   ============================================================ */

import 'dotenv/config';
import http          from 'http';
import app           from './app.js';
import { initWS }    from './ws/wsServer.js';
import { db }        from './db/pool.js';
import { redis }     from './db/redis.js';
import logger        from './utils/logger.js';

const PORT = process.env.PORT || 4000;
const HOST = process.env.HOST || '0.0.0.0';

/* ── Create HTTP server ─────────────────────────────────── */
const server = http.createServer(app);

/* ── Attach WebSocket ───────────────────────────────────── */
initWS(server);

/* ── Startup ────────────────────────────────────────────── */
async function start() {
    try {
        await db.connect();
        logger.info('✅ PostgreSQL connected');

        await redis.connect();
        logger.info('✅ Redis connected');

        server.listen(PORT, HOST, () => {
            logger.info(`🚀 Namak server running on http://${HOST}:${PORT}`);
            logger.info(`🌍 Environment: ${process.env.NODE_ENV}`);
        });
    } catch (err) {
        logger.error('❌ Startup failed:', err);
        process.exit(1);
    }
}

/* ── Graceful shutdown ──────────────────────────────────── */
async function shutdown(signal) {
    logger.info(`\n⚠️  ${signal} received — shutting down gracefully`);
    server.close(async () => {
        try {
            await db.end();
            await redis.quit();
            logger.info('✅ Connections closed. Goodbye!');
            process.exit(0);
        } catch (e) {
            logger.error(e);
            process.exit(1);
        }
    });
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT',  () => shutdown('SIGINT'));
process.on('uncaughtException',  err => { logger.error('UncaughtException', err);  process.exit(1); });
process.on('unhandledRejection', err => { logger.error('UnhandledRejection', err); process.exit(1); });

start();

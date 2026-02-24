/* ============================================================
   POOL.JS  —  PostgreSQL connection pool
   ============================================================ */

import pkg from 'pg';
const { Pool } = pkg;
import logger from '../utils/logger.js';

export const db = new Pool({
    host:     process.env.DB_HOST     || 'localhost',
    port:     Number(process.env.DB_PORT) || 5432,
    database: process.env.DB_NAME     || 'namak_db',
    user:     process.env.DB_USER     || 'namak_user',
    password: process.env.DB_PASS,
    max:      Number(process.env.DB_POOL_MAX)  || 20,
    idleTimeoutMillis: Number(process.env.DB_POOL_IDLE) || 10000,
    connectionTimeoutMillis: 5000,
    ssl: process.env.NODE_ENV === 'production' ? { rejectUnauthorized: false } : false,
});

db.on('error', err => logger.error('DB pool error:', err));

/* ── Query helper ───────────────────────────────────────── */
export async function query(text, params) {
    const start = Date.now();
    try {
        const res = await db.query(text, params);
        const ms  = Date.now() - start;
        if (ms > 200) logger.warn(`Slow query (${ms}ms): ${text.slice(0, 80)}`);
        return res;
    } catch (err) {
        logger.error('DB query error:', { text: text.slice(0, 80), err: err.message });
        throw err;
    }
}

/* ── Transaction helper ─────────────────────────────────── */
export async function transaction(fn) {
    const client = await db.connect();
    try {
        await client.query('BEGIN');
        const result = await fn(client);
        await client.query('COMMIT');
        return result;
    } catch (err) {
        await client.query('ROLLBACK');
        throw err;
    } finally {
        client.release();
    }
}

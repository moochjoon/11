/* ============================================================
   REDIS.JS  —  Redis client (sessions, cache, pub/sub)
   ============================================================ */

import { createClient } from 'redis';
import logger           from '../utils/logger.js';

const PREFIX = process.env.REDIS_PREFIX || 'namak:';

export const redis = createClient({
    url: process.env.REDIS_URL || 'redis://localhost:6379',
    socket: {
        reconnectStrategy: retries => Math.min(retries * 100, 3000),
        connectTimeout:    5000,
    },
});

redis.on('error',      err  => logger.error('Redis error:', err));
redis.on('reconnecting', () => logger.warn('Redis reconnecting...'));

/* ── Prefixed helpers ───────────────────────────────────── */
export const cache = {
    key: k => `${PREFIX}${k}`,

    async get(k) {
        const v = await redis.get(cache.key(k));
        if (!v) return null;
        try { return JSON.parse(v); } catch { return v; }
    },

    async set(k, v, ttlSec = 300) {
        await redis.set(
            cache.key(k),
            typeof v === 'string' ? v : JSON.stringify(v),
            { EX: ttlSec }
        );
    },

    async del(k)      { await redis.del(cache.key(k)); },
    async exists(k)   { return redis.exists(cache.key(k)); },

    async incr(k, ttlSec = 3600) {
        const key = cache.key(k);
        const n   = await redis.incr(key);
        if (n === 1) await redis.expire(key, ttlSec);
        return n;
    },

    async hset(k, field, v) {
        await redis.hSet(cache.key(k), field, JSON.stringify(v));
    },
    async hget(k, field) {
        const v = await redis.hGet(cache.key(k), field);
        if (!v) return null;
        try { return JSON.parse(v); } catch { return v; }
    },
    async hdel(k, field) { await redis.hDel(cache.key(k), field); },
    async hgetall(k) {
        const raw = await redis.hGetAll(cache.key(k));
        const out = {};
        for (const [f, v] of Object.entries(raw)) {
            try { out[f] = JSON.parse(v); } catch { out[f] = v; }
        }
        return out;
    },

    async sadd(k, member) { await redis.sAdd(cache.key(k), member); },
    async srem(k, member) { await redis.sRem(cache.key(k), member); },
    async smembers(k)     { return redis.sMembers(cache.key(k)); },
    async sismember(k, m) { return redis.sIsMember(cache.key(k), m); },
};

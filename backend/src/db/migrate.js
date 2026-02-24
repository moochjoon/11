/* ============================================================
   MIGRATE.JS  —  Database schema creation
   ============================================================ */

import 'dotenv/config';
import { db }    from './pool.js';
import logger    from '../utils/logger.js';

const SCHEMA = `
/* ── Extensions ─────────────────────────────────────────── */
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

/* ── ENUM types ─────────────────────────────────────────── */
DO $$ BEGIN
    CREATE TYPE chat_type      AS ENUM ('direct','group','channel','saved');
    CREATE TYPE msg_type       AS ENUM ('text','image','video','audio','voice','document','location','contact','sticker','gif','poll','system','call');
    CREATE TYPE msg_status     AS ENUM ('sending','sent','delivered','read','failed','deleted');
    CREATE TYPE member_role    AS ENUM ('owner','admin','member');
    CREATE TYPE call_type      AS ENUM ('audio','video');
    CREATE TYPE call_status    AS ENUM ('calling','active','ended','missed','rejected','busy');
    CREATE TYPE privacy_level  AS ENUM ('everyone','contacts','nobody');
EXCEPTION WHEN duplicate_object THEN null; END $$;

/* ── USERS ──────────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS users (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    phone           VARCHAR(20)  UNIQUE NOT NULL,
    username        VARCHAR(32)  UNIQUE,
    name            VARCHAR(64)  NOT NULL DEFAULT '',
    bio             VARCHAR(500) DEFAULT '',
    avatar          TEXT,
    avatar_thumb    TEXT,
    color           SMALLINT     DEFAULT 0,
    is_verified     BOOLEAN      DEFAULT false,
    is_bot          BOOLEAN      DEFAULT false,
    is_deleted      BOOLEAN      DEFAULT false,
    lang            VARCHAR(8)   DEFAULT 'fa',
    last_seen       TIMESTAMPTZ  DEFAULT NOW(),
    online          BOOLEAN      DEFAULT false,
    phone_privacy   privacy_level DEFAULT 'contacts',
    lastseen_privacy privacy_level DEFAULT 'contacts',
    created_at      TIMESTAMPTZ  DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_users_phone    ON users(phone);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_name_gin ON users USING gin(name gin_trgm_ops);

/* ── AUTH TOKENS ────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  TEXT NOT NULL UNIQUE,
    device_name VARCHAR(120),
    device_ip   INET,
    user_agent  TEXT,
    expires_at  TIMESTAMPTZ NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_rt_user  ON refresh_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_rt_token ON refresh_tokens(token_hash);

/* ── CONTACTS ───────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS contacts (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    owner_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        VARCHAR(64),
    is_blocked  BOOLEAN DEFAULT false,
    is_favorite BOOLEAN DEFAULT false,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(owner_id, target_id)
);
CREATE INDEX IF NOT EXISTS idx_contacts_owner  ON contacts(owner_id);
CREATE INDEX IF NOT EXISTS idx_contacts_target ON contacts(target_id);

/* ── CHATS ──────────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS chats (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type            chat_type    NOT NULL DEFAULT 'direct',
    title           VARCHAR(128),
    description     VARCHAR(500),
    avatar          TEXT,
    avatar_thumb    TEXT,
    color           SMALLINT     DEFAULT 0,
    is_verified     BOOLEAN      DEFAULT false,
    username        VARCHAR(32)  UNIQUE,
    invite_hash     VARCHAR(32)  UNIQUE,
    max_members     INT          DEFAULT 200000,
    slow_mode_sec   INT          DEFAULT 0,
    is_public       BOOLEAN      DEFAULT false,
    is_archived     BOOLEAN      DEFAULT false,
    pinned_msg_id   UUID,
    last_msg_id     UUID,
    last_msg_at     TIMESTAMPTZ,
    msg_count       BIGINT       DEFAULT 0,
    created_by      UUID         REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chats_type       ON chats(type);
CREATE INDEX IF NOT EXISTS idx_chats_last_msg   ON chats(last_msg_at DESC);
CREATE INDEX IF NOT EXISTS idx_chats_username   ON chats(username);

/* ── CHAT MEMBERS ───────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS chat_members (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    chat_id         UUID NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role            member_role  DEFAULT 'member',
    is_muted        BOOLEAN      DEFAULT false,
    mute_until      TIMESTAMPTZ,
    is_pinned       BOOLEAN      DEFAULT false,
    pin_order       SMALLINT     DEFAULT 0,
    unread_count    INT          DEFAULT 0,
    last_read_msg_id UUID,
    notify_filter   SMALLINT     DEFAULT 0,
    joined_at       TIMESTAMPTZ  DEFAULT NOW(),
    left_at         TIMESTAMPTZ,
    UNIQUE(chat_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_cm_chat   ON chat_members(chat_id);
CREATE INDEX IF NOT EXISTS idx_cm_user   ON chat_members(user_id);
CREATE INDEX IF NOT EXISTS idx_cm_pinned ON chat_members(user_id, is_pinned) WHERE is_pinned = true;

/* ── MESSAGES ───────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS messages (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    chat_id         UUID NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
    sender_id       UUID          REFERENCES users(id) ON DELETE SET NULL,
    type            msg_type     NOT NULL DEFAULT 'text',
    status          msg_status   NOT NULL DEFAULT 'sent',
    text            TEXT,
    media_url       TEXT,
    media_thumb     TEXT,
    media_mime      VARCHAR(64),
    media_size      BIGINT,
    media_duration  INT,
    media_width     INT,
    media_height    INT,
    file_name       VARCHAR(256),
    latitude        DECIMAL(10,7),
    longitude       DECIMAL(10,7),
    location_title  VARCHAR(128),
    reply_to_id     UUID          REFERENCES messages(id) ON DELETE SET NULL,
    forward_from_id UUID          REFERENCES messages(id) ON DELETE SET NULL,
    forward_chat_id UUID          REFERENCES chats(id)    ON DELETE SET NULL,
    poll_id         UUID,
    is_edited       BOOLEAN      DEFAULT false,
    is_deleted      BOOLEAN      DEFAULT false,
    is_pinned       BOOLEAN      DEFAULT false,
    is_silent       BOOLEAN      DEFAULT false,
    views           INT          DEFAULT 0,
    ttl_seconds     INT,
    expires_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_msg_chat   ON messages(chat_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_msg_sender ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_msg_reply  ON messages(reply_to_id) WHERE reply_to_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_msg_search ON messages USING gin(text gin_trgm_ops) WHERE text IS NOT NULL;

/* ── MESSAGE READS ──────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS message_reads (
    msg_id      UUID NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    user_id     UUID NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    read_at     TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (msg_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reads_msg  ON message_reads(msg_id);
CREATE INDEX IF NOT EXISTS idx_reads_user ON message_reads(user_id);

/* ── REACTIONS ──────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS reactions (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    msg_id      UUID NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    user_id     UUID NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    emoji       VARCHAR(16) NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(msg_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_msg ON reactions(msg_id);

/* ── POLLS ──────────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS polls (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    question    TEXT        NOT NULL,
    options     JSONB       NOT NULL DEFAULT '[]',
    is_anonymous BOOLEAN   DEFAULT true,
    is_multiple  BOOLEAN   DEFAULT false,
    is_quiz      BOOLEAN   DEFAULT false,
    correct_idx  SMALLINT,
    closed_at   TIMESTAMPTZ,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS poll_votes (
    poll_id     UUID        NOT NULL REFERENCES polls(id)   ON DELETE CASCADE,
    user_id     UUID        NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    option_idxs SMALLINT[]  NOT NULL DEFAULT '{}',
    voted_at    TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (poll_id, user_id)
);

/* ── CALLS ──────────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS calls (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    chat_id     UUID NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
    caller_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type        call_type   NOT NULL DEFAULT 'audio',
    status      call_status NOT NULL DEFAULT 'calling',
    started_at  TIMESTAMPTZ,
    ended_at    TIMESTAMPTZ,
    duration    INT,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_calls_chat   ON calls(chat_id);
CREATE INDEX IF NOT EXISTS idx_calls_caller ON calls(caller_id);

/* ── PUSH SUBSCRIPTIONS ─────────────────────────────────── */
CREATE TABLE IF NOT EXISTS push_subs (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint    TEXT NOT NULL UNIQUE,
    p256dh      TEXT NOT NULL,
    auth        TEXT NOT NULL,
    device_name VARCHAR(120),
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_push_user ON push_subs(user_id);

/* ── OTP ────────────────────────────────────────────────── */
CREATE TABLE IF NOT EXISTS otps (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    phone       VARCHAR(20) NOT NULL,
    code_hash   TEXT        NOT NULL,
    attempts    SMALLINT    DEFAULT 0,
    expires_at  TIMESTAMPTZ NOT NULL,
    used        BOOLEAN     DEFAULT false,
    created_at  TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_otp_phone ON otps(phone, expires_at);

/* ── UPDATED_AT trigger ─────────────────────────────────── */
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DO $$ DECLARE t TEXT;
BEGIN FOR t IN SELECT unnest(ARRAY['users','chats','messages']) LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS trg_%s_updated ON %s', t, t);
    EXECUTE format('CREATE TRIGGER trg_%s_updated BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION set_updated_at()', t, t);
END LOOP; END $$;
`;

async function migrate() {
    try {
        await db.query(SCHEMA);
        logger.info('✅ Migration complete');
    } catch (err) {
        logger.error('❌ Migration failed:', err);
        process.exit(1);
    } finally {
        await db.end();
    }
}

migrate();

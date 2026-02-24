/* ============================================================
   APP.JS  —  Express application factory
   ============================================================ */

import express       from 'express';
import cors          from 'cors';
import helmet        from 'helmet';
import compression   from 'compression';
import morgan        from 'morgan';
import path          from 'path';
import { fileURLToPath } from 'url';

import { rateLimiter }   from './middlewares/rateLimiter.js';
import { errorHandler }  from './middlewares/errorHandler.js';
import { notFound }      from './middlewares/notFound.js';
import logger            from './utils/logger.js';

/* Routes */
import authRoutes        from './routes/auth.js';
import userRoutes        from './routes/users.js';
import chatRoutes        from './routes/chats.js';
import messageRoutes     from './routes/messages.js';
import contactRoutes     from './routes/contacts.js';
import fileRoutes        from './routes/files.js';
import pushRoutes        from './routes/push.js';
import callRoutes        from './routes/calls.js';
import widgetRoutes      from './routes/widgets.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();

/* ── Security ───────────────────────────────────────────── */
app.use(helmet({
    crossOriginResourcePolicy: { policy: 'cross-origin' },
    contentSecurityPolicy: false,
}));

app.use(cors({
    origin: (process.env.CORS_ORIGIN || 'http://localhost:3000').split(','),
    credentials:      true,
    methods:          ['GET','POST','PUT','PATCH','DELETE','OPTIONS'],
    allowedHeaders:   ['Content-Type','Authorization','X-Request-ID'],
    exposedHeaders:   ['X-Total-Count','X-Next-Cursor'],
}));

/* ── Parsing ────────────────────────────────────────────── */
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true, limit: '2mb' }));
app.use(compression());

/* ── Logging ────────────────────────────────────────────── */
app.use(morgan('combined', {
    stream: { write: msg => logger.http(msg.trim()) },
    skip:   (req) => req.url === '/health',
}));

/* ── Rate limit ─────────────────────────────────────────── */
app.use('/api/', rateLimiter);

/* ── Static uploads ─────────────────────────────────────── */
app.use('/uploads', express.static(path.join(__dirname, '../../uploads'), {
    maxAge:   '7d',
    etag:     true,
    lastModified: true,
}));

/* ── Health ─────────────────────────────────────────────── */
app.get('/health', (_req, res) => res.json({
    status: 'ok',
    ts:     new Date().toISOString(),
    env:    process.env.NODE_ENV,
}));

/* ── API Routes ─────────────────────────────────────────── */
const API = '/api/v1';
app.use(`${API}/auth`,     authRoutes);
app.use(`${API}/users`,    userRoutes);
app.use(`${API}/chats`,    chatRoutes);
app.use(`${API}/messages`, messageRoutes);
app.use(`${API}/contacts`, contactRoutes);
app.use(`${API}/files`,    fileRoutes);
app.use(`${API}/push`,     pushRoutes);
app.use(`${API}/calls`,    callRoutes);
app.use(`${API}/widgets`,  widgetRoutes);

/* ── 404 / Error ────────────────────────────────────────── */
app.use(notFound);
app.use(errorHandler);

export default app;

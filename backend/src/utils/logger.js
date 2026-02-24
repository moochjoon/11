/* ============================================================
   LOGGER.JS  —  Simple structured logger
   ============================================================ */

const LEVELS = { error:0, warn:1, info:2, http:3, debug:4 };
const COLORS = { error:'\x1b[31m', warn:'\x1b[33m', info:'\x1b[36m', http:'\x1b[35m', debug:'\x1b[90m' };
const RESET  = '\x1b[0m';

const activeLevel = LEVELS[process.env.LOG_LEVEL] ?? LEVELS.info;
const isProd      = process.env.NODE_ENV === 'production';

function log(level, msg, meta) {
    if (LEVELS[level] > activeLevel) return;
    const ts   = new Date().toISOString();
    const color= isProd ? '' : (COLORS[level] || '');
    const reset= isProd ? '' : RESET;
    const line = `${color}[${ts}] [${level.toUpperCase()}] ${msg}${reset}`;
    if (meta) console[level === 'error' ? 'error' : 'log'](line, meta);
    else      console[level === 'error' ? 'error' : 'log'](line);
}

export default {
    error: (msg, meta) => log('error', msg, meta),
    warn:  (msg, meta) => log('warn',  msg, meta),
    info:  (msg, meta) => log('info',  msg, meta),
    http:  (msg, meta) => log('http',  msg, meta),
    debug: (msg, meta) => log('debug', msg, meta),
};

/* ============================================================
   APP.JS  —  Namak Messenger
   Core bootstrap: init, router, global state, event bus,
   theme, locale, SW registration, connection manager
   ============================================================ */

'use strict';

/* ──────────────────────────────────────────────────────────
   1. CONSTANTS
────────────────────────────────────────────────────────── */
const APP_NAME        = 'Namak';
const APP_VERSION     = '1.0.0';
const API_BASE        = '/api';
const WS_URL          = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`;
const SW_PATH         = '/sw.js';
const STORAGE_PREFIX  = 'namak_';
const MAX_FILE_SIZE   = 50 * 1024 * 1024;   // 50 MB
const MAX_IMAGE_SIZE  = 10 * 1024 * 1024;   // 10 MB
const SUPPORTED_IMG   = ['image/jpeg','image/png','image/gif','image/webp'];
const SUPPORTED_VIDEO = ['video/mp4','video/webm','video/ogg'];
const SUPPORTED_AUDIO = ['audio/mpeg','audio/ogg','audio/webm','audio/wav'];

/* ──────────────────────────────────────────────────────────
   2. EVENT BUS  (pub/sub)
────────────────────────────────────────────────────────── */
const EventBus = (() => {
    const listeners = {};

    return {
        on(event, fn, options = {}) {
            if (!listeners[event]) listeners[event] = [];
            listeners[event].push({ fn, once: !!options.once });
            return () => this.off(event, fn);
        },
        once(event, fn) {
            return this.on(event, fn, { once: true });
        },
        off(event, fn) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter(l => l.fn !== fn);
        },
        emit(event, data) {
            if (!listeners[event]) return;
            listeners[event] = listeners[event].filter(l => {
                try { l.fn(data); } catch(e) { console.error(`EventBus[${event}]:`, e); }
                return !l.once;
            });
        },
        clear(event) {
            if (event) delete listeners[event];
            else Object.keys(listeners).forEach(k => delete listeners[k]);
        }
    };
})();

/* ──────────────────────────────────────────────────────────
   3. GLOBAL STATE
────────────────────────────────────────────────────────── */
const State = (() => {
    const store    = {};
    const watchers = {};

    return {
        get(key, fallback = null) {
            return key in store ? store[key] : fallback;
        },
        set(key, value) {
            const prev = store[key];
            store[key] = value;
            if (JSON.stringify(prev) !== JSON.stringify(value)) {
                EventBus.emit(`state:${key}`, { value, prev });
                EventBus.emit('state:change', { key, value, prev });
            }
        },
        update(key, updater) {
            this.set(key, updater(this.get(key)));
        },
        watch(key, fn) {
            return EventBus.on(`state:${key}`, ({ value, prev }) => fn(value, prev));
        },
        dump() { return { ...store }; }
    };
})();

/* ──────────────────────────────────────────────────────────
   4. LOCAL STORAGE WRAPPER
────────────────────────────────────────────────────────── */
const Store = {
    get(key, fallback = null) {
        try {
            const raw = localStorage.getItem(STORAGE_PREFIX + key);
            return raw !== null ? JSON.parse(raw) : fallback;
        } catch { return fallback; }
    },
    set(key, value) {
        try { localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value)); return true; }
        catch { return false; }
    },
    remove(key) {
        localStorage.removeItem(STORAGE_PREFIX + key);
    },
    clear() {
        Object.keys(localStorage)
            .filter(k => k.startsWith(STORAGE_PREFIX))
            .forEach(k => localStorage.removeItem(k));
    },
    /* Session storage (tab-scoped) */
    session: {
        get(key, fallback = null) {
            try {
                const raw = sessionStorage.getItem(STORAGE_PREFIX + key);
                return raw !== null ? JSON.parse(raw) : fallback;
            } catch { return fallback; }
        },
        set(key, value) {
            try { sessionStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value)); return true; }
            catch { return false; }
        },
        remove(key) { sessionStorage.removeItem(STORAGE_PREFIX + key); }
    }
};

/* ──────────────────────────────────────────────────────────
   5. HTTP CLIENT
────────────────────────────────────────────────────────── */
const Http = (() => {
    let _token = null;

    function setToken(t) { _token = t; }

    async function request(method, path, data = null, options = {}) {
        const url      = path.startsWith('http') ? path : API_BASE + path;
        const isForm   = data instanceof FormData;
        const headers  = { ...options.headers };
        if (_token)    headers['Authorization'] = `Bearer ${_token}`;
        if (!isForm)   headers['Content-Type']  = 'application/json';
        headers['X-Requested-With'] = 'XMLHttpRequest';

        const config = {
            method,
            headers,
            signal: options.signal,
            credentials: 'same-origin',
        };
        if (data) config.body = isForm ? data : JSON.stringify(data);

        let res;
        try {
            res = await fetch(url, config);
        } catch (err) {
            if (err.name === 'AbortError') throw err;
            throw new NetworkError('Network unreachable', 0);
        }

        let body;
        const ct = res.headers.get('content-type') || '';
        try {
            body = ct.includes('application/json') ? await res.json() : await res.text();
        } catch { body = null; }

        if (!res.ok) {
            if (res.status === 401) EventBus.emit('auth:unauthorized');
            if (res.status === 429) EventBus.emit('auth:rateLimit', { retryAfter: res.headers.get('Retry-After') });
            const msg = (body && body.message) || res.statusText || 'Server error';
            throw new ApiError(msg, res.status, body);
        }

        return body;
    }

    return {
        setToken,
        get:    (path, opts)        => request('GET',    path, null, opts),
        post:   (path, data, opts)  => request('POST',   path, data, opts),
        put:    (path, data, opts)  => request('PUT',    path, data, opts),
        patch:  (path, data, opts)  => request('PATCH',  path, data, opts),
        delete: (path, opts)        => request('DELETE', path, null, opts),
        upload: (path, form, opts)  => request('POST',   path, form, opts),
    };
})();

/* ──────────────────────────────────────────────────────────
   6. CUSTOM ERRORS
────────────────────────────────────────────────────────── */
class ApiError extends Error {
    constructor(message, status, body) {
        super(message);
        this.name   = 'ApiError';
        this.status = status;
        this.body   = body;
    }
}
class NetworkError extends Error {
    constructor(message, status) {
        super(message);
        this.name   = 'NetworkError';
        this.status = status;
    }
}

/* ──────────────────────────────────────────────────────────
   7. THEME MANAGER
────────────────────────────────────────────────────────── */
const ThemeManager = (() => {
    const THEMES  = ['light', 'dark', 'system'];
    const ACCENTS = ['blue','teal','green','purple','pink','orange','red','indigo'];

    let _mq = null;  // media query for system pref

    function _apply(theme) {
        const resolved = theme === 'system'
            ? (_mq && _mq.matches ? 'dark' : 'light')
            : theme;
        document.documentElement.setAttribute('data-theme', resolved);
        State.set('theme:resolved', resolved);
        document.querySelector('meta[name="theme-color"]')
            ?.setAttribute('content', resolved === 'dark' ? '#1a1a1a' : '#ffffff');
    }

    function setTheme(theme) {
        if (!THEMES.includes(theme)) theme = 'system';
        Store.set('theme', theme);
        State.set('theme', theme);
        _apply(theme);
    }

    function setAccent(color) {
        const hex = color.startsWith('#') ? color
            : getComputedStyle(document.documentElement)
            .getPropertyValue(`--accent-${color}`).trim() || '#2196f3';
        // Convert hex to RGB for CSS variable
        const r = parseInt(hex.slice(1,3),16);
        const g = parseInt(hex.slice(3,5),16);
        const b = parseInt(hex.slice(5,7),16);
        document.documentElement.style.setProperty('--primary', hex);
        document.documentElement.style.setProperty('--primary-rgb', `${r},${g},${b}`);
        Store.set('accent', color);
        State.set('accent', color);
    }

    function setChatBg(bg) {
        document.body.dataset.chatBg = bg;
        Store.set('chat_bg', bg);
    }

    function setBubbleStyle(style) {
        document.body.dataset.bubbleStyle = style;
        Store.set('bubble_style', style);
    }

    function setFontSize(size) {
        // size: 12–20
        size = Math.min(20, Math.max(12, parseInt(size)));
        document.documentElement.style.setProperty('--msg-font-size', size + 'px');
        Store.set('font_size', size);
    }

    function init() {
        _mq = window.matchMedia('(prefers-color-scheme: dark)');
        _mq.addEventListener('change', () => {
            if (State.get('theme') === 'system') _apply('system');
        });

        const theme  = Store.get('theme',       'system');
        const accent = Store.get('accent',       'blue');
        const chatBg = Store.get('chat_bg',      'none');
        const bubble = Store.get('bubble_style', 'rounded');
        const fSize  = Store.get('font_size',    14);

        setTheme(theme);
        setAccent(accent);
        setChatBg(chatBg);
        setBubbleStyle(bubble);
        setFontSize(fSize);
    }

    return { init, setTheme, setAccent, setChatBg, setBubbleStyle, setFontSize, THEMES, ACCENTS };
})();

/* ──────────────────────────────────────────────────────────
   8. LOCALE / I18N
────────────────────────────────────────────────────────── */
const I18n = (() => {
    let _strings  = {};
    let _locale   = 'fa';
    let _dir      = 'rtl';

    const RTL_LOCALES = ['fa','ar','he','ur'];

    function t(key, params = {}) {
        let str = _strings[key] || key;
        Object.entries(params).forEach(([k, v]) => {
            str = str.replaceAll(`{${k}}`, v);
        });
        return str;
    }

    function plural(key, n) {
        // Persian/Arabic pluralization
        if (_locale === 'fa' || _locale === 'ar') {
            return n === 0 ? t(`${key}.zero`)  :
                n === 1 ? t(`${key}.one`)   : t(`${key}.many`, { n });
        }
        return n === 1 ? t(`${key}.one`) : t(`${key}.many`, { n });
    }

    async function load(locale) {
        try {
            const data = await Http.get(`/lang/${locale}.json`);
            _strings = data;
            _locale  = locale;
            _dir     = RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr';
            document.documentElement.lang = locale;
            document.documentElement.dir  = _dir;
            document.body.dataset.locale  = locale;
            State.set('locale', locale);
            State.set('dir', _dir);
            EventBus.emit('locale:changed', { locale, dir: _dir });
        } catch(e) {
            console.warn('[I18n] Failed to load locale:', locale, e);
        }
    }

    function formatTime(date, opts = {}) {
        date = date instanceof Date ? date : new Date(date);
        return new Intl.DateTimeFormat(_locale, {
            hour:   '2-digit',
            minute: '2-digit',
            ...opts
        }).format(date);
    }

    function formatDate(date, opts = {}) {
        date = date instanceof Date ? date : new Date(date);
        return new Intl.DateTimeFormat(_locale, {
            year:  'numeric',
            month: 'long',
            day:   'numeric',
            ...opts
        }).format(date);
    }

    function formatRelative(date) {
        date = date instanceof Date ? date : new Date(date);
        const now   = Date.now();
        const diff  = now - date.getTime();
        const mins  = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days  = Math.floor(diff / 86400000);

        if (diff < 60000)   return t('time.just_now');
        if (mins  < 60)     return t('time.mins_ago',  { n: mins  });
        if (hours < 24)     return t('time.hours_ago', { n: hours });
        if (days  < 7)      return t('time.days_ago',  { n: days  });
        return formatDate(date, { month: 'short', day: 'numeric' });
    }

    function formatFileSize(bytes) {
        if (bytes < 1024)                  return bytes + ' B';
        if (bytes < 1024 * 1024)           return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024)   return (bytes / (1024*1024)).toFixed(1) + ' MB';
        return (bytes / (1024*1024*1024)).toFixed(2) + ' GB';
    }

    function getLocale() { return _locale; }
    function getDir()    { return _dir; }

    return { t, plural, load, formatTime, formatDate, formatRelative, formatFileSize, getLocale, getDir };
})();

/* ──────────────────────────────────────────────────────────
   9. CONNECTION MANAGER
────────────────────────────────────────────────────────── */
const Connection = (() => {
    let _online = navigator.onLine;
    let _wsUp   = false;

    function _updateOnline(online) {
        if (_online === online) return;
        _online = online;
        State.set('online', online);
        EventBus.emit(online ? 'connection:online' : 'connection:offline');
        _renderStatusBar(online ? 'online' : 'offline');
    }

    function _renderStatusBar(status) {
        const bar = document.getElementById('statusBar');
        if (!bar) return;
        bar.dataset.status = status;
        bar.hidden = false;
        bar.textContent = status === 'online'
            ? I18n.t('connection.online')
            : status === 'offline'
                ? I18n.t('connection.offline')
                : I18n.t('connection.connecting');
        if (status === 'online') {
            setTimeout(() => { bar.hidden = true; }, 3000);
        }
    }

    function init() {
        State.set('online', _online);
        window.addEventListener('online',  () => _updateOnline(true));
        window.addEventListener('offline', () => _updateOnline(false));
    }

    function setWsStatus(up) {
        _wsUp = up;
        State.set('ws_connected', up);
    }

    function isOnline()    { return _online; }
    function isWsUp()      { return _wsUp; }

    return { init, setWsStatus, isOnline, isWsUp };
})();

/* ──────────────────────────────────────────────────────────
   10. ROUTER  (hash-based SPA routing)
────────────────────────────────────────────────────────── */
const Router = (() => {
    const routes    = [];
    let   _current  = null;
    let   _history  = [];

    function define(pattern, handler, opts = {}) {
        const regex  = new RegExp('^' + pattern.replace(/:[^/]+/g, '([^/]+)') + '(?:\\/)?$');
        const params = [...pattern.matchAll(/:([^/]+)/g)].map(m => m[1]);
        routes.push({ pattern, regex, params, handler, ...opts });
    }

    function navigate(path, replace = false) {
        if (replace) history.replaceState(null, '', '#' + path);
        else         history.pushState(null,    '', '#' + path);
        _dispatch(path);
    }

    function back() {
        if (_history.length > 1) {
            _history.pop();
            const prev = _history[_history.length - 1];
            navigate(prev, true);
        } else {
            navigate('/chats', true);
        }
    }

    function _dispatch(path) {
        path = path || '/chats';
        for (const route of routes) {
            const m = path.match(route.regex);
            if (m) {
                const args = {};
                route.params.forEach((p, i) => args[p] = decodeURIComponent(m[i+1]));
                const query = Object.fromEntries(new URLSearchParams(location.search));
                _current = { path, params: args, query, route };
                _history.push(path);
                if (_history.length > 50) _history.shift();
                State.set('route', _current);
                EventBus.emit('route:change', _current);
                try { route.handler(args, query); } catch(e) { console.error('[Router]', e); }
                return;
            }
        }
        // 404 fallback
        EventBus.emit('route:notfound', { path });
    }

    function init() {
        window.addEventListener('popstate', () => {
            const hash = location.hash.slice(1) || '/chats';
            _dispatch(hash);
        });
        // Initial dispatch
        const hash = location.hash.slice(1) || '/chats';
        _dispatch(hash);
    }

    function getCurrent() { return _current; }

    return { define, navigate, back, init, getCurrent };
})();

/* ──────────────────────────────────────────────────────────
   11. TOAST / SNACKBAR
────────────────────────────────────────────────────────── */
const Toast = (() => {
    let _container = null;
    let _queue     = [];
    let _active    = 0;
    const MAX      = 3;
    const DURATION = 3500;

    function _getContainer() {
        if (!_container) {
            _container = document.createElement('div');
            _container.className  = 'toast-container';
            _container.setAttribute('aria-live', 'polite');
            document.body.appendChild(_container);
        }
        return _container;
    }

    function show(message, type = 'info', opts = {}) {
        if (_active >= MAX) {
            _queue.push({ message, type, opts });
            return;
        }
        _active++;
        const c     = _getContainer();
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.setAttribute('role', 'status');

        const icons = {
            success: '✓', error: '✕', warning: '⚠', info: 'ℹ',
            loading: '⟳'
        };
        toast.innerHTML = `
            <span class="toast__icon">${icons[type] || icons.info}</span>
            <span class="toast__msg">${Utils.escapeHtml(message)}</span>
            ${opts.action ? `<button class="toast__action">${Utils.escapeHtml(opts.action.label)}</button>` : ''}
            <button class="toast__close" aria-label="Close">×</button>
        `;

        if (opts.action) {
            toast.querySelector('.toast__action').addEventListener('click', () => {
                opts.action.fn();
                _dismiss(toast);
            });
        }
        toast.querySelector('.toast__close').addEventListener('click', () => _dismiss(toast));

        c.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('toast--visible'));

        const duration = opts.duration ?? (type === 'loading' ? 0 : DURATION);
        if (duration > 0) {
            const t = setTimeout(() => _dismiss(toast), duration);
            toast._timeout = t;
        }

        return {
            dismiss: () => _dismiss(toast),
            update:  (msg, newType) => {
                clearTimeout(toast._timeout);
                toast.querySelector('.toast__msg').textContent = msg;
                toast.className = `toast toast--${newType || type} toast--visible`;
                if (duration > 0) toast._timeout = setTimeout(() => _dismiss(toast), duration);
            }
        };
    }

    function _dismiss(toast) {
        clearTimeout(toast._timeout);
        toast.classList.remove('toast--visible');
        toast.addEventListener('transitionend', () => {
            toast.remove();
            _active--;
            if (_queue.length) {
                const next = _queue.shift();
                show(next.message, next.type, next.opts);
            }
        }, { once: true });
    }

    return {
        show,
        success: (msg, opts)  => show(msg, 'success', opts),
        error:   (msg, opts)  => show(msg, 'error',   opts),
        warning: (msg, opts)  => show(msg, 'warning', opts),
        info:    (msg, opts)  => show(msg, 'info',    opts),
        loading: (msg, opts)  => show(msg, 'loading', opts),
    };
})();

/* ──────────────────────────────────────────────────────────
   12. MODAL MANAGER
────────────────────────────────────────────────────────── */
const Modal = (() => {
    const stack = [];

    function open(id, data = {}) {
        const el = document.getElementById(id);
        if (!el) return console.warn('[Modal] Not found:', id);

        // Fill dynamic data
        Object.entries(data).forEach(([k, v]) => {
            el.querySelectorAll(`[data-modal-field="${k}"]`).forEach(node => {
                if (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA') node.value = v;
                else node.textContent = v;
            });
        });

        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        stack.push(id);
        State.set('modal:open', id);
        EventBus.emit('modal:open', { id, data });

        // Focus first focusable element
        requestAnimationFrame(() => {
            const focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            focusable?.focus();
        });

        return {
            close: () => close(id),
            el
        };
    }

    function close(id) {
        const el = id ? document.getElementById(id) : null;
        const target = id || stack[stack.length - 1];
        const targetEl = el || document.getElementById(target);
        if (!targetEl) return;

        targetEl.hidden = true;
        targetEl.setAttribute('aria-hidden', 'true');
        const idx = stack.indexOf(target);
        if (idx > -1) stack.splice(idx, 1);
        if (!stack.length) document.body.classList.remove('modal-open');
        State.set('modal:open', stack[stack.length - 1] || null);
        EventBus.emit('modal:close', { id: target });

        // Reset form if any
        targetEl.querySelector('form')?.reset();
    }

    function closeAll() {
        [...stack].forEach(id => close(id));
    }

    function isOpen(id) { return stack.includes(id); }

    // Overlay click closes top modal
    document.addEventListener('click', e => {
        if (e.target.matches('.modal-overlay') && stack.length) {
            close(stack[stack.length - 1]);
        }
    });

    // Escape key closes top modal
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && stack.length) {
            e.preventDefault();
            close(stack[stack.length - 1]);
        }
    });

    return { open, close, closeAll, isOpen };
})();

/* ──────────────────────────────────────────────────────────
   13. UTILS
────────────────────────────────────────────────────────── */
const Utils = {
    /* String */
    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    },
    stripHtml(str) {
        const div = document.createElement('div');
        div.innerHTML = str;
        return div.textContent || '';
    },
    truncate(str, len, suffix = '…') {
        if (!str || str.length <= len) return str || '';
        return str.slice(0, len) + suffix;
    },
    slug(str) {
        return str.toLowerCase().trim()
            .replace(/[^\w\s-]/g,'').replace(/[\s_-]+/g,'-').replace(/^-+|-+$/g,'');
    },
    randomId(len = 8) {
        return Math.random().toString(36).slice(2, 2 + len);
    },
    uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    },

    /* Number */
    clamp(val, min, max) { return Math.min(max, Math.max(min, val)); },
    lerp(a, b, t)         { return a + (b - a) * t; },
    formatCount(n) {
        if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
        if (n >= 1000)    return (n/1000).toFixed(1) + 'K';
        return String(n);
    },

    /* Date */
    isToday(date) {
        const d = new Date(date);
        const n = new Date();
        return d.getDate() === n.getDate() && d.getMonth() === n.getMonth() && d.getFullYear() === n.getFullYear();
    },
    isYesterday(date) {
        const d  = new Date(date);
        const y  = new Date();
        y.setDate(y.getDate() - 1);
        return d.getDate() === y.getDate() && d.getMonth() === y.getMonth() && d.getFullYear() === y.getFullYear();
    },
    isThisWeek(date) {
        return Date.now() - new Date(date).getTime() < 7 * 86400000;
    },

    /* DOM */
    qs(sel, ctx = document)  { return ctx.querySelector(sel); },
    qsa(sel, ctx = document) { return [...ctx.querySelectorAll(sel)]; },
    el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'class')     node.className = v;
            else if (k === 'html') node.innerHTML = v;
            else if (k === 'text') node.textContent = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else                   node.setAttribute(k, v);
        });
        children.flat().forEach(c => {
            if (typeof c === 'string') node.appendChild(document.createTextNode(c));
            else if (c)                node.appendChild(c);
        });
        return node;
    },
    delegate(root, selector, event, handler) {
        root.addEventListener(event, e => {
            const target = e.target.closest(selector);
            if (target && root.contains(target)) handler.call(target, e, target);
        });
    },
    scrollIntoViewIfNeeded(el, container) {
        const eRect = el.getBoundingClientRect();
        const cRect = container.getBoundingClientRect();
        if (eRect.top < cRect.top)         container.scrollTop -= cRect.top - eRect.top;
        else if (eRect.bottom > cRect.bottom) container.scrollTop += eRect.bottom - cRect.bottom;
    },

    /* Copy to clipboard */
    async copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            Toast.success(I18n.t('copied'));
            return true;
        } catch {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            Toast.success(I18n.t('copied'));
            return true;
        }
    },

    /* Debounce / Throttle */
    debounce(fn, ms) {
        let t;
        const db = function(...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
        db.cancel = () => clearTimeout(t);
        return db;
    },
    throttle(fn, ms) {
        let last = 0;
        return function(...args) {
            const now = Date.now();
            if (now - last >= ms) { last = now; fn.apply(this, args); }
        };
    },

    /* File */
    fileExt(name)    { return name.slice(name.lastIndexOf('.')+1).toLowerCase(); },
    fileIcon(name) {
        const ext = this.fileExt(name);
        const map = {
            pdf:'📄', doc:'📝', docx:'📝', xls:'📊', xlsx:'📊',
            ppt:'📋', pptx:'📋', zip:'🗜', rar:'🗜', mp3:'🎵',
            ogg:'🎵', wav:'🎵', mp4:'🎬', avi:'🎬', mov:'🎬',
            txt:'📄', csv:'📊', json:'📄', js:'📄', html:'📄',
        };
        return map[ext] || '📎';
    },
    isImage(file) {
        return SUPPORTED_IMG.includes(file.type);
    },
    isVideo(file) {
        return SUPPORTED_VIDEO.includes(file.type);
    },
    isAudio(file) {
        return SUPPORTED_AUDIO.includes(file.type);
    },
    async fileToDataUrl(file) {
        return new Promise((res, rej) => {
            const reader = new FileReader();
            reader.onload  = () => res(reader.result);
            reader.onerror = rej;
            reader.readAsDataURL(file);
        });
    },
    async resizeImage(file, maxW = 1280, maxH = 1280, quality = 0.85) {
        return new Promise((res) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                let w = img.width, h = img.height;
                if (w > maxW || h > maxH) {
                    const ratio = Math.min(maxW/w, maxH/h);
                    w *= ratio; h *= ratio;
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                URL.revokeObjectURL(url);
                canvas.toBlob(blob => res(blob), 'image/jpeg', quality);
            };
            img.src = url;
        });
    },

    /* Color */
    stringToColor(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        return Math.abs(hash) % 8;  // 0–7, mapped to CSS data-color
    },

    /* URL */
    parseLinks(text) {
        const urlReg = /https?:\/\/(www\.)?[-a-zA-Z0-9@:%._+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&/=]*)/gi;
        return text.replace(urlReg, url => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`);
    },
    isUrl(str) {
        try { new URL(str); return true; } catch { return false; }
    },
};

/* ──────────────────────────────────────────────────────────
   14. AVATAR HELPER
────────────────────────────────────────────────────────── */
const Avatar = {
    initials(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return name.slice(0, 2).toUpperCase();
    },
    render(name, src, size = 44, online = false) {
        const colorIdx = Utils.stringToColor(name || '');
        const wrap = Utils.el('div', { class: 'avatar-wrap', style: `width:${size}px;height:${size}px` });
        if (src) {
            const img = Utils.el('img', { class: 'avatar avatar--img', src, alt: name });
            img.onerror = () => img.replaceWith(this._fallback(name, colorIdx, size));
            wrap.appendChild(img);
        } else {
            wrap.appendChild(this._fallback(name, colorIdx, size));
        }
        if (online) {
            wrap.appendChild(Utils.el('span', { class: 'avatar__online' }));
        }
        return wrap;
    },
    _fallback(name, colorIdx, size) {
        const el = Utils.el('div', {
            class: 'avatar avatar--fallback',
            'data-color': colorIdx,
            style: `width:${size}px;height:${size}px;font-size:${Math.floor(size*0.38)}px`,
            text: this.initials(name)
        });
        return el;
    }
};

/* ──────────────────────────────────────────────────────────
   15. KEYBOARD SHORTCUT MANAGER
────────────────────────────────────────────────────────── */
const Shortcuts = (() => {
    const map = {};

    function _key(e) {
        const parts = [];
        if (e.ctrlKey || e.metaKey) parts.push('ctrl');
        if (e.altKey)               parts.push('alt');
        if (e.shiftKey)             parts.push('shift');
        parts.push(e.key.toLowerCase());
        return parts.join('+');
    }

    function register(combo, fn, description = '') {
        map[combo.toLowerCase()] = { fn, description };
    }

    function unregister(combo) {
        delete map[combo.toLowerCase()];
    }

    function init() {
        document.addEventListener('keydown', e => {
            // Skip when typing in input / textarea
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) {
                // Only allow global combos with ctrl/meta
                if (!e.ctrlKey && !e.metaKey) return;
            }
            const combo = _key(e);
            if (map[combo]) {
                e.preventDefault();
                map[combo].fn(e);
            }
        });
    }

    function list() { return { ...map }; }

    return { register, unregister, init, list };
})();

/* ──────────────────────────────────────────────────────────
   16. NOTIFICATION HELPER
────────────────────────────────────────────────────────── */
const Notif = (() => {
    let _permitted = false;

    async function requestPermission() {
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') { _permitted = true; return true; }
        if (Notification.permission === 'denied')  return false;
        const res = await Notification.requestPermission();
        _permitted = res === 'granted';
        return _permitted;
    }

    function show(title, opts = {}) {
        if (!_permitted) return null;
        if (document.visibilityState === 'visible' && !opts.force) return null;
        const n = new Notification(title, {
            icon:   opts.icon   || '/icon-192.png',
            badge:  opts.badge  || '/icon-96.png',
            body:   opts.body   || '',
            tag:    opts.tag    || 'namak-msg',
            silent: opts.silent || false,
            data:   opts.data   || {},
        });
        n.addEventListener('click', () => {
            window.focus();
            if (opts.onClick) opts.onClick();
            n.close();
        });
        return n;
    }

    function init() {
        if (Notification.permission === 'granted') _permitted = true;
    }

    return { requestPermission, show, init, get permitted() { return _permitted; } };
})();

/* ──────────────────────────────────────────────────────────
   17. SERVICE WORKER REGISTRATION
────────────────────────────────────────────────────────── */
const SW = (() => {
    let _reg = null;

    async function register() {
        if (!('serviceWorker' in navigator)) return;
        try {
            _reg = await navigator.serviceWorker.register(SW_PATH, { scope: '/' });
            _reg.addEventListener('updatefound', () => {
                const worker = _reg.installing;
                worker.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        EventBus.emit('sw:update_ready');
                        Toast.info(I18n.t('update_available'), {
                            action: {
                                label: I18n.t('refresh'),
                                fn: () => {
                                    worker.postMessage({ type: 'SKIP_WAITING' });
                                    location.reload();
                                }
                            },
                            duration: 0
                        });
                    }
                });
            });
            navigator.serviceWorker.addEventListener('message', e => {
                EventBus.emit('sw:message', e.data);
            });
            console.log('[SW] Registered:', _reg.scope);
        } catch(e) {
            console.warn('[SW] Registration failed:', e);
        }
    }

    function postMessage(data) {
        navigator.serviceWorker.controller?.postMessage(data);
    }

    return { register, postMessage, get reg() { return _reg; } };
})();

/* ──────────────────────────────────────────────────────────
   18. GLOBAL EVENT BINDINGS
────────────────────────────────────────────────────────── */
function bindGlobalEvents() {

    /* Auth */
    EventBus.on('auth:unauthorized', () => {
        Store.remove('token');
        Store.remove('user');
        State.set('user', null);
        Router.navigate('/login', true);
    });

    /* Connection status */
    EventBus.on('connection:offline', () => {
        document.body.dataset.connection = 'offline';
    });
    EventBus.on('connection:online', () => {
        document.body.dataset.connection = 'online';
        EventBus.emit('connection:reconnect');
    });

    /* Visibility change — pause/resume */
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) EventBus.emit('app:background');
        else                 EventBus.emit('app:foreground');
    });

    /* Global click — close floating panels */
    document.addEventListener('click', e => {
        if (!e.target.closest('.dropdown-menu, [data-dropdown]'))
            Utils.qsa('.dropdown-menu:not([hidden])').forEach(m => m.hidden = true);
        if (!e.target.closest('.emoji-panel, .chat-input-bar__emoji'))
            Utils.qs('.emoji-panel:not([hidden])')?.setAttribute('hidden','');
        if (!e.target.closest('.attach-menu, .chat-input-bar__attach'))
            Utils.qs('.attach-menu:not([hidden])')?.setAttribute('hidden','');
    });

    /* Keyboard shortcuts */
    Shortcuts.register('ctrl+k',     () => EventBus.emit('search:open'));
    Shortcuts.register('ctrl+/',     () => EventBus.emit('shortcuts:show'));
    Shortcuts.register('ctrl+shift+m', () => EventBus.emit('chat:newMessage'));
    Shortcuts.register('ctrl+shift+n', () => EventBus.emit('contact:new'));
    Shortcuts.register('escape',     () => EventBus.emit('ui:escape'));

    /* Drag & drop on chat */
    document.addEventListener('dragover', e => {
        if (State.get('route')?.path?.startsWith('/chat/')) {
            e.preventDefault();
            EventBus.emit('chat:dragover', e);
        }
    });
    document.addEventListener('drop', e => {
        if (State.get('route')?.path?.startsWith('/chat/')) {
            e.preventDefault();
            EventBus.emit('chat:drop', e);
        }
    });

    /* Online badge update */
    State.watch('unread_total', count => {
        const favicon = document.getElementById('favicon');
        if (!favicon) return;
        favicon.href = count > 0 ? '/favicon-dot.png' : '/favicon.ico';
        document.title = count > 0 ? `(${count}) ${APP_NAME}` : APP_NAME;
    });
}

/* ──────────────────────────────────────────────────────────
   19. APP INIT
────────────────────────────────────────────────────────── */
const App = (() => {
    let _initialized = false;

    async function init() {
        if (_initialized) return;
        _initialized = true;

        console.log(`%c${APP_NAME} v${APP_VERSION}`, 'color:#2196f3;font-weight:bold;font-size:14px');

        /* 1. Theme (synchronous — before first paint) */
        ThemeManager.init();

        /* 2. Connection monitor */
        Connection.init();

        /* 3. Locale */
        const locale = Store.get('locale', 'fa');
        await I18n.load(locale);

        /* 4. Check auth token */
        const token = Store.get('token');
        const user  = Store.get('user');
        if (token && user) {
            Http.setToken(token);
            State.set('user',  user);
            State.set('token', token);
            EventBus.emit('auth:restored', { user, token });
        }

        /* 5. Keyboard shortcuts */
        Shortcuts.init();

        /* 6. Global bindings */
        bindGlobalEvents();

        /* 7. Router */
        Router.define('/login',          () => EventBus.emit('page:login'));
        Router.define('/register',       () => EventBus.emit('page:register'));
        Router.define('/chats',          () => EventBus.emit('page:chats'));
        Router.define('/chat/:id',       p  => EventBus.emit('page:chat',     p));
        Router.define('/contacts',       () => EventBus.emit('page:contacts'));
        Router.define('/profile',        () => EventBus.emit('page:profile'));
        Router.define('/profile/:id',    p  => EventBus.emit('page:userProfile', p));
        Router.define('/settings',       () => EventBus.emit('page:settings'));
        Router.define('/settings/:tab',  p  => EventBus.emit('page:settings',  p));
        Router.define('/invite',         () => EventBus.emit('page:invite'));
        Router.init();

        /* 8. Push notification permission */
        Notif.init();

        /* 9. Service worker */
        SW.register();

        /* 10. Signal ready */
        document.body.classList.add('app--ready');
        EventBus.emit('app:ready');
        console.log('[App] Ready');
    }

    function getUser()  { return State.get('user'); }
    function getToken() { return State.get('token'); }

    function login(user, token) {
        Http.setToken(token);
        Store.set('token', token);
        Store.set('user',  user);
        State.set('user',  user);
        State.set('token', token);
        EventBus.emit('auth:login', { user, token });
        Router.navigate('/chats', true);
    }

    function logout(everywhere = false) {
        if (everywhere) Http.post('/auth/logout-all').catch(() => {});
        else            Http.post('/auth/logout').catch(() => {});
        Http.setToken(null);
        Store.remove('token');
        Store.remove('user');
        State.set('user',  null);
        State.set('token', null);
        EventBus.emit('auth:logout');
        Router.navigate('/login', true);
    }

    function updateUser(patch) {
        const user = { ...State.get('user'), ...patch };
        State.set('user', user);
        Store.set('user', user);
        EventBus.emit('user:updated', user);
    }

    return { init, login, logout, updateUser, getUser, getToken };
})();

/* ──────────────────────────────────────────────────────────
   20. BOOTSTRAP
────────────────────────────────────────────────────────── */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.init());
} else {
    App.init();
}

/* Expose to global scope for inline handlers */
window.App        = App;
window.EventBus   = EventBus;
window.State      = State;
window.Store      = Store;
window.Router     = Router;
window.Toast      = Toast;
window.Modal      = Modal;
window.I18n       = I18n;
window.Http       = Http;
window.Utils      = Utils;
window.Avatar     = Avatar;
window.ThemeManager = ThemeManager;
window.Shortcuts  = Shortcuts;
window.Notif      = Notif;

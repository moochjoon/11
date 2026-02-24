/* ============================================================
   APP.JS  —  Namak Messenger
   Bootstrap, router, auth, global state
   ============================================================ */

'use strict';

/* ──────────────────────────────────────────────────────────
   1. GLOBAL STATE
────────────────────────────────────────────────────────── */
export const App = {
    version:      '1.0.0',
    user:         null,        // current user object
    activeChat:   null,        // active chat object
    chats:        new Map(),   // chatId → chat
    contacts:     new Map(),   // userId → contact
    onlineUsers:  new Set(),   // userId
    typingTimers: new Map(),   // chatId:userId → timeout
    settings:     {},
    sw:           null,        // ServiceWorker registration
    vapidKey:     null,
};

/* ──────────────────────────────────────────────────────────
   2. CONSTANTS
────────────────────────────────────────────────────────── */
export const API     = '/api/v1';
export const WS_PATH = '/ws';

const THEME_KEY    = 'namak_theme';
const ACCENT_KEY   = 'namak_accent';
const SETTINGS_KEY = 'namak_settings';
const AUTH_KEY     = 'namak_auth';

/* ──────────────────────────────────────────────────────────
   3. STORAGE HELPERS
────────────────────────────────────────────────────────── */
export const storage = {
    get:    key       => { try { return JSON.parse(localStorage.getItem(key)); } catch { return null; } },
    set:    (key, v)  => { try { localStorage.setItem(key, JSON.stringify(v)); } catch {} },
    remove: key       => localStorage.removeItem(key),
    clear:  ()        => localStorage.clear(),
};

/* ──────────────────────────────────────────────────────────
   4. HTTP CLIENT
────────────────────────────────────────────────────────── */
export async function api(method, path, body, opts = {}) {
    const auth  = storage.get(AUTH_KEY);
    const token = auth?.access_token;

    const headers = {
        'Content-Type': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        ...opts.headers,
    };

    const config = {
        method,
        headers,
        ...(body && method !== 'GET' ? { body: JSON.stringify(body) } : {}),
    };

    try {
        const res  = await fetch(`${API}${path}`, config);
        const data = await res.json().catch(() => ({}));

        // Auto-refresh token on 401
        if (res.status === 401 && data.error === 'Token expired' && !opts._retry) {
            const refreshed = await _refreshToken();
            if (refreshed) return api(method, path, body, { ...opts, _retry: true });
            _logout();
            return null;
        }

        if (!res.ok) throw Object.assign(new Error(data.error || 'Request failed'), { status: res.status, data });
        return data;
    } catch (err) {
        if (err.status) throw err;
        throw Object.assign(new Error('Network error'), { status: 0 });
    }
}

async function _refreshToken() {
    const auth = storage.get(AUTH_KEY);
    if (!auth?.refresh_token) return false;
    try {
        const res = await fetch(`${API}/auth/refresh`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ refresh_token: auth.refresh_token }),
        });
        if (!res.ok) return false;
        const data = await res.json();
        storage.set(AUTH_KEY, { ...auth, ...data });
        return true;
    } catch { return false; }
}

/* ──────────────────────────────────────────────────────────
   5. AUTH
────────────────────────────────────────────────────────── */
export function isLoggedIn() {
    const auth = storage.get(AUTH_KEY);
    return !!(auth?.access_token);
}

export function getToken() {
    return storage.get(AUTH_KEY)?.access_token || null;
}

export function saveAuth(data) {
    storage.set(AUTH_KEY, data);
    App.user = data.user;
}

function _logout() {
    storage.remove(AUTH_KEY);
    App.user = null;
    location.href = '/';
}

export async function logout() {
    const auth = storage.get(AUTH_KEY);
    try {
        await api('POST', '/auth/logout', { refresh_token: auth?.refresh_token });
    } catch {}
    storage.remove(AUTH_KEY);
    location.reload();
}

/* ──────────────────────────────────────────────────────────
   6. SETTINGS
────────────────────────────────────────────────────────── */
const DEFAULT_SETTINGS = {
    theme:          'system',    // light | dark | system
    accent:         'blue',
    fontSize:       14,
    bubbleStyle:    'default',   // default | square | minimal
    chatBg:         'default',
    enterToSend:    true,
    soundEnabled:   true,
    notifyEnabled:  true,
    notifyPreview:  true,
    autoDownload:   true,
    language:       'fa',
    compactMode:    false,
    animationsEnabled: true,
    sendReadReceipts:  true,
    showOnlineStatus:  true,
};

export function loadSettings() {
    const saved = storage.get(SETTINGS_KEY) || {};
    App.settings = { ...DEFAULT_SETTINGS, ...saved };
    _applySettings();
}

export function saveSetting(key, value) {
    App.settings[key] = value;
    storage.set(SETTINGS_KEY, App.settings);
    _applySettings();
}

function _applySettings() {
    const { theme, accent, fontSize, bubbleStyle, chatBg, compactMode, animationsEnabled } = App.settings;
    const root = document.documentElement;

    root.setAttribute('data-theme',   theme);
    root.setAttribute('data-accent',  accent);
    root.setAttribute('data-bubble',  bubbleStyle);
    root.setAttribute('data-chat-bg', chatBg);
    root.style.setProperty('--font-size-base', `${fontSize}px`);

    document.getElementById('app')?.classList.toggle('compact-mode', compactMode);
    document.body.classList.toggle('no-animations', !animationsEnabled);
}

/* ──────────────────────────────────────────────────────────
   7. ROUTER (hash-based SPA)
────────────────────────────────────────────────────────── */
const routes = new Map();

export function route(hash, handler) {
    routes.set(hash, handler);
}

export function navigate(hash) {
    location.hash = hash;
}

function _handleRoute() {
    const hash    = location.hash.slice(1) || '/';
    const parts   = hash.split('/');
    const base    = '/' + (parts[1] || '');

    const handler = routes.get(hash) || routes.get(base) || routes.get('*');
    if (handler) handler({ hash, parts, params: _parseParams() });
}

function _parseParams() {
    const search = location.hash.split('?')[1] || '';
    return Object.fromEntries(new URLSearchParams(search));
}

window.addEventListener('hashchange', _handleRoute);

/* ──────────────────────────────────────────────────────────
   8. NOTIFICATIONS
────────────────────────────────────────────────────────── */
export async function requestNotifications() {
    if (!('Notification' in window)) return false;
    if (Notification.permission === 'granted') return true;
    const perm = await Notification.requestPermission();
    return perm === 'granted';
}

export async function subscribePush() {
    if (!App.sw || !App.vapidKey) return;
    try {
        const sub = await App.sw.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: _urlB64ToUint8Array(App.vapidKey),
        });
        await api('POST', '/push/register', sub.toJSON());
        return sub;
    } catch (err) {
        console.warn('[Push] Subscribe failed:', err);
    }
}

function _urlB64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

/* ──────────────────────────────────────────────────────────
   9. SOUND
────────────────────────────────────────────────────────── */
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
const sounds   = {};

async function loadSound(name, url) {
    try {
        const res    = await fetch(url);
        const buffer = await res.arrayBuffer();
        sounds[name] = await audioCtx.decodeAudioData(buffer);
    } catch {}
}

export function playSound(name) {
    if (!App.settings.soundEnabled) return;
    const buffer = sounds[name];
    if (!buffer) return;
    const src = audioCtx.createBufferSource();
    src.buffer = buffer;
    src.connect(audioCtx.destination);
    src.start(0);
}

/* ──────────────────────────────────────────────────────────
   10. VISIBILITY / FOCUS
────────────────────────────────────────────────────────── */
export let isVisible = !document.hidden;
document.addEventListener('visibilitychange', () => {
    isVisible = !document.hidden;
    if (isVisible && App.activeChat) {
        import('./chat.js').then(m => m.markSeen(App.activeChat.id));
    }
    if (isVisible) navigator.clearAppBadge?.().catch(() => {});
});

/* ──────────────────────────────────────────────────────────
   11. RIPPLE EFFECT
────────────────────────────────────────────────────────── */
export function addRipple(el) {
    el.addEventListener('click', e => {
        if (!App.settings.animationsEnabled) return;
        const rect = el.getBoundingClientRect();
        const wave = document.createElement('span');
        wave.className = 'ripple-wave';
        const size = Math.max(rect.width, rect.height) * 2;
        wave.style.cssText = `
            width: ${size}px; height: ${size}px;
            top:  ${e.clientY - rect.top  - size / 2}px;
            left: ${e.clientX - rect.left - size / 2}px;
        `;
        el.appendChild(wave);
        wave.addEventListener('animationend', () => wave.remove());
    });
}

/* ──────────────────────────────────────────────────────────
   12. DATE / TIME HELPERS
────────────────────────────────────────────────────────── */
export function formatTime(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
}

export function formatDate(dateStr) {
    const d    = new Date(dateStr);
    const now  = new Date();
    const diff = (now - d) / 1000;

    if (diff < 60)      return 'همین الان';
    if (diff < 3600)    return `${Math.floor(diff / 60)} دقیقه پیش`;
    if (diff < 86400)   return formatTime(dateStr);

    const isToday     = d.toDateString() === now.toDateString();
    const isYesterday = new Date(now - 86400000).toDateString() === d.toDateString();

    if (isToday)     return formatTime(dateStr);
    if (isYesterday) return 'دیروز';

    return d.toLocaleDateString('fa-IR', { month: 'short', day: 'numeric' });
}

export function formatFullDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('fa-IR', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
}

export function formatDuration(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

export function formatFileSize(bytes) {
    if (bytes < 1024)        return `${bytes} B`;
    if (bytes < 1048576)     return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1073741824)  return `${(bytes / 1048576).toFixed(1)} MB`;
    return `${(bytes / 1073741824).toFixed(1)} GB`;
}

/* ──────────────────────────────────────────────────────────
   13. DOM HELPERS
────────────────────────────────────────────────────────── */
export const $ = (sel, ctx = document) => ctx.querySelector(sel);
export const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

export function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class')   node.className = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
        else node.setAttribute(k, v);
    }
    for (const child of children.flat()) {
        if (typeof child === 'string') node.appendChild(document.createTextNode(child));
        else if (child)                node.appendChild(child);
    }
    return node;
}

export function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export function parseTextWithLinks(text) {
    const escaped = escapeHtml(text);
    return escaped
        .replace(/(https?:\/\/[^\s<>"']+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/@(\w+)/g, '<span class="mention" data-username="$1">@$1</span>')
        .replace(/#(\w+)/g, '<span class="hashtag">#$1</span>')
        .replace(/\n/g, '<br>');
}

export function getInitials(name = '') {
    return name.trim().split(/\s+/).map(w => w[0] || '').join('').slice(0, 2).toUpperCase();
}

export function getUserColor(id = '') {
    let hash = 0;
    for (let i = 0; i < id.length; i++) hash = (hash * 31 + id.charCodeAt(i)) >>> 0;
    return hash % 12;
}

/* ──────────────────────────────────────────────────────────
   14. AVATAR BUILDER
────────────────────────────────────────────────────────── */
export function buildAvatar(user, size = 'md') {
    const colorIdx = user.color ?? getUserColor(user.id);
    const div      = el('div', {
        class:        `avatar avatar--${size}`,
        'data-color': colorIdx,
    });

    if (user.avatar_thumb || user.avatar) {
        const img = el('img', {
            src:   user.avatar_thumb || user.avatar,
            alt:   user.name,
            class: 'avatar--img',
        });
        img.onerror = () => {
            div.innerHTML = '';
            div.classList.add('avatar--fallback');
            div.textContent = getInitials(user.name);
        };
        div.classList.add('avatar--img');
        div.appendChild(img);
    } else {
        div.classList.add('avatar--fallback');
        div.textContent = getInitials(user.name);
    }
    return div;
}

/* ──────────────────────────────────────────────────────────
   15. CLIPBOARD
────────────────────────────────────────────────────────── */
export async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const ta = el('textarea', { style: 'position:fixed;opacity:0' });
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        return true;
    }
}

/* ──────────────────────────────────────────────────────────
   16. KEYBOARD SHORTCUTS
────────────────────────────────────────────────────────── */
const shortcuts = new Map();

export function registerShortcut(combo, handler) {
    shortcuts.set(combo, handler);
}

document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.isContentEditable) return;
    const combo = [
        e.ctrlKey  ? 'ctrl'  : '',
        e.metaKey  ? 'meta'  : '',
        e.shiftKey ? 'shift' : '',
        e.altKey   ? 'alt'   : '',
        e.key.toLowerCase(),
    ].filter(Boolean).join('+');

    const handler = shortcuts.get(combo);
    if (handler) { e.preventDefault(); handler(e); }
});

/* ──────────────────────────────────────────────────────────
   17. EVENT BUS
────────────────────────────────────────────────────────── */
const eventBus = new EventTarget();
export const on  = (name, fn)      => eventBus.addEventListener(name, fn);
export const off = (name, fn)      => eventBus.removeEventListener(name, fn);
export const emit = (name, detail) => eventBus.dispatchEvent(new CustomEvent(name, { detail }));

/* ──────────────────────────────────────────────────────────
   18. BOOTSTRAP
────────────────────────────────────────────────────────── */
async function init() {
    loadSettings();

    /* Register SW */
    if ('serviceWorker' in navigator) {
        try {
            App.sw = await navigator.serviceWorker.register('/sw.js');
            navigator.serviceWorker.addEventListener('message', e => {
                const { type } = e.data || {};
                if (type === 'SYNC_COMPLETE') emit('sync:complete', e.data);
                if (type === 'PERIODIC_REFRESH') emit('refresh', {});
                if (type === 'NAVIGATE') navigate(e.data.path);
            });
        } catch (err) {
            console.warn('[SW] Registration failed:', err);
        }
    }

    /* Load sounds */
    await Promise.all([
        loadSound('message', '/assets/sounds/message.mp3'),
        loadSound('sent',    '/assets/sounds/sent.mp3'),
        loadSound('call',    '/assets/sounds/call.mp3'),
    ]);

    /* Auth gate */
    if (!isLoggedIn()) {
        const { renderAuth } = await import('./ui.js');
        renderAuth();
        return;
    }

    /* Load user profile */
    try {
        const me = await api('GET', '/users/me');
        App.user = me;
        storage.set(AUTH_KEY, { ...storage.get(AUTH_KEY), user: me });
    } catch {
        _logout();
        return;
    }

    /* Boot main UI */
    const { renderApp }      = await import('./ui.js');
    const { initSocket }     = await import('./socket.js');
    const { initChatList }   = await import('./chat.js');
    const { initContacts }   = await import('./contacts.js');

    renderApp();
    initSocket();
    await initChatList();

    /* Keyboard shortcuts */
    registerShortcut('ctrl+k', () => emit('search:open', {}));
    registerShortcut('escape', () => emit('modal:close', {}));

    /* Handle share target */
    const url = new URL(location.href);
    if (url.searchParams.has('share')) {
        const { handleShareTarget } = await import('./ui.js');
        handleShareTarget();
    }

    /* PWA install prompt */
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        App.installPrompt = e;
        emit('pwa:installable', {});
    });

    /* Handle hash route */
    _handleRoute();
}

document.addEventListener('DOMContentLoaded', init);

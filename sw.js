/* ============================================================
   SW.JS  —  Namak Messenger  |  Service Worker
   Cache strategies, offline support, push notifications,
   background sync, periodic sync, share target
   ============================================================ */

'use strict';

/* ──────────────────────────────────────────────────────────
   1. CONFIG
────────────────────────────────────────────────────────── */
const APP_VERSION    = '1.0.0';
const CACHE_STATIC   = `namak-static-v${APP_VERSION}`;
const CACHE_DYNAMIC  = `namak-dynamic-v${APP_VERSION}`;
const CACHE_MEDIA    = `namak-media-v${APP_VERSION}`;
const CACHE_API      = `namak-api-v${APP_VERSION}`;

const MAX_DYNAMIC    = 120;   // max dynamic cache entries
const MAX_MEDIA      = 80;    // max media cache entries
const MAX_API        = 40;    // max api cache entries

const API_BASE       = '/api/v1';
const PUSH_ENDPOINT  = '/api/v1/push/register';

/* Static shell — always cache on install */
const STATIC_ASSETS = [
    '/',
    '/index.html',
    '/offline.html',
    '/assets/css/variables.css',
    '/assets/css/base.css',
    '/assets/css/layout.css',
    '/assets/css/components.css',
    '/assets/css/animations.css',
    '/assets/js/app.js',
    '/assets/js/socket.js',
    '/assets/js/chat.js',
    '/assets/js/ui.js',
    '/assets/js/profile.js',
    '/assets/js/settings.js',
    '/assets/js/contacts.js',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/assets/sounds/message.mp3',
    '/assets/sounds/sent.mp3',
    '/assets/sounds/call.mp3',
];

/* API routes to cache with network-first */
const API_CACHEABLE = [
    /\/api\/v1\/chats(\?.*)?$/,
    /\/api\/v1\/contacts(\?.*)?$/,
    /\/api\/v1\/users\/me$/,
];

/* Media extensions to cache */
const MEDIA_EXTS = /\.(jpg|jpeg|png|gif|webp|mp4|mp3|ogg|webm|svg)(\?.*)?$/i;

/* ──────────────────────────────────────────────────────────
   2. INSTALL — Cache static shell
────────────────────────────────────────────────────────── */
self.addEventListener('install', event => {
    self.skipWaiting();

    event.waitUntil(
        caches.open(CACHE_STATIC).then(cache => {
            return cache.addAll(
                STATIC_ASSETS.map(url => new Request(url, { cache: 'reload' }))
            );
        }).catch(err => {
            console.warn('[SW] Install cache failed:', err);
        })
    );
});

/* ──────────────────────────────────────────────────────────
   3. ACTIVATE — Clean old caches
────────────────────────────────────────────────────────── */
self.addEventListener('activate', event => {
    event.waitUntil(
        Promise.all([
            // Remove old cache versions
            caches.keys().then(keys =>
                Promise.all(
                    keys
                        .filter(k =>
                            (k.startsWith('namak-static-') && k !== CACHE_STATIC)   ||
                            (k.startsWith('namak-dynamic-')&& k !== CACHE_DYNAMIC)  ||
                            (k.startsWith('namak-media-')  && k !== CACHE_MEDIA)    ||
                            (k.startsWith('namak-api-')    && k !== CACHE_API)
                        )
                        .map(k => caches.delete(k))
                )
            ),
            // Take control of all clients immediately
            self.clients.claim(),
        ])
    );
});

/* ──────────────────────────────────────────────────────────
   4. FETCH — Strategy router
────────────────────────────────────────────────────────── */
self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // Skip non-GET except POST for background sync
    if (req.method !== 'GET') {
        event.respondWith(_handleMutation(req));
        return;
    }

    // Skip browser extensions / chrome-extension
    if (!url.protocol.startsWith('http')) return;

    // Skip WebSocket upgrades
    if (req.headers.get('upgrade') === 'websocket') return;

    /* Route strategies */

    // 1. Static shell → cache first
    if (_isStaticAsset(url)) {
        event.respondWith(_cacheFirst(req, CACHE_STATIC));
        return;
    }

    // 2. Media files → cache first (with size limit)
    if (MEDIA_EXTS.test(url.pathname)) {
        event.respondWith(_cacheFirstWithLimit(req, CACHE_MEDIA, MAX_MEDIA));
        return;
    }

    // 3. API cacheable endpoints → network first
    if (url.pathname.startsWith(API_BASE) && _isApiCacheable(url)) {
        event.respondWith(_networkFirst(req, CACHE_API, MAX_API));
        return;
    }

    // 4. Other API → network only (with offline fallback)
    if (url.pathname.startsWith(API_BASE)) {
        event.respondWith(_networkOnly(req));
        return;
    }

    // 5. Navigation (HTML pages) → network first → cache → offline page
    if (req.mode === 'navigate') {
        event.respondWith(_navigationStrategy(req));
        return;
    }

    // 6. Everything else → stale while revalidate
    event.respondWith(_staleWhileRevalidate(req, CACHE_DYNAMIC, MAX_DYNAMIC));
});

/* ──────────────────────────────────────────────────────────
   5. CACHE STRATEGIES
────────────────────────────────────────────────────────── */

/** Cache First — for static assets */
async function _cacheFirst(req, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(req);
    if (cached) return cached;

    try {
        const fresh = await fetch(req);
        if (fresh.ok) cache.put(req, fresh.clone());
        return fresh;
    } catch {
        return new Response('Offline', { status: 503 });
    }
}

/** Cache First with entry limit */
async function _cacheFirstWithLimit(req, cacheName, limit) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(req);
    if (cached) return cached;

    try {
        const fresh = await fetch(req);
        if (fresh.ok) {
            await _limitCacheSize(cache, limit);
            cache.put(req, fresh.clone());
        }
        return fresh;
    } catch {
        return new Response('Offline', { status: 503 });
    }
}

/** Network First — for API */
async function _networkFirst(req, cacheName, limit) {
    const cache = await caches.open(cacheName);
    try {
        const fresh = await fetch(req.clone());
        if (fresh.ok) {
            await _limitCacheSize(cache, limit);
            cache.put(req, fresh.clone());
        }
        return fresh;
    } catch {
        const cached = await cache.match(req);
        return cached || _offlineApiResponse(req);
    }
}

/** Network Only */
async function _networkOnly(req) {
    try {
        return await fetch(req);
    } catch {
        return _offlineApiResponse(req);
    }
}

/** Stale While Revalidate */
async function _staleWhileRevalidate(req, cacheName, limit) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(req);

    const fetchPromise = fetch(req.clone())
        .then(async fresh => {
            if (fresh.ok) {
                await _limitCacheSize(cache, limit);
                cache.put(req, fresh.clone());
            }
            return fresh;
        })
        .catch(() => cached);

    return cached || fetchPromise;
}

/** Navigation strategy */
async function _navigationStrategy(req) {
    try {
        const fresh = await fetch(req);
        // Cache the shell for offline
        const cache = await caches.open(CACHE_STATIC);
        cache.put(new Request('/'), fresh.clone());
        return fresh;
    } catch {
        const cache  = await caches.open(CACHE_STATIC);
        const cached = await cache.match('/') || await cache.match('/index.html');
        if (cached) return cached;
        return cache.match('/offline.html') ||
            new Response('<h1>You are offline</h1>', {
                headers: { 'Content-Type': 'text/html' },
            });
    }
}

/* ──────────────────────────────────────────────────────────
   6. MUTATION HANDLER (POST / PATCH / DELETE)
────────────────────────────────────────────────────────── */
async function _handleMutation(req) {
    try {
        return await fetch(req);
    } catch {
        // Queue for background sync if POST/PATCH
        if (['POST', 'PATCH', 'DELETE'].includes(req.method)) {
            await _queueRequest(req);
            return new Response(JSON.stringify({ queued: true }), {
                status:  202,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response(null, { status: 503 });
    }
}

/* ──────────────────────────────────────────────────────────
   7. BACKGROUND SYNC — Queued mutations
────────────────────────────────────────────────────────── */
const SYNC_QUEUE_KEY = 'namak-sync-queue';

async function _queueRequest(req) {
    const entry = {
        url:     req.url,
        method:  req.method,
        headers: [...req.headers.entries()],
        body:    req.method !== 'DELETE' ? await req.text() : null,
        ts:      Date.now(),
    };

    const queue = await _readQueue();
    queue.push(entry);
    await _writeQueue(queue);

    // Register sync tag
    self.registration.sync?.register('namak-msg-sync').catch(() => {});
}

async function _readQueue() {
    const cache  = await caches.open('namak-sync-store');
    const res    = await cache.match(SYNC_QUEUE_KEY);
    if (!res) return [];
    try { return await res.json(); } catch { return []; }
}

async function _writeQueue(queue) {
    const cache = await caches.open('namak-sync-store');
    await cache.put(
        new Request(SYNC_QUEUE_KEY),
        new Response(JSON.stringify(queue), {
            headers: { 'Content-Type': 'application/json' },
        })
    );
}

self.addEventListener('sync', event => {
    if (event.tag === 'namak-msg-sync') {
        event.waitUntil(_replayQueue());
    }
});

async function _replayQueue() {
    const queue   = await _readQueue();
    const failed  = [];

    for (const entry of queue) {
        try {
            const res = await fetch(entry.url, {
                method:  entry.method,
                headers: Object.fromEntries(entry.headers),
                body:    entry.body || undefined,
            });
            if (!res.ok) failed.push(entry);
        } catch {
            failed.push(entry);
        }
    }

    await _writeQueue(failed);

    // Notify clients
    const clients = await self.clients.matchAll();
    clients.forEach(c => c.postMessage({ type: 'SYNC_COMPLETE', failed: failed.length }));
}

/* ──────────────────────────────────────────────────────────
   8. PERIODIC SYNC — Background data refresh
────────────────────────────────────────────────────────── */
self.addEventListener('periodicsync', event => {
    if (event.tag === 'namak-refresh') {
        event.waitUntil(_periodicRefresh());
    }
});

async function _periodicRefresh() {
    try {
        // Refresh chats list in background
        const [chatsRes, contactsRes] = await Promise.all([
            fetch(`${API_BASE}/chats?limit=30`),
            fetch(`${API_BASE}/contacts?limit=50`),
        ]);

        const cache = await caches.open(CACHE_API);
        if (chatsRes.ok)    cache.put(new Request(`${API_BASE}/chats?limit=30`),    chatsRes);
        if (contactsRes.ok) cache.put(new Request(`${API_BASE}/contacts?limit=50`), contactsRes);

        _notifyClients({ type: 'PERIODIC_REFRESH' });
    } catch { /* silent */ }
}

/* ──────────────────────────────────────────────────────────
   9. PUSH NOTIFICATIONS
────────────────────────────────────────────────────────── */
self.addEventListener('push', event => {
    if (!event.data) return;

    let payload;
    try { payload = event.data.json(); }
    catch { payload = { title: 'Namak', body: event.data.text() }; }

    event.waitUntil(_showNotification(payload));
});

async function _showNotification(payload) {
    const {
        title      = 'Namak',
        body       = '',
        icon       = '/assets/icons/icon-192.png',
        badge      = '/assets/icons/badge-72.png',
        tag        = 'namak-msg',
        data       = {},
        image,
        actions    = [],
        silent     = false,
        vibrate    = [200, 100, 200],
        renotify   = true,
    } = payload;

    const opts = {
        body,
        icon,
        badge,
        tag,
        data,
        renotify,
        silent,
        vibrate,
        timestamp: data.ts || Date.now(),
        requireInteraction: data.require_interaction || false,
        actions: _buildNotifActions(data.type, actions),
    };

    if (image) opts.image = image;

    // Group by chat_id
    if (data.chat_id) opts.tag = `namak-chat-${data.chat_id}`;

    // Count unread for badge
    if ('setAppBadge' in navigator) {
        const unreadCount = data.unread_total || 0;
        navigator.setAppBadge(unreadCount).catch(() => {});
    }

    await self.registration.showNotification(title, opts);
}

function _buildNotifActions(type, extra = []) {
    const base = [
        { action: 'reply',   title: 'پاسخ' },
        { action: 'dismiss', title: 'رد'   },
    ];
    if (type === 'call') {
        return [
            { action: 'answer', title: '📞 پاسخ' },
            { action: 'reject', title: '❌ رد'    },
        ];
    }
    return [...extra, ...base].slice(0, 2);
}

/* ──────────────────────────────────────────────────────────
   10. NOTIFICATION CLICK
────────────────────────────────────────────────────────── */
self.addEventListener('notificationclick', event => {
    const notif  = event.notification;
    const action = event.action;
    const data   = notif.data || {};

    notif.close();

    if (action === 'dismiss') return;

    if (action === 'reply') {
        // Inline reply (requires notification reply input)
        const reply = event.reply || '';
        if (reply && data.chat_id) {
            event.waitUntil(
                fetch(`${API_BASE}/messages`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        chat_id: data.chat_id,
                        text:    reply,
                        type:    'text',
                    }),
                }).catch(() => {})
            );
            return;
        }
    }

    if (action === 'answer' && data.call_id) {
        event.waitUntil(_focusOrOpen(`/call/${data.call_id}?answer=1`));
        return;
    }

    if (action === 'reject' && data.call_id) {
        event.waitUntil(
            fetch(`${API_BASE}/calls/${data.call_id}/reject`, { method: 'POST' })
        );
        return;
    }

    // Default: open chat
    const target = data.chat_id ? `/chat/${data.chat_id}` : '/';
    event.waitUntil(_focusOrOpen(target));
});

async function _focusOrOpen(path) {
    const url     = self.location.origin + path;
    const clients = await self.clients.matchAll({
        type:            'window',
        includeUncontrolled: true,
    });

    // Focus existing tab if open
    for (const client of clients) {
        if (client.url === url && 'focus' in client) {
            await client.focus();
            client.postMessage({ type: 'NAVIGATE', path });
            return;
        }
    }

    // Focus any app window and navigate
    for (const client of clients) {
        if ('focus' in client) {
            await client.focus();
            client.postMessage({ type: 'NAVIGATE', path });
            return;
        }
    }

    // Open new window
    if (self.clients.openWindow) {
        await self.clients.openWindow(url);
    }
}

/* ──────────────────────────────────────────────────────────
   11. NOTIFICATION CLOSE
────────────────────────────────────────────────────────── */
self.addEventListener('notificationclose', event => {
    const data = event.notification.data || {};
    if (data.chat_id) {
        fetch(`${API_BASE}/messages/seen`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: data.chat_id }),
        }).catch(() => {});
    }
});

/* ──────────────────────────────────────────────────────────
   12. SHARE TARGET
────────────────────────────────────────────────────────── */
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    if (
        event.request.method === 'POST' &&
        url.pathname === '/share-target'
    ) {
        event.respondWith(_handleShareTarget(event.request));
    }
});

async function _handleShareTarget(req) {
    const form    = await req.formData();
    const title   = form.get('title')   || '';
    const text    = form.get('text')    || '';
    const sharedUrl= form.get('url')    || '';
    const files   = form.getAll('files');

    // Store share data temporarily
    const cache = await caches.open('namak-share-store');
    await cache.put(
        new Request('/share-data'),
        new Response(JSON.stringify({ title, text, url: sharedUrl, files: files.length }), {
            headers: { 'Content-Type': 'application/json' },
        })
    );

    // If files: store blobs
    if (files.length) {
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            await cache.put(
                new Request(`/share-file-${i}`),
                new Response(file)
            );
        }
    }

    return Response.redirect('/?share=1', 303);
}

/* ──────────────────────────────────────────────────────────
   13. MESSAGE — postMessage from app
────────────────────────────────────────────────────────── */
self.addEventListener('message', event => {
    const { type, data } = event.data || {};

    switch(type) {
        case 'SKIP_WAITING':
            self.skipWaiting();
            break;

        case 'CACHE_URLS':
            if (Array.isArray(data?.urls)) {
                caches.open(CACHE_DYNAMIC).then(cache => {
                    cache.addAll(data.urls).catch(() => {});
                });
            }
            break;

        case 'CLEAR_CACHE':
            const name = data?.name;
            if (name) {
                caches.delete(name).then(() =>
                    event.source?.postMessage({ type: 'CACHE_CLEARED', name })
                );
            }
            break;

        case 'GET_CACHE_SIZE':
            _getCacheSize().then(size =>
                event.source?.postMessage({ type: 'CACHE_SIZE', size })
            );
            break;

        case 'PUSH_SUBSCRIBE':
            _subscribePush(data?.vapidKey, event.source);
            break;

        case 'SET_BADGE':
            navigator.setAppBadge?.(data?.count || 0).catch(() => {});
            break;

        case 'CLEAR_BADGE':
            navigator.clearAppBadge?.().catch(() => {});
            break;
    }
});

/* ──────────────────────────────────────────────────────────
   14. PUSH SUBSCRIPTION
────────────────────────────────────────────────────────── */
async function _subscribePush(vapidKey, client) {
    try {
        const sub = await self.registration.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: _urlBase64ToUint8Array(vapidKey),
        });

        // Send to server
        await fetch(PUSH_ENDPOINT, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(sub),
        });

        client?.postMessage({ type: 'PUSH_SUBSCRIBED', sub });
    } catch(err) {
        client?.postMessage({ type: 'PUSH_ERROR', err: err.message });
    }
}

function _urlBase64ToUint8Array(base64String) {
    const padding  = '='.repeat((4 - base64String.length % 4) % 4);
    const base64   = (base64String + padding)
        .replace(/-/g, '+').replace(/_/g, '/');
    const raw      = atob(base64);
    const output   = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
    return output;
}

/* ──────────────────────────────────────────────────────────
   15. HELPERS
────────────────────────────────────────────────────────── */
function _isStaticAsset(url) {
    return STATIC_ASSETS.includes(url.pathname) ||
        url.pathname.startsWith('/assets/icons/') ||
        url.pathname.startsWith('/assets/fonts/');
}

function _isApiCacheable(url) {
    return API_CACHEABLE.some(rx => rx.test(url.pathname + url.search));
}

function _offlineApiResponse(req) {
    return new Response(
        JSON.stringify({ error: 'offline', code: 503 }),
        {
            status:  503,
            headers: {
                'Content-Type':  'application/json',
                'X-SW-Offline':  '1',
            },
        }
    );
}

async function _limitCacheSize(cache, limit) {
    const keys = await cache.keys();
    if (keys.length >= limit) {
        await Promise.all(
            keys.slice(0, keys.length - limit + 1).map(k => cache.delete(k))
        );
    }
}

async function _getCacheSize() {
    let total = 0;
    const names = await caches.keys();
    for (const name of names) {
        const cache   = await caches.open(name);
        const keys    = await cache.keys();
        for (const key of keys) {
            const res  = await cache.match(key);
            const blob = await res?.blob?.().catch(() => null);
            if (blob) total += blob.size;
        }
    }
    return total;
}

function _notifyClients(msg) {
    self.clients.matchAll().then(clients =>
        clients.forEach(c => c.postMessage(msg))
    );
}

/* ──────────────────────────────────────────────────────────
   16. ERROR BOUNDARY
────────────────────────────────────────────────────────── */
self.addEventListener('error', event => {
    console.error('[SW] Uncaught error:', event.message, event.filename, event.lineno);
});

self.addEventListener('unhandledrejection', event => {
    console.error('[SW] Unhandled rejection:', event.reason);
    event.preventDefault();
});

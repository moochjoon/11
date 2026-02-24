/* ============================================================
   SOCKET.JS  —  Namak Messenger
   WebSocket manager: connect, reconnect, heartbeat,
   message queue, presence, typing, encryption handshake
   ============================================================ */

'use strict';

const Socket = (() => {

    /* ──────────────────────────────────────────────────────────
       1. CONFIG
    ────────────────────────────────────────────────────────── */
    const CFG = {
        RECONNECT_BASE:    1000,    // initial retry delay (ms)
        RECONNECT_MAX:     30000,   // max retry delay (ms)
        RECONNECT_FACTOR:  1.6,     // exponential backoff multiplier
        RECONNECT_JITTER:  0.3,     // random jitter factor
        PING_INTERVAL:     25000,   // heartbeat every 25 s
        PING_TIMEOUT:      8000,    // pong must arrive within 8 s
        QUEUE_MAX:         200,     // max offline queue size
        CONNECT_TIMEOUT:   12000,   // give up initial connect after 12 s
    };

    /* ──────────────────────────────────────────────────────────
       2. INTERNAL STATE
    ────────────────────────────────────────────────────────── */
    let _ws               = null;
    let _status           = 'idle';     // idle|connecting|connected|reconnecting|disconnected
    let _retryCount       = 0;
    let _retryTimer       = null;
    let _pingTimer        = null;
    let _pongTimer        = null;
    let _connectTimer     = null;
    let _queue            = [];         // outgoing messages buffered while offline
    let _handlers         = {};         // type → Set<handler>
    let _pendingAcks      = new Map();  // msgId → { resolve, reject, timer }
    let _lastPongAt       = 0;
    let _intentionalClose = false;
    let _token            = null;
    let _sessionId        = Utils.uuid();

    /* ──────────────────────────────────────────────────────────
       3. STATUS HELPERS
    ────────────────────────────────────────────────────────── */
    function _setStatus(s) {
        if (_status === s) return;
        const prev = _status;
        _status = s;
        Connection.setWsStatus(s === 'connected');
        State.set('ws_status', s);
        EventBus.emit('ws:status', { status: s, prev });

        // Update UI indicator
        const dot = Utils.qs('.ws-status-dot');
        if (dot) dot.dataset.status = s;
    }

    function isConnected() { return _status === 'connected'; }

    /* ──────────────────────────────────────────────────────────
       4. CONNECT
    ────────────────────────────────────────────────────────── */
    function connect(token) {
        if (token) _token = token;
        if (_ws && (_ws.readyState === WebSocket.OPEN ||
            _ws.readyState === WebSocket.CONNECTING)) return;

        _intentionalClose = false;
        _setStatus('connecting');

        const url = new URL(WS_URL);
        if (_token)    url.searchParams.set('token',     _token);
        if (_sessionId)url.searchParams.set('session',   _sessionId);
        url.searchParams.set('v', APP_VERSION);

        try { _ws = new WebSocket(url.toString()); }
        catch(e) { console.error('[WS] Failed to create socket:', e); _scheduleReconnect(); return; }

        _ws.binaryType = 'arraybuffer';

        // Connection timeout guard
        _connectTimer = setTimeout(() => {
            if (_status !== 'connected') {
                console.warn('[WS] Connect timeout');
                _ws.close(4001, 'connect_timeout');
            }
        }, CFG.CONNECT_TIMEOUT);

        _ws.addEventListener('open',    _onOpen);
        _ws.addEventListener('message', _onMessage);
        _ws.addEventListener('close',   _onClose);
        _ws.addEventListener('error',   _onError);
    }

    function disconnect(code = 1000, reason = 'client_disconnect') {
        _intentionalClose = true;
        _clearTimers();
        if (_ws) { _ws.close(code, reason); _ws = null; }
        _setStatus('disconnected');
    }

    /* ──────────────────────────────────────────────────────────
       5. SOCKET EVENT HANDLERS
    ────────────────────────────────────────────────────────── */
    function _onOpen() {
        clearTimeout(_connectTimer);
        _retryCount = 0;
        _setStatus('connected');
        EventBus.emit('ws:open');
        console.log('[WS] Connected');

        // Start heartbeat
        _startPing();

        // Flush offline queue
        _flushQueue();

        // Send presence
        send({ type: 'presence', status: 'online' });
    }

    function _onMessage(event) {
        let msg;
        try {
            msg = typeof event.data === 'string'
                ? JSON.parse(event.data)
                : _decodeBinary(event.data);
        } catch(e) {
            console.warn('[WS] Malformed message:', e);
            return;
        }

        if (!msg || !msg.type) return;

        // Handle pong
        if (msg.type === 'pong') { _onPong(); return; }

        // Handle ack
        if (msg.type === 'ack' && msg.id) { _resolveAck(msg.id, msg); return; }

        // Handle server errors for pending acks
        if (msg.type === 'error' && msg.ref_id) { _rejectAck(msg.ref_id, msg); }

        // Dispatch to registered handlers
        _dispatch(msg);
    }

    function _onClose(event) {
        clearTimeout(_connectTimer);
        _stopPing();
        _ws = null;

        const { code, reason } = event;
        console.log(`[WS] Closed: ${code} ${reason}`);
        EventBus.emit('ws:close', { code, reason });

        // Reject all pending acks
        _pendingAcks.forEach(({ reject, timer }, id) => {
            clearTimeout(timer);
            reject(new Error(`WS closed: ${code}`));
        });
        _pendingAcks.clear();

        if (_intentionalClose) { _setStatus('disconnected'); return; }

        // Auth errors — do not reconnect
        if (code === 4003 || code === 4004) {
            EventBus.emit('auth:unauthorized');
            _setStatus('disconnected');
            return;
        }

        _setStatus('reconnecting');
        _scheduleReconnect();
    }

    function _onError(event) {
        console.error('[WS] Error:', event);
        EventBus.emit('ws:error', event);
        // onclose fires after onerror automatically
    }

    /* ──────────────────────────────────────────────────────────
       6. RECONNECT  (exponential backoff + jitter)
    ────────────────────────────────────────────────────────── */
    function _scheduleReconnect() {
        clearTimeout(_retryTimer);
        if (!Connection.isOnline()) {
            // Wait for network to come back
            EventBus.once('connection:online', () => connect());
            return;
        }

        const base   = Math.min(CFG.RECONNECT_BASE * (CFG.RECONNECT_FACTOR ** _retryCount), CFG.RECONNECT_MAX);
        const jitter = base * CFG.RECONNECT_JITTER * (Math.random() * 2 - 1);
        const delay  = Math.round(base + jitter);

        console.log(`[WS] Reconnect in ${delay}ms (attempt #${_retryCount + 1})`);
        _retryTimer = setTimeout(() => {
            _retryCount++;
            connect();
        }, delay);

        EventBus.emit('ws:reconnecting', { attempt: _retryCount + 1, delay });
    }

    /* ──────────────────────────────────────────────────────────
       7. HEARTBEAT  (ping / pong)
    ────────────────────────────────────────────────────────── */
    function _startPing() {
        _stopPing();
        _pingTimer = setInterval(_sendPing, CFG.PING_INTERVAL);
    }

    function _stopPing() {
        clearInterval(_pingTimer);
        clearTimeout(_pongTimer);
        _pingTimer = null;
        _pongTimer = null;
    }

    function _sendPing() {
        if (!isConnected()) return;
        _rawSend({ type: 'ping', ts: Date.now() });
        _pongTimer = setTimeout(() => {
            console.warn('[WS] Pong timeout — reconnecting');
            _ws?.close(4002, 'pong_timeout');
        }, CFG.PING_TIMEOUT);
    }

    function _onPong() {
        clearTimeout(_pongTimer);
        _lastPongAt = Date.now();
    }

    /* ──────────────────────────────────────────────────────────
       8. SEND  (with queue + optional ack)
    ────────────────────────────────────────────────────────── */
    function send(msg, opts = {}) {
        if (!msg.id) msg.id = Utils.uuid();
        if (!msg.ts) msg.ts = Date.now();

        if (!isConnected()) {
            if (opts.queue !== false) {
                _enqueue(msg);
            }
            if (opts.ack) return Promise.reject(new Error('WS not connected'));
            return false;
        }

        _rawSend(msg);

        if (opts.ack) {
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    _pendingAcks.delete(msg.id);
                    reject(new Error(`ACK timeout for msg ${msg.id}`));
                }, opts.ackTimeout || 10000);
                _pendingAcks.set(msg.id, { resolve, reject, timer, msg });
            });
        }

        return true;
    }

    function _rawSend(msg) {
        try {
            _ws.send(JSON.stringify(msg));
        } catch(e) {
            console.error('[WS] Send failed:', e);
            _enqueue(msg);
        }
    }

    /* ──────────────────────────────────────────────────────────
       9. OFFLINE QUEUE
    ────────────────────────────────────────────────────────── */
    function _enqueue(msg) {
        // Don't queue transient messages
        const SKIP_TYPES = ['ping','pong','typing','presence','read_receipt'];
        if (SKIP_TYPES.includes(msg.type)) return;

        if (_queue.length >= CFG.QUEUE_MAX) _queue.shift();
        _queue.push({ msg, queuedAt: Date.now() });
        State.set('ws_queue_size', _queue.length);
    }

    function _flushQueue() {
        if (!_queue.length) return;
        const items = [..._queue];
        _queue = [];
        State.set('ws_queue_size', 0);

        const MAX_AGE = 60000; // discard messages older than 1 min
        items.forEach(({ msg, queuedAt }) => {
            if (Date.now() - queuedAt < MAX_AGE) _rawSend(msg);
        });
        console.log(`[WS] Flushed ${items.length} queued messages`);
    }

    /* ──────────────────────────────────────────────────────────
       10. ACK RESOLVER
    ────────────────────────────────────────────────────────── */
    function _resolveAck(id, data) {
        const pending = _pendingAcks.get(id);
        if (!pending) return;
        clearTimeout(pending.timer);
        _pendingAcks.delete(id);
        pending.resolve(data);
    }

    function _rejectAck(id, err) {
        const pending = _pendingAcks.get(id);
        if (!pending) return;
        clearTimeout(pending.timer);
        _pendingAcks.delete(id);
        pending.reject(new Error(err.message || 'Server error'));
    }

    /* ──────────────────────────────────────────────────────────
       11. MESSAGE DISPATCHER
    ────────────────────────────────────────────────────────── */
    function _dispatch(msg) {
        // Wildcard handlers
        if (_handlers['*']) {
            _handlers['*'].forEach(fn => { try { fn(msg); } catch(e) { console.error('[WS] Handler error:', e); } });
        }
        // Typed handlers
        if (_handlers[msg.type]) {
            _handlers[msg.type].forEach(fn => { try { fn(msg); } catch(e) { console.error('[WS] Handler error:', e); } });
        }
        // Also emit on EventBus for cross-module subscription
        EventBus.emit(`ws:${msg.type}`, msg);
    }

    function on(type, fn) {
        if (!_handlers[type]) _handlers[type] = new Set();
        _handlers[type].add(fn);
        return () => off(type, fn);
    }

    function off(type, fn) {
        _handlers[type]?.delete(fn);
    }

    /* ──────────────────────────────────────────────────────────
       12. BINARY DECODE
    ────────────────────────────────────────────────────────── */
    function _decodeBinary(buffer) {
        // Simple protocol: first 2 bytes = type code, rest = JSON payload
        const view    = new DataView(buffer);
        const typeCode = view.getUint16(0);
        const json     = new TextDecoder().decode(new Uint8Array(buffer, 2));
        const payload  = JSON.parse(json);
        payload._binary_type = typeCode;
        return payload;
    }

    /* ──────────────────────────────────────────────────────────
       13. TIMER CLEANUP
    ────────────────────────────────────────────────────────── */
    function _clearTimers() {
        clearTimeout(_retryTimer);
        clearTimeout(_connectTimer);
        _stopPing();
    }

    /* ──────────────────────────────────────────────────────────
       14. PRESENCE MODULE
    ────────────────────────────────────────────────────────── */
    const Presence = (() => {
        const _states   = new Map();   // userId → { status, lastSeen }
        const _watchers = new Map();   // userId → Set<fn>
        let   _myStatus = 'online';

        /* Incoming presence update from server */
        on('presence', msg => {
            const { user_id, status, last_seen } = msg;
            const prev = _states.get(user_id);
            _states.set(user_id, { status, lastSeen: last_seen });

            if (prev?.status !== status) {
                _watchers.get(user_id)?.forEach(fn => fn({ status, lastSeen: last_seen }));
                EventBus.emit('presence:update', { userId: user_id, status, lastSeen: last_seen });
                _updatePresenceUI(user_id, status);
            }
        });

        /* Batch presence sync (e.g. when chat list loads) */
        on('presence_batch', msg => {
            (msg.users || []).forEach(u => {
                _states.set(u.user_id, { status: u.status, lastSeen: u.last_seen });
                _updatePresenceUI(u.user_id, u.status);
            });
        });

        function _updatePresenceUI(userId, status) {
            Utils.qsa(`[data-user-id="${userId}"] .presence-dot,
                   [data-user-id="${userId}"] .avatar__online`).forEach(el => {
                el.dataset.status = status;
                el.hidden = status === 'offline';
            });
            Utils.qsa(`[data-user-id="${userId}"] .presence-label`).forEach(el => {
                el.textContent = status === 'online'
                    ? I18n.t('online')
                    : _states.get(userId)?.lastSeen
                        ? I18n.formatRelative(_states.get(userId).lastSeen)
                        : I18n.t('offline');
            });
        }

        function get(userId) {
            return _states.get(userId) || { status: 'offline', lastSeen: null };
        }

        function watch(userId, fn) {
            if (!_watchers.has(userId)) _watchers.set(userId, new Set());
            _watchers.get(userId).add(fn);
            return () => _watchers.get(userId)?.delete(fn);
        }

        function setMyStatus(status) {
            if (_myStatus === status) return;
            _myStatus = status;
            send({ type: 'presence', status });
        }

        /* Auto away on visibility/focus */
        document.addEventListener('visibilitychange', () => {
            setMyStatus(document.hidden ? 'away' : 'online');
        });
        window.addEventListener('blur',  () => setMyStatus('away'));
        window.addEventListener('focus', () => setMyStatus('online'));

        return { get, watch, setMyStatus, get myStatus() { return _myStatus; } };
    })();

    /* ──────────────────────────────────────────────────────────
       15. TYPING INDICATOR
    ────────────────────────────────────────────────────────── */
    const Typing = (() => {
        const _timers   = new Map();   // `${chatId}:${userId}` → timer
        const _typists  = new Map();   // chatId → Set<userId>
        let   _myTimer  = null;
        let   _iTyping  = false;
        let   _curChat  = null;

        const TYPING_TIMEOUT    = 5000;
        const TYPING_DEBOUNCE   = 1500;

        /* Incoming typing event */
        on('typing', msg => {
            const { chat_id, user_id, typing } = msg;
            const key = `${chat_id}:${user_id}`;

            if (!_typists.has(chat_id)) _typists.set(chat_id, new Set());

            if (typing) {
                _typists.get(chat_id).add(user_id);
                clearTimeout(_timers.get(key));
                _timers.set(key, setTimeout(() => _clearTypist(chat_id, user_id), TYPING_TIMEOUT));
            } else {
                _clearTypist(chat_id, user_id);
            }

            _renderTyping(chat_id);
            EventBus.emit('typing:update', { chatId: chat_id, typists: [..._typists.get(chat_id)] });
        });

        function _clearTypist(chatId, userId) {
            _typists.get(chatId)?.delete(userId);
            clearTimeout(_timers.get(`${chatId}:${userId}`));
            _timers.delete(`${chatId}:${userId}`);
            _renderTyping(chatId);
        }

        function _renderTyping(chatId) {
            const typists  = [...(_typists.get(chatId) || [])];
            const isActive = State.get('active_chat') === chatId;
            if (!isActive) return;

            const bar = Utils.qs('.typing-indicator');
            if (!bar) return;

            if (!typists.length) { bar.hidden = true; return; }

            bar.hidden = false;
            const names = typists
                .map(id => State.get(`contacts.${id}`)?.first_name || I18n.t('someone'))
                .slice(0, 3);

            let text = '';
            if (names.length === 1) text = I18n.t('typing.one',  { name: names[0] });
            else if (names.length === 2) text = I18n.t('typing.two',  { a: names[0], b: names[1] });
            else                    text = I18n.t('typing.many', { n: typists.length });

            const label = bar.querySelector('.typing-indicator__text');
            if (label) label.textContent = text;
        }

        /* Outgoing typing */
        const _sendTyping = Utils.debounce((chatId, typing) => {
            send({ type: 'typing', chat_id: chatId, typing });
        }, TYPING_DEBOUNCE);

        function notifyTyping(chatId) {
            _curChat = chatId;
            if (!_iTyping) {
                _iTyping = true;
                send({ type: 'typing', chat_id: chatId, typing: true });
            }
            clearTimeout(_myTimer);
            _myTimer = setTimeout(() => stopTyping(chatId), TYPING_TIMEOUT);
        }

        function stopTyping(chatId) {
            if (!_iTyping) return;
            _iTyping = false;
            clearTimeout(_myTimer);
            send({ type: 'typing', chat_id: chatId, typing: false });
        }

        function getTypists(chatId) {
            return [...(_typists.get(chatId) || [])];
        }

        return { notifyTyping, stopTyping, getTypists };
    })();

    /* ──────────────────────────────────────────────────────────
       16. READ RECEIPTS
    ────────────────────────────────────────────────────────── */
    const ReadReceipts = (() => {
        const _batch   = new Map();  // chatId → Set<msgId>
        let   _timer   = null;
        const DEBOUNCE = 800;

        on('read_receipt', msg => {
            const { chat_id, message_ids, user_id, read_at } = msg;
            EventBus.emit('receipt:read', { chatId: chat_id, messageIds: message_ids, userId: user_id, readAt: read_at });
            _updateReceiptUI(message_ids, user_id, 'read');
        });

        on('delivered', msg => {
            const { chat_id, message_ids, user_id } = msg;
            EventBus.emit('receipt:delivered', { chatId: chat_id, messageIds: message_ids, userId: user_id });
            _updateReceiptUI(message_ids, user_id, 'delivered');
        });

        function _updateReceiptUI(messageIds, userId, status) {
            messageIds.forEach(id => {
                const row = Utils.qs(`.msg-row[data-msg-id="${id}"]`);
                if (!row) return;
                const tick = row.querySelector('.msg-meta__tick');
                if (tick) tick.dataset.status = status;
            });
        }

        function markRead(chatId, messageIds) {
            if (!messageIds.length) return;
            if (!_batch.has(chatId)) _batch.set(chatId, new Set());
            messageIds.forEach(id => _batch.get(chatId).add(id));

            clearTimeout(_timer);
            _timer = setTimeout(_flush, DEBOUNCE);
        }

        function _flush() {
            _batch.forEach((ids, chatId) => {
                if (!ids.size) return;
                send({
                    type:        'read_receipt',
                    chat_id:     chatId,
                    message_ids: [...ids],
                    read_at:     new Date().toISOString(),
                });
            });
            _batch.clear();
        }

        return { markRead };
    })();

    /* ──────────────────────────────────────────────────────────
       17. INCOMING MESSAGE ROUTER
    ────────────────────────────────────────────────────────── */
    on('new_message', msg => {
        const chatId   = msg.chat_id;
        const isActive = State.get('active_chat') === chatId;
        const myId     = App.getUser()?.id;

        // Don't process own echoes
        if (msg.sender_id === myId) return;

        EventBus.emit('chat:newMessage', msg);

        // Auto-mark as read if chat is open
        if (isActive && document.visibilityState === 'visible') {
            ReadReceipts.markRead(chatId, [msg.id]);
        } else {
            // Update unread badge
            const prev = State.get(`chat.${chatId}.unread`) || 0;
            State.set(`chat.${chatId}.unread`, prev + 1);
            const total = (State.get('unread_total') || 0) + 1;
            State.set('unread_total', total);

            // Push notification
            if (Notif.permitted) {
                Notif.show(msg.sender_name || I18n.t('new_message'), {
                    body:   msg.text || I18n.t('media_message'),
                    tag:    `chat-${chatId}`,
                    data:   { chatId },
                    onClick:() => Router.navigate(`/chat/${chatId}`)
                });
            }
        }
    });

    on('message_deleted', msg => {
        EventBus.emit('chat:messageDeleted', msg);
    });

    on('message_edited', msg => {
        EventBus.emit('chat:messageEdited', msg);
    });

    on('message_reaction', msg => {
        EventBus.emit('chat:reaction', msg);
    });

    on('chat_created', msg => {
        EventBus.emit('chat:created', msg);
    });

    on('chat_updated', msg => {
        EventBus.emit('chat:updated', msg);
    });

    on('member_joined', msg => {
        EventBus.emit('group:memberJoined', msg);
    });

    on('member_left', msg => {
        EventBus.emit('group:memberLeft', msg);
    });

    on('call_incoming', msg => {
        EventBus.emit('call:incoming', msg);
    });

    on('call_ended', msg => {
        EventBus.emit('call:ended', msg);
    });

    /* ──────────────────────────────────────────────────────────
       18. RECONNECT ON NETWORK RESTORE
    ────────────────────────────────────────────────────────── */
    EventBus.on('connection:online', () => {
        if (_status === 'reconnecting' || _status === 'disconnected') {
            console.log('[WS] Network restored — reconnecting');
            connect();
        }
    });

    EventBus.on('auth:login', ({ token }) => {
        _token = token;
        connect(token);
    });

    EventBus.on('auth:logout', () => {
        disconnect(1000, 'logout');
    });

    EventBus.on('app:foreground', () => {
        if (_status !== 'connected') connect();
    });

    /* ──────────────────────────────────────────────────────────
       19. SEND HELPERS  (typed wrappers)
    ────────────────────────────────────────────────────────── */
    const Actions = {

        sendMessage(chatId, payload) {
            const msg = {
                type:    'send_message',
                chat_id: chatId,
                id:      Utils.uuid(),
                ts:      Date.now(),
                ...payload,
            };
            return send(msg, { ack: true, ackTimeout: 15000 });
        },

        editMessage(chatId, msgId, text) {
            return send({ type: 'edit_message',   chat_id: chatId, message_id: msgId, text });
        },

        deleteMessage(chatId, msgId, forEveryone = false) {
            return send({ type: 'delete_message', chat_id: chatId, message_id: msgId, for_everyone: forEveryone });
        },

        reactMessage(chatId, msgId, emoji) {
            return send({ type: 'react_message',  chat_id: chatId, message_id: msgId, emoji });
        },

        forwardMessage(fromChatId, msgId, toChatIds) {
            return send({ type: 'forward_message', from_chat_id: fromChatId, message_id: msgId, to_chat_ids: toChatIds });
        },

        pinMessage(chatId, msgId, pin = true) {
            return send({ type: pin ? 'pin_message' : 'unpin_message', chat_id: chatId, message_id: msgId });
        },

        markRead(chatId, messageIds) {
            ReadReceipts.markRead(chatId, messageIds);
        },

        typing(chatId)     { Typing.notifyTyping(chatId); },
        stopTyping(chatId) { Typing.stopTyping(chatId);   },

        setPresence(status) { Presence.setMyStatus(status); },

        joinCall(chatId, type = 'video') {
            return send({ type: 'call_join', chat_id: chatId, call_type: type }, { ack: true });
        },

        leaveCall(chatId) {
            return send({ type: 'call_leave', chat_id: chatId });
        },

        callSignal(chatId, signal) {
            return send({ type: 'call_signal', chat_id: chatId, signal });
        },
    };

    /* ──────────────────────────────────────────────────────────
       20. DEBUG UTILITIES
    ────────────────────────────────────────────────────────── */
    const Debug = {
        status()      { return _status; },
        queueSize()   { return _queue.length; },
        pendingAcks() { return _pendingAcks.size; },
        latency()     { return _lastPongAt ? Date.now() - _lastPongAt : null; },
        handlers()    { return Object.fromEntries(Object.entries(_handlers).map(([k,v]) => [k, v.size])); },
        simulate(msg) { _dispatch(msg); },  // inject a fake message for testing
    };

    /* ──────────────────────────────────────────────────────────
       21. INIT HOOK
    ────────────────────────────────────────────────────────── */
    EventBus.on('auth:restored', ({ token }) => {
        connect(token);
    });

    /* ──────────────────────────────────────────────────────────
       22. PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        connect,
        disconnect,
        send,
        on,
        off,
        isConnected,
        Presence,
        Typing,
        ReadReceipts,
        Actions,
        Debug,
        get status()  { return _status; },
        get queue()   { return [..._queue]; },
    };

})(); // end Socket IIFE

window.Socket = Socket;

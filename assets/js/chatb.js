/* ============================================================
   CHAT.JS  —  Namak Messenger
   Chat page: message list, send, edit, delete, reply,
   reactions, media, voice recording, search, pagination
   ============================================================ */

'use strict';

const Chat = (() => {

    /* ──────────────────────────────────────────────────────────
       1. STATE
    ────────────────────────────────────────────────────────── */
    const _state = {
        chatId:       null,
        chatInfo:     null,
        messages:     [],          // ordered array
        messageMap:   new Map(),   // id → message object
        page:         1,
        hasMore:      true,
        loading:      false,
        loadingOlder: false,
        replyTo:      null,        // message being replied to
        editingId:    null,        // message id being edited
        selected:     new Set(),   // selected message ids (multi-select mode)
        searchQuery:  '',
        searchResults:[],
        searchIndex:  -1,
        draft:        {},          // chatId → draft text
        pinned:       [],          // pinned messages
        members:      new Map(),   // userId → member info
        myId:         null,
    };

    const PAGE_SIZE    = 40;
    const MAX_TEXT_LEN = 4096;

    /* ──────────────────────────────────────────────────────────
       2. DOM REFS
    ────────────────────────────────────────────────────────── */
    const DOM = {
        get window()       { return Utils.qs('.chat-window'); },
        get msgList()      { return Utils.qs('.message-list'); },
        get inputBar()     { return Utils.qs('.chat-input-bar'); },
        get input()        { return Utils.qs('.chat-input'); },
        get sendBtn()      { return Utils.qs('.chat-input-bar__send'); },
        get attachBtn()    { return Utils.qs('.chat-input-bar__attach'); },
        get emojiBtn()     { return Utils.qs('.chat-input-bar__emoji'); },
        get recordBtn()    { return Utils.qs('.chat-input-bar__record'); },
        get replyBar()     { return Utils.qs('.chat-reply-bar'); },
        get replyText()    { return Utils.qs('.chat-reply-bar__text'); },
        get replyCancel()  { return Utils.qs('.chat-reply-bar__cancel'); },
        get typingBar()    { return Utils.qs('.typing-indicator'); },
        get pinnedBar()    { return Utils.qs('.pinned-msg-bar'); },
        get scrollBtn()    { return Utils.qs('.scroll-to-bottom'); },
        get topbar()       { return Utils.qs('.topbar'); },
        get topbarName()   { return Utils.qs('.topbar__name'); },
        get topbarStatus() { return Utils.qs('.topbar__status'); },
        get topbarAvatar() { return Utils.qs('.topbar__avatar'); },
        get searchBar()    { return Utils.qs('.chat-search-bar'); },
        get searchInput()  { return Utils.qs('.chat-search-input'); },
        get loadingTop()   { return Utils.qs('.msg-loading-top'); },
        get emptyState()   { return Utils.qs('.chat-empty-state'); },
        get attachMenu()   { return Utils.qs('.attach-menu'); },
        get emojiPanel()   { return Utils.qs('.emoji-panel'); },
    };

    /* ──────────────────────────────────────────────────────────
       3. OPEN CHAT
    ────────────────────────────────────────────────────────── */
    async function open(chatId) {
        if (_state.chatId === chatId) return;

        // Save draft of previous chat
        if (_state.chatId) _saveDraft(_state.chatId);

        // Reset state
        _reset();
        _state.chatId = chatId;
        _state.myId   = App.getUser()?.id;

        State.set('active_chat', chatId);
        document.body.dataset.chatOpen = 'true';

        // Mark mobile layout
        Utils.qs('.app-shell')?.classList.add('app-shell--chat-open');

        // Show skeleton
        _renderSkeleton();

        try {
            // Parallel fetch: chat info + first page of messages
            const [info, history] = await Promise.all([
                Http.get(`/chats/${chatId}`),
                Http.get(`/chats/${chatId}/messages?limit=${PAGE_SIZE}&page=1`),
            ]);

            _state.chatInfo = info;
            _state.members  = new Map((info.members || []).map(m => [m.id, m]));
            _state.pinned   = info.pinned_messages || [];
            _state.hasMore  = history.has_more;
            _state.page     = 1;

            _setMessages(history.messages || []);
            _renderTopbar(info);
            _renderMessages(true);
            _renderPinnedBar();
            _restoreDraft(chatId);
            _scrollToBottom(false);

            // Mark all as read
            if (_state.messages.length) {
                const unread = _state.messages
                    .filter(m => !m.is_read && m.sender_id !== _state.myId)
                    .map(m => m.id);
                if (unread.length) Socket.Actions.markRead(chatId, unread);
            }

            // Subscribe to presence
            Socket.Presence.watch(info.other_user_id || info.id, ({ status, lastSeen }) => {
                _updateTopbarStatus(status, lastSeen);
            });

            // Reset unread counter
            State.set(`chat.${chatId}.unread`, 0);
            _recalcUnreadTotal();

            EventBus.emit('chat:opened', { chatId, info });

        } catch(e) {
            console.error('[Chat] Failed to open:', e);
            _renderError(e);
        }
    }

    function close() {
        if (_state.chatId) _saveDraft(_state.chatId);
        _reset();
        State.set('active_chat', null);
        document.body.dataset.chatOpen = 'false';
        Utils.qs('.app-shell')?.classList.remove('app-shell--chat-open');
        EventBus.emit('chat:closed');
    }

    function _reset() {
        _state.chatId      = null;
        _state.chatInfo    = null;
        _state.messages    = [];
        _state.messageMap  = new Map();
        _state.page        = 1;
        _state.hasMore     = true;
        _state.loading     = false;
        _state.replyTo     = null;
        _state.editingId   = null;
        _state.selected.clear();
        _state.searchQuery = '';
        _state.searchResults = [];
        _state.searchIndex   = -1;
        _state.pinned        = [];
        _state.members       = new Map();
        _cancelEdit();
        _clearReply();
        if (DOM.msgList) DOM.msgList.innerHTML = '';
    }

    /* ──────────────────────────────────────────────────────────
       4. MESSAGE STORE
    ────────────────────────────────────────────────────────── */
    function _setMessages(msgs) {
        msgs.forEach(m => _state.messageMap.set(m.id, m));
        _state.messages = [..._state.messageMap.values()]
            .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    }

    function _prependMessages(msgs) {
        msgs.forEach(m => _state.messageMap.set(m.id, m));
        _state.messages = [..._state.messageMap.values()]
            .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    }

    function _upsertMessage(msg) {
        const exists = _state.messageMap.has(msg.id);
        _state.messageMap.set(msg.id, msg);
        if (!exists) {
            _state.messages = [..._state.messageMap.values()]
                .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        }
        return exists;
    }

    function _removeMessage(id) {
        _state.messageMap.delete(id);
        _state.messages = _state.messages.filter(m => m.id !== id);
    }

    /* ──────────────────────────────────────────────────────────
       5. LOAD MORE  (pagination on scroll)
    ────────────────────────────────────────────────────────── */
    async function loadOlderMessages() {
        if (_state.loadingOlder || !_state.hasMore || !_state.chatId) return;
        _state.loadingOlder = true;

        const list    = DOM.msgList;
        const anchor  = list?.firstElementChild;
        const prevH   = list?.scrollHeight || 0;

        if (DOM.loadingTop) DOM.loadingTop.hidden = false;

        try {
            const data = await Http.get(
                `/chats/${_state.chatId}/messages?limit=${PAGE_SIZE}&page=${_state.page + 1}`
            );
            _state.page++;
            _state.hasMore = data.has_more;
            _prependMessages(data.messages || []);
            _prependMessageRows(data.messages || []);

            // Restore scroll position
            if (list) {
                list.scrollTop = list.scrollHeight - prevH;
            }
        } catch(e) {
            Toast.error(I18n.t('error.load_messages'));
        } finally {
            _state.loadingOlder = false;
            if (DOM.loadingTop) DOM.loadingTop.hidden = true;
        }
    }

    /* ──────────────────────────────────────────────────────────
       6. RENDER TOPBAR
    ────────────────────────────────────────────────────────── */
    function _renderTopbar(info) {
        const { topbar, topbarName, topbarStatus, topbarAvatar } = DOM;
        if (!topbar) return;

        if (topbarName)   topbarName.textContent   = info.name || info.title || '';
        if (topbarAvatar) {
            topbarAvatar.innerHTML = '';
            topbarAvatar.appendChild(Avatar.render(
                info.name || info.title,
                info.avatar,
                36,
                false
            ));
            topbarAvatar.dataset.userId = info.other_user_id || '';
        }

        _updateTopbarStatus(
            Socket.Presence.get(info.other_user_id)?.status || info.status,
            info.last_seen
        );

        // Group member count
        if (info.type === 'group' || info.type === 'channel') {
            if (topbarStatus) {
                topbarStatus.textContent = I18n.plural('members', info.member_count || 0);
            }
        }
    }

    function _updateTopbarStatus(status, lastSeen) {
        const el = DOM.topbarStatus;
        if (!el) return;
        if (status === 'online') {
            el.textContent       = I18n.t('online');
            el.dataset.status    = 'online';
        } else {
            el.textContent    = lastSeen
                ? I18n.t('last_seen') + ' ' + I18n.formatRelative(lastSeen)
                : I18n.t('offline');
            el.dataset.status = 'offline';
        }
    }

    /* ──────────────────────────────────────────────────────────
       7. RENDER MESSAGES  (full list)
    ────────────────────────────────────────────────────────── */
    function _renderMessages(clear = false) {
        const list = DOM.msgList;
        if (!list) return;
        if (clear) list.innerHTML = '';

        if (!_state.messages.length) {
            _showEmptyState();
            return;
        }
        _hideEmptyState();

        const frag = document.createDocumentFragment();
        let lastDate = null;
        let lastSenderId = null;

        _state.messages.forEach((msg, idx) => {
            const msgDate = new Date(msg.created_at).toDateString();
            if (msgDate !== lastDate) {
                frag.appendChild(_buildDateSeparator(msg.created_at));
                lastDate = msgDate;
                lastSenderId = null;
            }
            const grouped = lastSenderId === msg.sender_id;
            frag.appendChild(_buildMessageRow(msg, { grouped }));
            lastSenderId = msg.sender_id;
        });

        list.appendChild(frag);
    }

    function _prependMessageRows(msgs) {
        const list = DOM.msgList;
        if (!list || !msgs.length) return;

        const frag = document.createDocumentFragment();
        msgs = [...msgs].sort((a,b) => new Date(a.created_at) - new Date(b.created_at));

        msgs.forEach(msg => {
            frag.appendChild(_buildMessageRow(msg, { grouped: false }));
        });
        list.insertBefore(frag, list.firstChild);
    }

    /* ──────────────────────────────────────────────────────────
       8. BUILD MESSAGE ROW
    ────────────────────────────────────────────────────────── */
    function _buildMessageRow(msg, opts = {}) {
        const isMe    = msg.sender_id === _state.myId;
        const row     = Utils.el('div', {
            class:           `msg-row ${isMe ? 'msg-row--out' : 'msg-row--in'}${opts.grouped ? ' msg-row--grouped' : ''}`,
            'data-msg-id':   msg.id,
            'data-sender':   msg.sender_id,
            'data-type':     msg.content_type || 'text',
        });

        // Avatar (only for incoming, non-grouped)
        if (!isMe && !opts.grouped) {
            const member = _state.members.get(msg.sender_id);
            const avatarWrap = Utils.el('div', { class: 'msg-avatar' });
            avatarWrap.appendChild(Avatar.render(member?.name || msg.sender_name, member?.avatar, 30));
            row.appendChild(avatarWrap);
        } else if (!isMe) {
            row.appendChild(Utils.el('div', { class: 'msg-avatar msg-avatar--spacer' }));
        }

        // Bubble
        const bubble = _buildBubble(msg, isMe, opts.grouped);
        row.appendChild(bubble);

        // Message actions (hover/long-press)
        row.appendChild(_buildMsgActions(msg, isMe));

        return row;
    }

    function _buildBubble(msg, isMe, grouped) {
        const bubble = Utils.el('div', { class: 'msg-bubble' });

        // Sender name (group chats, incoming, first in group)
        if (!isMe && !grouped && _state.chatInfo?.type === 'group') {
            const member = _state.members.get(msg.sender_id);
            const name   = Utils.el('div', {
                class:         'msg-sender-name',
                text:          member?.name || msg.sender_name || '',
                'data-color':  Utils.stringToColor(msg.sender_id || ''),
            });
            bubble.appendChild(name);
        }

        // Reply preview
        if (msg.reply_to) {
            bubble.appendChild(_buildReplyPreview(msg.reply_to));
        }

        // Content
        bubble.appendChild(_buildContent(msg));

        // Forwarded tag
        if (msg.forwarded_from) {
            const fwd = Utils.el('div', {
                class: 'msg-forwarded',
                html:  `<svg viewBox="0 0 24 24"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h11a2 2 0 012 2v3"/><path d="M15 19l-4-4 4-4"/><path d="M19 15H11"/></svg>
                    <span>${I18n.t('forwarded_from')} <b>${Utils.escapeHtml(msg.forwarded_from.name)}</b></span>`
            });
            bubble.insertBefore(fwd, bubble.firstChild);
        }

        // Meta row (time + status ticks)
        bubble.appendChild(_buildMeta(msg, isMe));

        // Reactions
        if (msg.reactions && Object.keys(msg.reactions).length) {
            bubble.appendChild(_buildReactions(msg));
        }

        return bubble;
    }

    /* ──────────────────────────────────────────────────────────
       9. BUILD CONTENT  (by type)
    ────────────────────────────────────────────────────────── */
    function _buildContent(msg) {
        switch (msg.content_type) {
            case 'text':    return _buildTextContent(msg);
            case 'image':   return _buildImageContent(msg);
            case 'video':   return _buildVideoContent(msg);
            case 'audio':   return _buildAudioContent(msg);
            case 'voice':   return _buildVoiceContent(msg);
            case 'file':    return _buildFileContent(msg);
            case 'sticker': return _buildStickerContent(msg);
            case 'location':return _buildLocationContent(msg);
            case 'contact': return _buildContactContent(msg);
            case 'system':  return _buildSystemContent(msg);
            default:        return _buildTextContent(msg);
        }
    }

    function _buildTextContent(msg) {
        const el = Utils.el('div', { class: 'msg-text' });
        if (msg.text) {
            let html = Utils.escapeHtml(msg.text);
            // URLs
            html = Utils.parseLinks(html);
            // Bold **text**
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            // Italic _text_
            html = html.replace(/_(.+?)_/g, '<em>$1</em>');
            // Code `text`
            html = html.replace(/`(.+?)`/g, '<code>$1</code>');
            // Strike ~~text~~
            html = html.replace(/~~(.+?)~~/g, '<s>$1</s>');
            // Newlines
            html = html.replace(/\n/g, '<br>');

            el.innerHTML = html;

            // Edited indicator
            if (msg.is_edited) {
                el.innerHTML += ` <span class="msg-edited">${I18n.t('edited')}</span>`;
            }
        }
        return el;
    }

    function _buildImageContent(msg) {
        const wrap = Utils.el('div', { class: 'msg-image-wrap' });
        const img  = Utils.el('img', {
            class:   'msg-image',
            src:     msg.thumbnail_url || msg.media_url,
            alt:     msg.caption || '',
            loading: 'lazy',
        });
        img.dataset.fullSrc = msg.media_url;
        img.addEventListener('load', () => img.classList.add('loaded'));
        img.addEventListener('click', () => Lightbox.open(msg.media_url, msg.caption));
        wrap.appendChild(img);

        if (msg.caption) {
            wrap.appendChild(Utils.el('div', {
                class: 'msg-caption',
                html:  Utils.escapeHtml(msg.caption)
            }));
        }
        return wrap;
    }

    function _buildVideoContent(msg) {
        const wrap = Utils.el('div', { class: 'msg-video-wrap' });
        const thumb = Utils.el('div', { class: 'msg-video-thumb' });
        if (msg.thumbnail_url) {
            thumb.style.backgroundImage = `url(${msg.thumbnail_url})`;
        }
        const play = Utils.el('button', {
            class: 'msg-video-play',
            html:  '<svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
            'aria-label': I18n.t('play_video'),
        });
        play.addEventListener('click', () => Lightbox.openVideo(msg.media_url));

        const dur = Utils.el('span', {
            class: 'msg-video-duration',
            text:  msg.duration ? _formatDuration(msg.duration) : '',
        });

        thumb.appendChild(play);
        thumb.appendChild(dur);
        wrap.appendChild(thumb);
        if (msg.caption) {
            wrap.appendChild(Utils.el('div', { class: 'msg-caption', text: msg.caption }));
        }
        return wrap;
    }

    function _buildAudioContent(msg) {
        const wrap = Utils.el('div', { class: 'msg-audio' });
        const btn  = Utils.el('button', {
            class: 'msg-audio__play',
            html:  '<svg class="icon-play" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg><svg class="icon-pause" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>',
        });

        const waveform = Utils.el('div',   { class: 'msg-audio__waveform' });
        const time     = Utils.el('span',  { class: 'msg-audio__time', text: msg.duration ? _formatDuration(msg.duration) : '0:00' });
        const size     = Utils.el('span',  { class: 'msg-audio__size', text: msg.file_size ? I18n.formatFileSize(msg.file_size) : '' });

        _renderWaveform(waveform, msg.waveform || []);

        let audio = null;
        btn.addEventListener('click', () => _toggleAudio(btn, audio, msg.media_url, time, waveform, (a) => { audio = a; }));

        wrap.append(btn, waveform, time, size);
        return wrap;
    }

    function _buildVoiceContent(msg) {
        const wrap = _buildAudioContent(msg);
        wrap.classList.add('msg-audio--voice');
        return wrap;
    }

    function _buildFileContent(msg) {
        const wrap = Utils.el('a', {
            class:  'msg-file',
            href:   msg.media_url,
            target: '_blank',
            rel:    'noopener noreferrer',
        });
        const icon = Utils.el('div', { class: 'msg-file__icon', text: Utils.fileIcon(msg.file_name || '') });
        const info = Utils.el('div', { class: 'msg-file__info' });
        const name = Utils.el('div', { class: 'msg-file__name', text: msg.file_name || I18n.t('file') });
        const size = Utils.el('div', { class: 'msg-file__size', text: msg.file_size ? I18n.formatFileSize(msg.file_size) : '' });
        info.append(name, size);

        const dl = Utils.el('button', {
            class: 'msg-file__dl',
            html:  '<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
        });
        dl.addEventListener('click', e => { e.preventDefault(); _downloadFile(msg.media_url, msg.file_name); });

        wrap.append(icon, info, dl);
        return wrap;
    }

    function _buildStickerContent(msg) {
        const img = Utils.el('img', {
            class:  'msg-sticker',
            src:    msg.media_url,
            alt:    msg.emoji || 'sticker',
            loading:'lazy',
        });
        return img;
    }

    function _buildLocationContent(msg) {
        const { lat, lng, address } = msg.location || {};
        const wrap = Utils.el('div', { class: 'msg-location' });
        const map  = Utils.el('a', {
            class:  'msg-location__map',
            href:   `https://maps.google.com/?q=${lat},${lng}`,
            target: '_blank',
            rel:    'noopener noreferrer',
            style:  `background-image:url(https://staticmap.example.com/${lat},${lng},14,200x120.png)`,
        });
        const pin = Utils.el('div', { class: 'msg-location__pin', html: '📍' });
        map.appendChild(pin);
        const addr = Utils.el('div', { class: 'msg-location__address', text: address || `${lat}, ${lng}` });
        wrap.append(map, addr);
        return wrap;
    }

    function _buildContactContent(msg) {
        const c    = msg.shared_contact || {};
        const wrap = Utils.el('div', { class: 'msg-contact' });
        const av   = Utils.el('div', { class: 'msg-contact__avatar' });
        av.appendChild(Avatar.render(c.name, c.avatar, 40));
        const info = Utils.el('div', { class: 'msg-contact__info' });
        info.appendChild(Utils.el('div', { class: 'msg-contact__name', text: c.name || '' }));
        info.appendChild(Utils.el('div', { class: 'msg-contact__phone', text: c.phone || '' }));
        const add  = Utils.el('button', { class: 'msg-contact__add btn btn--sm btn--secondary', text: I18n.t('add_contact') });
        add.addEventListener('click', () => EventBus.emit('contact:addFromMsg', c));
        wrap.append(av, info, add);
        return wrap;
    }

    function _buildSystemContent(msg) {
        return Utils.el('div', {
            class: 'msg-system',
            text:  msg.text || '',
        });
    }

    /* ──────────────────────────────────────────────────────────
       10. REPLY PREVIEW (inside bubble)
    ────────────────────────────────────────────────────────── */
    function _buildReplyPreview(replyTo) {
        const el  = Utils.el('div', {
            class: 'msg-reply-preview',
            'data-ref-id': replyTo.id,
        });
        const bar = Utils.el('div', { class: 'msg-reply-preview__bar' });
        const body= Utils.el('div', { class: 'msg-reply-preview__body' });
        const who = Utils.el('div', { class: 'msg-reply-preview__name', text: replyTo.sender_name || '' });
        const txt = Utils.el('div', {
            class: 'msg-reply-preview__text',
            text:  Utils.truncate(replyTo.text || I18n.t('media_message'), 60),
        });
        body.append(who, txt);
        el.append(bar, body);
        el.addEventListener('click', () => _scrollToMessage(replyTo.id));
        return el;
    }

    /* ──────────────────────────────────────────────────────────
       11. META (time + tick)
    ────────────────────────────────────────────────────────── */
    function _buildMeta(msg, isMe) {
        const meta = Utils.el('div', { class: 'msg-meta' });
        const time = Utils.el('span', {
            class: 'msg-meta__time',
            text:  I18n.formatTime(msg.created_at),
            title: I18n.formatDate(msg.created_at, { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
        });
        meta.appendChild(time);

        if (isMe) {
            const tick = Utils.el('span', {
                class:           'msg-meta__tick',
                'data-status':   msg.status || 'sent',
                'aria-label':    msg.status || 'sent',
                html: `<svg viewBox="0 0 16 11">
                    <path class="tick1" d="M1 5l4 4L12 1"/>
                    <path class="tick2" d="M5 9l4-4"/>
                   </svg>`
            });
            meta.appendChild(tick);
        }
        return meta;
    }

    /* ──────────────────────────────────────────────────────────
       12. REACTIONS
    ────────────────────────────────────────────────────────── */
    function _buildReactions(msg) {
        const wrap = Utils.el('div', { class: 'msg-reactions' });
        Object.entries(msg.reactions || {}).forEach(([emoji, data]) => {
            const btn = Utils.el('button', {
                class:         `msg-reaction${data.includes?.(_state.myId) ? ' msg-reaction--mine' : ''}`,
                'data-emoji':  emoji,
            });
            btn.innerHTML = `<span class="msg-reaction__emoji">${emoji}</span><span class="msg-reaction__count">${data.count || data.length}</span>`;
            btn.addEventListener('click', () => _toggleReaction(msg.id, emoji));
            wrap.appendChild(btn);
        });

        // Add reaction button
        const addBtn = Utils.el('button', {
            class: 'msg-reaction msg-reaction--add',
            html:  '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
            'aria-label': I18n.t('add_reaction'),
        });
        addBtn.addEventListener('click', e => _showEmojiPicker(e, msg.id));
        wrap.appendChild(addBtn);
        return wrap;
    }

    /* ──────────────────────────────────────────────────────────
       13. MESSAGE ACTIONS PANEL
    ────────────────────────────────────────────────────────── */
    function _buildMsgActions(msg, isMe) {
        const wrap = Utils.el('div', { class: 'msg-actions' });

        const actions = [
            { icon: 'reply',    label: I18n.t('reply'),   fn: () => setReply(msg) },
            { icon: 'forward',  label: I18n.t('forward'), fn: () => _forwardMessage(msg) },
        ];
        if (isMe) {
            actions.push(
                { icon: 'edit',   label: I18n.t('edit'),   fn: () => startEdit(msg), disabled: msg.content_type !== 'text' },
                { icon: 'delete', label: I18n.t('delete'), fn: () => _promptDelete(msg), danger: true },
            );
        }
        actions.push({ icon: 'emoji', label: I18n.t('react'), fn: e => _showEmojiPicker(e, msg.id) });
        actions.push({ icon: 'more',  label: I18n.t('more'),  fn: e => _showMsgCtxMenu(e, msg) });

        actions.filter(a => !a.disabled).forEach(({ icon, label, fn, danger }) => {
            const btn = Utils.el('button', {
                class:        `msg-action-btn${danger ? ' msg-action-btn--danger' : ''}`,
                'aria-label': label,
                title:        label,
            });
            btn.innerHTML = _actionIcon(icon);
            btn.addEventListener('click', e => { e.stopPropagation(); fn(e); });
            wrap.appendChild(btn);
        });

        return wrap;
    }

    function _actionIcon(name) {
        const icons = {
            reply:   '<svg viewBox="0 0 24 24"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 00-4-4H4"/></svg>',
            forward: '<svg viewBox="0 0 24 24"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 014-4h12"/></svg>',
            edit:    '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            delete:  '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
            emoji:   '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
            more:    '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>',
        };
        return icons[name] || '';
    }

    /* ──────────────────────────────────────────────────────────
       14. DATE SEPARATOR
    ────────────────────────────────────────────────────────── */
    function _buildDateSeparator(date) {
        let label;
        if (Utils.isToday(date))     label = I18n.t('today');
        else if (Utils.isYesterday(date)) label = I18n.t('yesterday');
        else                         label = I18n.formatDate(date, { month: 'long', day: 'numeric' });

        return Utils.el('div', {
            class: 'msg-date-separator',
            html:  `<span class="msg-date-separator__label">${label}</span>`
        });
    }

    /* ──────────────────────────────────────────────────────────
       15. SEND MESSAGE
    ────────────────────────────────────────────────────────── */
    async function sendMessage() {
        const input = DOM.input;
        if (!input) return;

        const text = input.value.trim();
        if (!text && !_state.pendingMedia) return;
        if (text.length > MAX_TEXT_LEN) {
            Toast.error(I18n.t('error.msg_too_long'));
            return;
        }

        // Optimistic message
        const tempId  = 'temp_' + Utils.uuid();
        const tempMsg = {
            id:           tempId,
            chat_id:      _state.chatId,
            sender_id:    _state.myId,
            sender_name:  App.getUser()?.name,
            content_type: 'text',
            text,
            created_at:   new Date().toISOString(),
            status:       'sending',
            reply_to:     _state.replyTo ? { ..._state.replyTo } : null,
            is_edited:    false,
        };

        _upsertMessage(tempMsg);
        _appendMessageRow(tempMsg);
        _scrollToBottom(true);

        // Clear input
        input.value = '';
        input.style.height = '';
        Socket.Typing.stopTyping(_state.chatId);
        const replyTo = _state.replyTo;
        _clearReply();

        try {
            const sent = await Socket.Actions.sendMessage(_state.chatId, {
                content_type: 'text',
                text,
                reply_to_id: replyTo?.id || null,
            });

            // Replace temp with real
            _removeMessage(tempId);
            _upsertMessage({ ...sent, status: 'sent' });
            _updateMessageRow(tempId, sent);

        } catch(e) {
            // Mark as failed
            const row = Utils.qs(`.msg-row[data-msg-id="${tempId}"]`);
            if (row) row.classList.add('msg-row--failed');
            Toast.error(I18n.t('error.send_failed'), {
                action: { label: I18n.t('retry'), fn: () => _retrySend(tempId, text, replyTo) }
            });
        }
    }

    function _appendMessageRow(msg) {
        const list = DOM.msgList;
        if (!list) return;
        _hideEmptyState();

        // Check if we need a new date separator
        const lastRow  = list.lastElementChild;
        const lastDate = lastRow?.dataset?.date;
        const msgDate  = new Date(msg.created_at).toDateString();
        if (msgDate !== lastDate) {
            const sep = _buildDateSeparator(msg.created_at);
            sep.dataset.date = msgDate;
            list.appendChild(sep);
        }

        const prev = list.querySelector('.msg-row:last-of-type');
        const grouped = prev?.dataset?.sender === String(msg.sender_id);
        const row = _buildMessageRow(msg, { grouped });
        list.appendChild(row);

        EventBus.emit('chat:messageRendered', { msgId: msg.id, element: row });
    }

    function _updateMessageRow(oldId, newMsg) {
        const row = Utils.qs(`.msg-row[data-msg-id="${oldId}"]`);
        if (!row) return;
        const newRow = _buildMessageRow(newMsg, {
            grouped: row.classList.contains('msg-row--grouped')
        });
        row.replaceWith(newRow);
    }

    /* ──────────────────────────────────────────────────────────
       16. EDIT MESSAGE
    ────────────────────────────────────────────────────────── */
    function startEdit(msg) {
        if (msg.content_type !== 'text') return;
        _state.editingId = msg.id;

        const input = DOM.input;
        if (input) {
            input.value = msg.text || '';
            input.focus();
            _autoResizeInput(input);
        }

        const bar = DOM.replyBar;
        if (bar) {
            bar.classList.add('chat-reply-bar--edit');
            bar.hidden = false;
            const label = bar.querySelector('.chat-reply-bar__title');
            const text  = bar.querySelector('.chat-reply-bar__text');
            if (label) label.textContent = I18n.t('editing');
            if (text)  text.textContent  = Utils.truncate(msg.text || '', 80);
        }

        // Highlight the row
        Utils.qs(`.msg-row[data-msg-id="${msg.id}"]`)?.classList.add('msg-row--editing');
    }

    async function _submitEdit() {
        const id    = _state.editingId;
        const input = DOM.input;
        if (!id || !input) return;

        const text = input.value.trim();
        if (!text) return;

        _cancelEdit();

        try {
            await Socket.Actions.editMessage(_state.chatId, id, text);
        } catch {
            Toast.error(I18n.t('error.edit_failed'));
        }
    }

    function _cancelEdit() {
        if (!_state.editingId) return;
        Utils.qs(`.msg-row[data-msg-id="${_state.editingId}"]`)?.classList.remove('msg-row--editing');
        _state.editingId = null;

        const bar = DOM.replyBar;
        if (bar) {
            bar.classList.remove('chat-reply-bar--edit');
            if (!_state.replyTo) bar.hidden = true;
        }
        if (DOM.input) DOM.input.value = '';
    }

    /* ──────────────────────────────────────────────────────────
       17. DELETE MESSAGE
    ────────────────────────────────────────────────────────── */
    function _promptDelete(msg) {
        const isMe = msg.sender_id === _state.myId;
        const m    = Modal.open('modalDeleteMessage');
        if (!m) return;

        const delForAll = m.el.querySelector('#deleteForAll');
        if (delForAll) delForAll.hidden = !isMe;

        m.el.querySelector('[data-action="delete-confirm"]')
            ?.addEventListener('click', async () => {
                const forEveryone = delForAll?.checked && isMe;
                Modal.close('modalDeleteMessage');
                try {
                    await Socket.Actions.deleteMessage(_state.chatId, msg.id, forEveryone);
                } catch {
                    Toast.error(I18n.t('error.delete_failed'));
                }
            }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       18. REPLY
    ────────────────────────────────────────────────────────── */
    function setReply(msg) {
        _cancelEdit();
        _state.replyTo = msg;

        const bar = DOM.replyBar;
        if (bar) {
            bar.hidden = false;
            bar.classList.remove('chat-reply-bar--edit');
            const title = bar.querySelector('.chat-reply-bar__title');
            const text  = bar.querySelector('.chat-reply-bar__text');
            if (title) title.textContent = msg.sender_name || I18n.t('reply');
            if (text)  text.textContent  = Utils.truncate(msg.text || I18n.t('media_message'), 80);
        }

        DOM.input?.focus();
    }

    function _clearReply() {
        _state.replyTo = null;
        const bar = DOM.replyBar;
        if (bar && !_state.editingId) bar.hidden = true;
    }

    /* ──────────────────────────────────────────────────────────
       19. REACTIONS
    ────────────────────────────────────────────────────────── */
    function _toggleReaction(msgId, emoji) {
        Socket.Actions.reactMessage(_state.chatId, msgId, emoji);
    }

    function _showEmojiPicker(e, msgId) {
        e.stopPropagation();
        const panel = Utils.qs('.reaction-picker');
        if (!panel) return;

        panel.dataset.msgId = msgId;
        const rect = e.currentTarget.getBoundingClientRect();
        panel.style.top  = (rect.top  - panel.offsetHeight - 8) + 'px';
        panel.style.left = (rect.left - panel.offsetWidth  / 2) + 'px';
        panel.hidden     = false;
    }

    /* ──────────────────────────────────────────────────────────
       20. FORWARD
    ────────────────────────────────────────────────────────── */
    function _forwardMessage(msg) {
        EventBus.emit('message:forward', { msg });
        Modal.open('modalForward');
    }

    /* ──────────────────────────────────────────────────────────
       21. CONTEXT MENU
    ────────────────────────────────────────────────────────── */
    function _showMsgCtxMenu(e, msg) {
        e.preventDefault();
        const menu   = Utils.qs('.msg-ctx-menu');
        if (!menu) return;
        const isMe   = msg.sender_id === _state.myId;
        menu.innerHTML = '';

        const items = [
            { label: I18n.t('reply'),    icon: 'reply',   fn: () => setReply(msg) },
            { label: I18n.t('forward'),  icon: 'forward', fn: () => _forwardMessage(msg) },
            { label: I18n.t('copy'),     icon: 'copy',    fn: () => Utils.copyText(msg.text || '') },
            { label: I18n.t('select'),   icon: 'select',  fn: () => _selectMessage(msg.id) },
            { label: I18n.t('pin'),      icon: 'pin',     fn: () => _pinMessage(msg) },
            ...(isMe ? [
                { label: I18n.t('edit'),   icon: 'edit',   fn: () => startEdit(msg), disabled: msg.content_type !== 'text' },
                { label: I18n.t('delete'), icon: 'delete', fn: () => _promptDelete(msg), danger: true },
            ] : []),
        ].filter(i => !i.disabled);

        items.forEach(({ label, fn, danger }) => {
            const item = Utils.el('div', {
                class: `msg-ctx-item${danger ? ' msg-ctx-item--danger' : ''}`,
                text:  label,
            });
            item.addEventListener('click', () => { fn(); menu.hidden = true; });
            menu.appendChild(item);
        });

        // Position
        const vw = window.innerWidth, vh = window.innerHeight;
        let x = e.clientX, y = e.clientY;
        menu.hidden = false;
        const mw = menu.offsetWidth, mh = menu.offsetHeight;
        if (x + mw > vw) x = vw - mw - 8;
        if (y + mh > vh) y = vh - mh - 8;
        menu.style.cssText = `left:${x}px;top:${y}px`;

        const close = () => { menu.hidden = true; document.removeEventListener('click', close); };
        setTimeout(() => document.addEventListener('click', close), 0);
    }

    /* ──────────────────────────────────────────────────────────
       22. PIN MESSAGE
    ────────────────────────────────────────────────────────── */
    async function _pinMessage(msg) {
        try {
            await Socket.Actions.pinMessage(_state.chatId, msg.id, true);
            _state.pinned.unshift(msg);
            _renderPinnedBar();
        } catch { Toast.error(I18n.t('error.pin_failed')); }
    }

    function _renderPinnedBar() {
        const bar = DOM.pinnedBar;
        if (!bar) return;
        if (!_state.pinned.length) { bar.hidden = true; return; }

        const msg = _state.pinned[0];
        const text = bar.querySelector('.pinned-msg-bar__text');
        const count = bar.querySelector('.pinned-msg-bar__count');
        if (text)  text.textContent  = Utils.truncate(msg.text || I18n.t('pinned_message'), 60);
        if (count) count.textContent = _state.pinned.length > 1 ? `+${_state.pinned.length - 1}` : '';
        bar.hidden = false;

        bar.onclick = () => _scrollToMessage(msg.id);
    }

    /* ──────────────────────────────────────────────────────────
       23. MULTI-SELECT
    ────────────────────────────────────────────────────────── */
    function _selectMessage(id) {
        _state.selected.add(id);
        document.body.classList.add('msg-select-mode');
        Utils.qs(`.msg-row[data-msg-id="${id}"]`)?.classList.add('msg-row--selected');
        _updateSelectToolbar();
    }

    function _deselectAll() {
        _state.selected.clear();
        document.body.classList.remove('msg-select-mode');
        Utils.qsa('.msg-row--selected').forEach(r => r.classList.remove('msg-row--selected'));
        _updateSelectToolbar();
    }

    function _updateSelectToolbar() {
        const bar = Utils.qs('.msg-select-toolbar');
        if (!bar) return;
        bar.hidden = !_state.selected.size;
        const count = bar.querySelector('.msg-select-toolbar__count');
        if (count) count.textContent = I18n.plural('selected', _state.selected.size);
    }

    /* ──────────────────────────────────────────────────────────
       24. SCROLL HELPERS
    ────────────────────────────────────────────────────────── */
    function _scrollToBottom(smooth = false) {
        const list = DOM.msgList;
        if (!list) return;
        list.scrollTo({ top: list.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
    }

    function _scrollToMessage(msgId) {
        const row = Utils.qs(`.msg-row[data-msg-id="${msgId}"]`);
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('msg-row--highlight');
        setTimeout(() => row.classList.remove('msg-row--highlight'), 2000);
    }

    function _isNearBottom(threshold = 200) {
        const list = DOM.msgList;
        if (!list) return true;
        return list.scrollHeight - list.scrollTop - list.clientHeight < threshold;
    }

    /* ──────────────────────────────────────────────────────────
       25. INPUT HANDLING
    ────────────────────────────────────────────────────────── */
    function _bindInput() {
        const input   = DOM.input;
        const sendBtn = DOM.sendBtn;
        if (!input) return;

        input.addEventListener('input', () => {
            _autoResizeInput(input);
            _toggleSendBtn();
            if (input.value.trim()) {
                Socket.Typing.notifyTyping(_state.chatId);
            } else {
                Socket.Typing.stopTyping(_state.chatId);
            }
            _saveDraftDebounced(_state.chatId, input.value);
        });

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (_state.editingId) _submitEdit();
                else                  sendMessage();
            }
            if (e.key === 'Escape') {
                if (_state.editingId) _cancelEdit();
                else                  _clearReply();
            }
        });

        sendBtn?.addEventListener('click', () => {
            if (_state.editingId) _submitEdit();
            else                  sendMessage();
        });

        DOM.replyCancel?.addEventListener('click', () => {
            _clearReply();
            _cancelEdit();
        });
    }

    function _autoResizeInput(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }

    function _toggleSendBtn() {
        const btn  = DOM.sendBtn;
        const rec  = DOM.recordBtn;
        const hasText = DOM.input?.value.trim().length > 0;
        if (btn)  btn.hidden  = !hasText;
        if (rec)  rec.hidden  =  hasText;
    }

    /* ──────────────────────────────────────────────────────────
       26. DRAFT
    ────────────────────────────────────────────────────────── */
    const _saveDraftDebounced = Utils.debounce((chatId, text) => {
        if (text) _state.draft[chatId] = text;
        else      delete _state.draft[chatId];
        Store.set('drafts', _state.draft);
    }, 600);

    function _saveDraft(chatId) {
        const text = DOM.input?.value || '';
        if (text) _state.draft[chatId] = text;
        else      delete _state.draft[chatId];
        Store.set('drafts', _state.draft);
    }

    function _restoreDraft(chatId) {
        const drafts = Store.get('drafts', {});
        _state.draft = drafts;
        const text   = drafts[chatId] || '';
        if (DOM.input) {
            DOM.input.value = text;
            _autoResizeInput(DOM.input);
            _toggleSendBtn();
        }
    }

    /* ──────────────────────────────────────────────────────────
       27. FILE / MEDIA ATTACH
    ────────────────────────────────────────────────────────── */
    function _bindAttach() {
        DOM.attachBtn?.addEventListener('click', e => {
            e.stopPropagation();
            const menu = DOM.attachMenu;
            if (menu) menu.hidden = !menu.hidden;
        });

        Utils.delegate(document, '[data-attach-type]', 'click', (e, el) => {
            const type = el.dataset.attachType;
            _openFilePicker(type);
            if (DOM.attachMenu) DOM.attachMenu.hidden = true;
        });

        // Drag & drop
        EventBus.on('chat:drop', e => {
            const files = [...(e.dataTransfer?.files || [])];
            if (files.length) _handleFiles(files);
        });

        // Paste
        document.addEventListener('paste', e => {
            if (_state.chatId && e.clipboardData?.files?.length) {
                _handleFiles([...e.clipboardData.files]);
            }
        });
    }

    function _openFilePicker(type) {
        const input = document.createElement('input');
        input.type  = 'file';
        input.multiple = true;

        const accepts = {
            image:    'image/*',
            video:    'video/*',
            audio:    'audio/*',
            document: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar',
            any:      '*',
        };
        input.accept = accepts[type] || accepts.any;

        input.addEventListener('change', () => {
            if (input.files?.length) _handleFiles([...input.files]);
        });
        input.click();
    }

    async function _handleFiles(files) {
        for (const file of files) {
            if (file.size > MAX_FILE_SIZE) {
                Toast.error(I18n.t('error.file_too_large', { name: file.name, max: I18n.formatFileSize(MAX_FILE_SIZE) }));
                continue;
            }
            await _uploadAndSend(file);
        }
    }

    async function _uploadAndSend(file) {
        const isImg   = Utils.isImage(file);
        const isVid   = Utils.isVideo(file);
        const isAud   = Utils.isAudio(file);

        // Optimistic preview
        const tempId  = 'temp_' + Utils.uuid();
        let preview   = null;
        if (isImg) preview = await Utils.fileToDataUrl(file);

        const type    = isImg ? 'image' : isVid ? 'video' : isAud ? 'audio' : 'file';
        const tempMsg = {
            id:            tempId,
            chat_id:       _state.chatId,
            sender_id:     _state.myId,
            sender_name:   App.getUser()?.name,
            content_type:  type,
            media_url:     preview || '',
            file_name:     file.name,
            file_size:     file.size,
            created_at:    new Date().toISOString(),
            status:        'uploading',
        };
        _upsertMessage(tempMsg);
        _appendMessageRow(tempMsg);
        _scrollToBottom(true);

        const toast   = Toast.loading(I18n.t('uploading') + ' ' + file.name);

        try {
            // Resize image before upload
            let blob = file;
            if (isImg && file.size > MAX_IMAGE_SIZE) {
                blob = await Utils.resizeImage(file);
            }

            const form = new FormData();
            form.append('file',    blob, file.name);
            form.append('chat_id', _state.chatId);
            form.append('type',    type);
            if (_state.replyTo) form.append('reply_to_id', _state.replyTo.id);

            const res = await Http.upload('/media/upload', form);
            toast.dismiss();

            // Send via socket with returned URL
            const sent = await Socket.Actions.sendMessage(_state.chatId, {
                content_type: type,
                media_url:    res.url,
                thumbnail_url:res.thumbnail_url || null,
                file_name:    file.name,
                file_size:    file.size,
                duration:     res.duration || null,
                waveform:     res.waveform  || null,
                reply_to_id:  _state.replyTo?.id || null,
            });

            _removeMessage(tempId);
            _upsertMessage({ ...sent, status: 'sent' });
            _updateMessageRow(tempId, sent);
            _clearReply();

        } catch(e) {
            toast.dismiss();
            Toast.error(I18n.t('error.upload_failed', { name: file.name }));
            const row = Utils.qs(`.msg-row[data-msg-id="${tempId}"]`);
            if (row) row.classList.add('msg-row--failed');
        }
    }

    /* ──────────────────────────────────────────────────────────
       28. VOICE RECORDING
    ────────────────────────────────────────────────────────── */
    const Voice = (() => {
        let _recorder    = null;
        let _chunks      = [];
        let _startTime   = null;
        let _timer       = null;
        let _analyser    = null;
        let _stream      = null;
        let _waveData    = [];
        let _animFrame   = null;

        async function start() {
            try {
                _stream   = await navigator.mediaDevices.getUserMedia({ audio: true });
                _recorder = new MediaRecorder(_stream, { mimeType: _bestMime() });
                _chunks   = [];
                _waveData = [];
                _startTime = Date.now();

                _recorder.ondataavailable = e => { if (e.data.size) _chunks.push(e.data); };
                _recorder.onstop = _onStop;
                _recorder.start(100);

                _setupAnalyser(_stream);
                _startTimer();
                _renderRecordingBar(true);

            } catch(e) {
                Toast.error(I18n.t('error.mic_denied'));
            }
        }

        function stop(send = true) {
            if (!_recorder || _recorder.state === 'inactive') return;
            _recorder.dataset = { send };
            _recorder.stop();
            _stream?.getTracks().forEach(t => t.stop());
            _stopTimer();
            _stopAnalyser();
            _renderRecordingBar(false);
        }

        function cancel() { stop(false); }

        function _onStop() {
            const shouldSend = _recorder.dataset?.send !== false;
            if (!shouldSend) { _cleanup(); return; }

            const duration = Math.round((Date.now() - _startTime) / 1000);
            if (duration < 1) { _cleanup(); return; }

            const mime = _bestMime();
            const blob = new Blob(_chunks, { type: mime });
            const file = new File([blob], `voice_${Date.now()}.ogg`, { type: mime });
            file._waveData = [..._waveData];
            file._duration = duration;
            _cleanup();
            _uploadAndSend(file);
        }

        function _bestMime() {
            const types = ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];
            return types.find(t => MediaRecorder.isTypeSupported(t)) || 'audio/webm';
        }

        function _setupAnalyser(stream) {
            const ctx = new AudioContext();
            _analyser = ctx.createAnalyser();
            _analyser.fftSize = 64;
            ctx.createMediaStreamSource(stream).connect(_analyser);
            _drawWaveform();
        }

        function _drawWaveform() {
            if (!_analyser) return;
            const data = new Uint8Array(_analyser.frequencyBinCount);
            _analyser.getByteFrequencyData(data);
            const avg = data.reduce((s,v) => s + v, 0) / data.length / 255;
            _waveData.push(Math.round(avg * 100));
            if (_waveData.length > 100) _waveData.shift();

            const bar = Utils.qs('.chat-recording-bar__wave');
            if (bar) {
                _renderWaveform(bar, _waveData.slice(-20));
            }
            _animFrame = requestAnimationFrame(_drawWaveform);
        }

        function _stopAnalyser() {
            if (_animFrame) cancelAnimationFrame(_animFrame);
            _analyser = null;
        }

        function _startTimer() {
            const el = Utils.qs('.chat-recording-bar__time');
            _timer = setInterval(() => {
                const s = Math.round((Date.now() - _startTime) / 1000);
                if (el) el.textContent = _formatDuration(s);
            }, 1000);
        }

        function _stopTimer() { clearInterval(_timer); _timer = null; }

        function _renderRecordingBar(show) {
            const bar = Utils.qs('.chat-recording-bar');
            if (bar) bar.hidden = !show;
            if (DOM.inputBar) DOM.inputBar.hidden = show;
        }

        function _cleanup() { _recorder = null; _chunks = []; _stream = null; }

        return { start, stop, cancel };
    })();

    function _bindRecord() {
        DOM.recordBtn?.addEventListener('pointerdown', e => {
            e.preventDefault();
            Voice.start();
        });
        DOM.recordBtn?.addEventListener('pointerup', () => Voice.stop(true));
        DOM.recordBtn?.addEventListener('pointerleave', () => Voice.stop(true));

        Utils.qs('.chat-recording-bar__cancel')
            ?.addEventListener('click', () => Voice.cancel());
        Utils.qs('.chat-recording-bar__send')
            ?.addEventListener('click', () => Voice.stop(true));
    }

    /* ──────────────────────────────────────────────────────────
       29. SEARCH IN CHAT
    ────────────────────────────────────────────────────────── */
    function openSearch() {
        const bar = DOM.searchBar;
        if (bar) { bar.hidden = false; DOM.searchInput?.focus(); }
    }

    function closeSearch() {
        const bar = DOM.searchBar;
        if (bar) bar.hidden = true;
        _clearSearchHighlights();
        _state.searchQuery   = '';
        _state.searchResults = [];
        _state.searchIndex   = -1;
    }

    function _bindSearch() {
        const input = DOM.searchInput;
        if (!input) return;

        input.addEventListener('input', Utils.debounce(async () => {
            const q = input.value.trim();
            if (!q) { _clearSearchHighlights(); return; }
            _state.searchQuery = q;

            try {
                const res = await Http.get(`/chats/${_state.chatId}/search?q=${encodeURIComponent(q)}&limit=50`);
                _state.searchResults = res.messages || [];
                _state.searchIndex   = 0;
                _highlightSearchResults();
                _jumpToSearchResult(0);
            } catch { /* silent */ }
        }, 400));

        Utils.qs('.chat-search-prev')?.addEventListener('click', () => {
            const i = (_state.searchIndex - 1 + _state.searchResults.length) % _state.searchResults.length;
            _jumpToSearchResult(i);
        });

        Utils.qs('.chat-search-next')?.addEventListener('click', () => {
            const i = (_state.searchIndex + 1) % _state.searchResults.length;
            _jumpToSearchResult(i);
        });

        Utils.qs('.chat-search-close')?.addEventListener('click', closeSearch);
    }

    function _highlightSearchResults() {
        _clearSearchHighlights();
        _state.searchResults.forEach(msg => {
            const row = Utils.qs(`.msg-row[data-msg-id="${msg.id}"]`);
            if (row) row.classList.add('msg-row--search-match');
        });
        const bar = DOM.searchBar;
        const counter = bar?.querySelector('.chat-search-counter');
        if (counter) {
            counter.textContent = _state.searchResults.length
                ? `${_state.searchIndex + 1} / ${_state.searchResults.length}`
                : I18n.t('no_results');
        }
    }

    function _clearSearchHighlights() {
        Utils.qsa('.msg-row--search-match').forEach(r => r.classList.remove('msg-row--search-match'));
    }

    function _jumpToSearchResult(idx) {
        if (!_state.searchResults.length) return;
        _state.searchIndex = idx;
        const msg = _state.searchResults[idx];
        if (msg) _scrollToMessage(msg.id);

        const counter = DOM.searchBar?.querySelector('.chat-search-counter');
        if (counter) counter.textContent = `${idx + 1} / ${_state.searchResults.length}`;
    }

    /* ──────────────────────────────────────────────────────────
       30. SCROLL & LOAD MORE
    ────────────────────────────────────────────────────────── */
    function _bindScroll() {
        const list = DOM.msgList;
        if (!list) return;

        const btn = DOM.scrollBtn;

        list.addEventListener('scroll', Utils.throttle(() => {
            // Load older on near top
            if (list.scrollTop < 80) loadOlderMessages();

            // Show/hide scroll-to-bottom
            if (btn) btn.hidden = _isNearBottom(300);

            // Mark visible messages as read
            _markVisibleRead();
        }, 200));

        btn?.addEventListener('click', () => _scrollToBottom(true));
    }

    function _markVisibleRead() {
        if (!_state.chatId) return;
        const list   = DOM.msgList;
        if (!list)   return;
        const rect   = list.getBoundingClientRect();
        const unread = [];

        Utils.qsa('.msg-row--in:not(.msg-row--read)', list).forEach(row => {
            const rr = row.getBoundingClientRect();
            if (rr.top >= rect.top && rr.bottom <= rect.bottom) {
                const id = row.dataset.msgId;
                if (id) { unread.push(id); row.classList.add('msg-row--read'); }
            }
        });

        if (unread.length) Socket.Actions.markRead(_state.chatId, unread);
    }

    /* ──────────────────────────────────────────────────────────
       31. REALTIME MESSAGE HANDLERS
    ────────────────────────────────────────────────────────── */
    function _bindSocket() {
        EventBus.on('chat:newMessage', msg => {
            if (msg.chat_id !== _state.chatId) return;
            _upsertMessage(msg);
            _appendMessageRow(msg);

            const atBottom = _isNearBottom();
            if (atBottom) _scrollToBottom(true);

            if (document.visibilityState === 'visible' && atBottom) {
                Socket.Actions.markRead(_state.chatId, [msg.id]);
            }
        });

        EventBus.on('chat:messageDeleted', ({ message_id, for_everyone }) => {
            if (!for_everyone) return;
            const row = Utils.qs(`.msg-row[data-msg-id="${message_id}"]`);
            if (row) {
                const bubble = row.querySelector('.msg-bubble');
                if (bubble) {
                    bubble.innerHTML = `<div class="msg-deleted">${I18n.t('message_deleted')}</div>`;
                }
            }
            _removeMessage(message_id);
        });

        EventBus.on('chat:messageEdited', msg => {
            if (msg.chat_id !== _state.chatId) return;
            const existing = _state.messageMap.get(msg.id);
            if (existing) {
                _upsertMessage({ ...existing, ...msg, is_edited: true });
                _updateMessageRow(msg.id, { ...existing, ...msg, is_edited: true });
            }
        });

        EventBus.on('chat:reaction', msg => {
            if (msg.chat_id !== _state.chatId) return;
            const existing = _state.messageMap.get(msg.message_id);
            if (existing) {
                existing.reactions = msg.reactions;
                const row = Utils.qs(`.msg-row[data-msg-id="${msg.message_id}"]`);
                if (row) {
                    const old = row.querySelector('.msg-reactions');
                    const next = _buildReactions(existing);
                    if (old) old.replaceWith(next);
                    else     row.querySelector('.msg-bubble')?.appendChild(next);
                }
            }
        });

        EventBus.on('receipt:read', ({ chatId, messageIds }) => {
            if (chatId !== _state.chatId) return;
            messageIds.forEach(id => {
                const tick = Utils.qs(`.msg-row[data-msg-id="${id}"] .msg-meta__tick`);
                if (tick) tick.dataset.status = 'read';
            });
        });

        EventBus.on('receipt:delivered', ({ chatId, messageIds }) => {
            if (chatId !== _state.chatId) return;
            messageIds.forEach(id => {
                const tick = Utils.qs(`.msg-row[data-msg-id="${id}"] .msg-meta__tick`);
                if (tick && tick.dataset.status !== 'read') tick.dataset.status = 'delivered';
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       32. AUDIO PLAYER
    ────────────────────────────────────────────────────────── */
    let _activeAudio = null;

    function _toggleAudio(btn, audio, url, timeEl, waveformEl, setAudio) {
        // Stop any other playing audio
        if (_activeAudio && _activeAudio !== audio) {
            _activeAudio.pause();
            _activeAudio = null;
        }

        if (!audio) {
            audio = new Audio(url);
            setAudio(audio);
            _activeAudio = audio;

            audio.addEventListener('timeupdate', () => {
                if (timeEl) timeEl.textContent = _formatDuration(Math.floor(audio.currentTime));
                _updateWaveformProgress(waveformEl, audio.currentTime / audio.duration);
            });
            audio.addEventListener('ended', () => {
                btn.classList.remove('playing');
                if (timeEl && audio.duration) timeEl.textContent = _formatDuration(Math.floor(audio.duration));
            });
            audio.play();
            btn.classList.add('playing');
        } else if (audio.paused) {
            audio.play();
            btn.classList.add('playing');
            _activeAudio = audio;
        } else {
            audio.pause();
            btn.classList.remove('playing');
            _activeAudio = null;
        }
    }

    /* ──────────────────────────────────────────────────────────
       33. WAVEFORM RENDERER
    ────────────────────────────────────────────────────────── */
    function _renderWaveform(container, data) {
        container.innerHTML = '';
        const bars = data.length ? data : Array(30).fill(20);
        bars.forEach(v => {
            const bar = Utils.el('span', {
                class: 'waveform-bar',
                style: `height:${Math.max(4, Math.min(100, v))}%`,
            });
            container.appendChild(bar);
        });
    }

    function _updateWaveformProgress(container, progress) {
        if (!container) return;
        const bars = container.querySelectorAll('.waveform-bar');
        const done = Math.floor(progress * bars.length);
        bars.forEach((bar, i) => {
            bar.classList.toggle('waveform-bar--played', i < done);
        });
    }

    /* ──────────────────────────────────────────────────────────
       34. LIGHTBOX
    ────────────────────────────────────────────────────────── */
    const Lightbox = (() => {
        let _currentSrc = null;
        let _isVideo    = false;

        function open(src, caption = '') {
            _currentSrc = src;
            _isVideo    = false;
            const el = Utils.qs('.lightbox');
            if (!el) return;

            const img = el.querySelector('.lightbox__img');
            const cap = el.querySelector('.lightbox__caption');
            const vid = el.querySelector('.lightbox__video');

            if (img) { img.src = src; img.hidden = false; }
            if (vid) vid.hidden = true;
            if (cap) cap.textContent = caption;

            el.hidden = false;
            document.body.classList.add('lightbox-open');
        }

        function openVideo(src) {
            _currentSrc = src;
            _isVideo    = true;
            const el  = Utils.qs('.lightbox');
            if (!el)  return;

            const vid = el.querySelector('.lightbox__video');
            const img = el.querySelector('.lightbox__img');

            if (vid) { vid.src = src; vid.hidden = false; vid.play(); }
            if (img) img.hidden = true;

            el.hidden = false;
            document.body.classList.add('lightbox-open');
        }

        function close() {
            const el = Utils.qs('.lightbox');
            if (!el) return;
            const vid = el.querySelector('.lightbox__video');
            if (vid && !vid.paused) vid.pause();
            el.hidden = true;
            document.body.classList.remove('lightbox-open');
        }

        // Bind
        Utils.qs('.lightbox__close')?.addEventListener('click', close);
        Utils.qs('.lightbox')?.addEventListener('click', e => {
            if (e.target.matches('.lightbox')) close();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') close();
        });

        return { open, openVideo, close };
    })();

    /* ──────────────────────────────────────────────────────────
       35. SKELETON / EMPTY STATES
    ────────────────────────────────────────────────────────── */
    function _renderSkeleton() {
        const list = DOM.msgList;
        if (!list) return;
        list.innerHTML = '';
        const patterns = [false, true, false, false, true, false, true, false];
        patterns.forEach(isOut => {
            const row = Utils.el('div', { class: `msg-skeleton ${isOut ? 'msg-skeleton--out' : ''}` });
            row.innerHTML = `
            <div class="msg-skeleton__avatar"></div>
            <div class="msg-skeleton__body">
                <div class="msg-skeleton__bubble" style="width:${40 + Math.random()*40}%"></div>
                <div class="msg-skeleton__meta"></div>
            </div>`;
            list.appendChild(row);
        });
    }

    function _renderError(e) {
        const list = DOM.msgList;
        if (!list) return;
        list.innerHTML = `
        <div class="chat-error-state">
            <div class="chat-error-state__icon">⚠️</div>
            <div class="chat-error-state__title">${I18n.t('error.load_chat')}</div>
            <div class="chat-error-state__sub">${Utils.escapeHtml(e.message || '')}</div>
            <button class="btn btn--primary" onclick="Chat.open('${_state.chatId}')">
                ${I18n.t('retry')}
            </button>
        </div>`;
    }

    function _showEmptyState() {
        if (DOM.emptyState) DOM.emptyState.hidden = false;
    }
    function _hideEmptyState() {
        if (DOM.emptyState) DOM.emptyState.hidden = true;
    }

    /* ──────────────────────────────────────────────────────────
       36. UNREAD TOTAL
    ────────────────────────────────────────────────────────── */
    function _recalcUnreadTotal() {
        // Re-sum from all chat states
        let total = 0;
        const all = State.dump();
        Object.entries(all).forEach(([k, v]) => {
            if (k.startsWith('chat.') && k.endsWith('.unread') && typeof v === 'number') {
                total += v;
            }
        });
        State.set('unread_total', total);
    }

    /* ──────────────────────────────────────────────────────────
       37. RETRY SEND
    ────────────────────────────────────────────────────────── */
    async function _retrySend(tempId, text, replyTo) {
        const row = Utils.qs(`.msg-row[data-msg-id="${tempId}"]`);
        if (row) row.classList.remove('msg-row--failed');

        try {
            const sent = await Socket.Actions.sendMessage(_state.chatId, {
                content_type: 'text',
                text,
                reply_to_id: replyTo?.id || null,
            });
            _removeMessage(tempId);
            _upsertMessage({ ...sent, status: 'sent' });
            _updateMessageRow(tempId, sent);
        } catch {
            const r = Utils.qs(`.msg-row[data-msg-id="${tempId}"]`);
            if (r) r.classList.add('msg-row--failed');
            Toast.error(I18n.t('error.send_failed'));
        }
    }

    /* ──────────────────────────────────────────────────────────
       38. DOWNLOAD FILE
    ────────────────────────────────────────────────────────── */
    function _downloadFile(url, name) {
        const a   = document.createElement('a');
        a.href    = url;
        a.download= name || 'file';
        a.rel     = 'noopener noreferrer';
        a.click();
    }

    /* ──────────────────────────────────────────────────────────
       39. MISC HELPERS
    ────────────────────────────────────────────────────────── */
    function _formatDuration(secs) {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    /* ──────────────────────────────────────────────────────────
       40. INIT
    ────────────────────────────────────────────────────────── */
    function init() {
        _bindInput();
        _bindAttach();
        _bindRecord();
        _bindSearch();
        _bindScroll();
        _bindSocket();

        // Route handler
        EventBus.on('page:chat', ({ id }) => open(id));

        // Keyboard shortcuts
        Shortcuts.register('ctrl+f', () => openSearch());

        // Escape global handler
        EventBus.on('ui:escape', () => {
            if (_state.searchQuery) { closeSearch(); return; }
            if (_state.selected.size) { _deselectAll(); return; }
            if (_state.editingId)  { _cancelEdit();  return; }
            if (_state.replyTo)    { _clearReply();  return; }
        });

        // Multi-select toolbar
        Utils.qs('.msg-select-toolbar__cancel')
            ?.addEventListener('click', _deselectAll);
        Utils.qs('.msg-select-toolbar__delete')
            ?.addEventListener('click', () => {
                [..._state.selected].forEach(id => {
                    const msg = _state.messageMap.get(id);
                    if (msg) _promptDelete(msg);
                });
            });
        Utils.qs('.msg-select-toolbar__forward')
            ?.addEventListener('click', () => {
                const msgs = [..._state.selected].map(id => _state.messageMap.get(id)).filter(Boolean);
                EventBus.emit('message:forwardMultiple', { msgs });
                Modal.open('modalForward');
            });

        console.log('[Chat] Initialized');
    }

    /* ──────────────────────────────────────────────────────────
       PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        init,
        open,
        close,
        sendMessage,
        setReply,
        startEdit,
        loadOlderMessages,
        openSearch,
        closeSearch,
        Lightbox,
        Voice,
        get state()    { return { ..._state }; },
        get chatId()   { return _state.chatId; },
    };

})();

window.Chat = Chat;

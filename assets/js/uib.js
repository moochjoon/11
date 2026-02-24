/* ============================================================
   UI.JS  —  Namak Messenger
   Sidebar, chat list, search, emoji panel, dropdowns,
   context menus, toasts CSS, swipe gestures, animations,
   sidebar tabs, new chat flow, forward modal
   ============================================================ */

'use strict';

const UI = (() => {

    /* ──────────────────────────────────────────────────────────
       1. SIDEBAR STATE
    ────────────────────────────────────────────────────────── */
    const _sidebar = {
        activeTab:   'chats',       // chats | groups | saved | archived
        chats:       [],            // full list from server
        filtered:    [],            // after search / filter
        filter:      'all',         // all | unread | online | archived
        searchQuery: '',
        loading:     false,
        page:        1,
        hasMore:     true,
    };

    /* ──────────────────────────────────────────────────────────
       2. DOM REFS
    ────────────────────────────────────────────────────────── */
    const D = {
        get sidebar()          { return Utils.qs('.sidebar'); },
        get sidebarBody()      { return Utils.qs('.sidebar__body'); },
        get chatList()         { return Utils.qs('.chat-list'); },
        get searchInput()      { return Utils.qs('.sidebar__search-input'); },
        get searchClear()      { return Utils.qs('.sidebar__search-clear'); },
        get filterBar()        { return Utils.qs('.sidebar-filter-bar'); },
        get tabs()             { return Utils.qsa('.sidebar__nav-item'); },
        get tabLine()          { return Utils.qs('.sidebar__tab-line'); },
        get newChatBtn()       { return Utils.qs('[data-action="new-chat"]'); },
        get newGroupBtn()      { return Utils.qs('[data-action="new-group"]'); },
        get headerMenu()       { return Utils.qs('.sidebar__header-menu'); },
        get headerMenuBtn()    { return Utils.qs('.sidebar__header-menu-btn'); },
    };

    /* ──────────────────────────────────────────────────────────
       3. CHAT LIST — FETCH & RENDER
    ────────────────────────────────────────────────────────── */
    async function loadChatList(refresh = false) {
        if (_sidebar.loading) return;
        if (refresh) {
            _sidebar.chats   = [];
            _sidebar.page    = 1;
            _sidebar.hasMore = true;
        }
        if (!_sidebar.hasMore) return;
        _sidebar.loading = true;

        if (refresh) _renderChatSkeleton();

        try {
            const data = await Http.get(
                `/chats?tab=${_sidebar.activeTab}&page=${_sidebar.page}&limit=30`
            );
            const chats = data.chats || [];
            _sidebar.chats   = refresh ? chats : [..._sidebar.chats, ...chats];
            _sidebar.hasMore = data.has_more;
            _sidebar.page++;
            _applyFilter();
            _renderChatList();
        } catch(e) {
            if (refresh) _renderChatListError();
        } finally {
            _sidebar.loading = false;
        }
    }

    function _applyFilter() {
        const q = _sidebar.searchQuery.toLowerCase();
        let list = [..._sidebar.chats];

        switch (_sidebar.filter) {
            case 'unread':   list = list.filter(c => (c.unread_count || 0) > 0);        break;
            case 'online':   list = list.filter(c => c.status === 'online');             break;
            case 'archived': list = list.filter(c => c.is_archived);                     break;
            default:         list = list.filter(c => !c.is_archived);
        }

        if (q) {
            list = list.filter(c =>
                (c.name || '').toLowerCase().includes(q) ||
                (c.last_message?.text || '').toLowerCase().includes(q)
            );
        }

        _sidebar.filtered = list;
    }

    /* ──────────────────────────────────────────────────────────
       4. RENDER CHAT LIST
    ────────────────────────────────────────────────────────── */
    function _renderChatList() {
        const container = D.chatList;
        if (!container) return;

        if (!_sidebar.filtered.length) {
            container.innerHTML = _sidebar.searchQuery
                ? _tplSearchEmpty(_sidebar.searchQuery)
                : _tplChatListEmpty(_sidebar.activeTab);
            return;
        }

        // Diff-render: only update changed items
        const existing = new Map(
            [...container.querySelectorAll('.chat-item')].map(el => [el.dataset.chatId, el])
        );

        const frag     = document.createDocumentFragment();
        const newOrder = [];

        _sidebar.filtered.forEach(chat => {
            let el = existing.get(String(chat.id));
            if (el) {
                _updateChatItem(el, chat);
                existing.delete(String(chat.id));
            } else {
                el = _buildChatItem(chat);
            }
            frag.appendChild(el);
            newOrder.push(chat.id);
        });

        // Remove stale
        existing.forEach(el => el.remove());

        container.innerHTML = '';
        container.appendChild(frag);

        // Highlight active
        const activeId = State.get('active_chat');
        if (activeId) _setActiveChatItem(String(activeId));
    }

    function _buildChatItem(chat) {
        const el        = Utils.el('div', {
            class:          `chat-item${chat.is_pinned ? ' chat-item--pinned' : ''}${chat.is_muted ? ' chat-item--muted' : ''}`,
            'data-chat-id': chat.id,
            role:           'button',
            tabindex:       '0',
        });
        el.innerHTML = _tplChatItem(chat);
        el.addEventListener('click',   () => _openChat(chat));
        el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') _openChat(chat); });
        el.addEventListener('contextmenu', e => { e.preventDefault(); _showChatCtxMenu(e, chat); });

        // Long-press (mobile)
        _bindLongPress(el, () => _showChatCtxMenu(null, chat));

        // Swipe (mobile)
        _bindSwipe(el, {
            onSwipeLeft:  () => _archiveChat(chat),
            onSwipeRight: () => _markChatRead(chat),
        });

        return el;
    }

    function _tplChatItem(chat) {
        const colorIdx  = Utils.stringToColor(chat.name || '');
        const avatar    = chat.avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(chat.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${colorIdx}">${Avatar.initials(chat.name)}</div>`;
        const onlineDot = chat.status === 'online'
            ? `<span class="chat-item__online"></span>` : '';
        const unread    = (chat.unread_count || 0) > 0
            ? `<span class="chat-item__badge${chat.is_muted ? ' chat-item__badge--muted' : ''}">${Utils.formatCount(chat.unread_count)}</span>` : '';
        const pinIcon   = chat.is_pinned
            ? `<span class="chat-item__pin">📌</span>` : '';
        const muteIcon  = chat.is_muted
            ? `<span class="chat-item__mute">🔇</span>` : '';
        const verified  = chat.is_verified
            ? `<svg class="chat-item__verified" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>` : '';
        const typing    = chat.is_typing
            ? `<span class="chat-item__typing">${I18n.t('typing_dots')}</span>`
            : `<span class="chat-item__last-msg">${Utils.escapeHtml(Utils.truncate(chat.last_message?.text || '', 42))}</span>`;

        const tick = chat.last_message?.is_mine
            ? `<span class="chat-item__tick" data-status="${chat.last_message.status || 'sent'}">
               <svg viewBox="0 0 16 11"><path class="tick1" d="M1 5l4 4L12 1"/><path class="tick2" d="M5 9l4-4"/></svg>
           </span>` : '';

        const time = chat.last_message?.created_at
            ? `<span class="chat-item__time">${_chatTime(chat.last_message.created_at)}</span>` : '';

        return `
        <div class="chat-item__avatar-wrap">
            ${avatar}${onlineDot}
        </div>
        <div class="chat-item__body">
            <div class="chat-item__row1">
                <span class="chat-item__name">${Utils.escapeHtml(chat.name || '')}${verified}</span>
                <span class="chat-item__time-wrap">${tick}${time}</span>
            </div>
            <div class="chat-item__row2">
                ${typing}
                <span class="chat-item__badges">${muteIcon}${pinIcon}${unread}</span>
            </div>
        </div>`;
    }

    function _updateChatItem(el, chat) {
        // Micro-update: only mutate what changed
        const badge = el.querySelector('.chat-item__badge');
        const count = chat.unread_count || 0;
        if (badge) {
            badge.textContent = Utils.formatCount(count);
            badge.hidden      = count === 0;
        } else if (count > 0) {
            const span = Utils.el('span', {
                class: `chat-item__badge${chat.is_muted ? ' chat-item__badge--muted' : ''}`,
                text:  Utils.formatCount(count),
            });
            el.querySelector('.chat-item__badges')?.prepend(span);
        }

        const lastMsg = el.querySelector('.chat-item__last-msg');
        if (lastMsg && chat.last_message) {
            lastMsg.textContent = Utils.truncate(chat.last_message.text || '', 42);
        }

        const timeEl = el.querySelector('.chat-item__time');
        if (timeEl && chat.last_message?.created_at) {
            timeEl.textContent = _chatTime(chat.last_message.created_at);
        }

        const tick = el.querySelector('.chat-item__tick');
        if (tick && chat.last_message?.status) {
            tick.dataset.status = chat.last_message.status;
        }

        const typing = el.querySelector('.chat-item__typing');
        if (typing) {
            typing.hidden = !chat.is_typing;
            if (lastMsg) lastMsg.hidden = chat.is_typing;
        }
    }

    function _setActiveChatItem(chatId) {
        Utils.qsa('.chat-item--active').forEach(el => el.classList.remove('chat-item--active'));
        Utils.qs(`.chat-item[data-chat-id="${chatId}"]`)?.classList.add('chat-item--active');
    }

    function _chatTime(ts) {
        if (!ts) return '';
        if (Utils.isToday(ts))     return I18n.formatTime(ts);
        if (Utils.isYesterday(ts)) return I18n.t('yesterday');
        if (Utils.isThisWeek(ts))  {
            return new Intl.DateTimeFormat(I18n.getLocale(), { weekday: 'short' }).format(new Date(ts));
        }
        return I18n.formatDate(ts, { day: 'numeric', month: 'numeric' });
    }

    function _openChat(chat) {
        _setActiveChatItem(String(chat.id));
        Router.navigate(`/chat/${chat.id}`);
    }

    /* ──────────────────────────────────────────────────────────
       5. CHAT CONTEXT MENU
    ────────────────────────────────────────────────────────── */
    function _showChatCtxMenu(e, chat) {
        const menu = Utils.qs('.chat-ctx-menu') || _createCtxMenu('chat-ctx-menu');

        menu.innerHTML = '';
        const items = [
            { label: I18n.t('open'),       fn: () => _openChat(chat) },
            { label: I18n.t('mark_read'),  fn: () => _markChatRead(chat),   disabled: !chat.unread_count },
            { label: chat.is_pinned ? I18n.t('unpin') : I18n.t('pin'),
                fn: () => _togglePin(chat) },
            { label: chat.is_muted ? I18n.t('unmute') : I18n.t('mute'),
                fn: () => _toggleMute(chat) },
            { label: chat.is_archived ? I18n.t('unarchive') : I18n.t('archive'),
                fn: () => _archiveChat(chat) },
            { label: I18n.t('delete_chat'),fn: () => _promptDeleteChat(chat), danger: true },
        ].filter(i => !i.disabled);

        items.forEach(({ label, fn, danger }) => {
            const item = Utils.el('div', {
                class: `ctx-menu-item${danger ? ' ctx-menu-item--danger' : ''}`,
                text:  label,
            });
            item.addEventListener('click', () => { fn(); menu.hidden = true; });
            menu.appendChild(item);
        });

        _positionCtxMenu(menu, e);
    }

    function _createCtxMenu(cls) {
        const menu = Utils.el('div', { class: `ctx-menu ${cls}` });
        document.body.appendChild(menu);
        const close = () => { menu.hidden = true; };
        document.addEventListener('click', close);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        return menu;
    }

    function _positionCtxMenu(menu, e) {
        menu.hidden = false;
        if (!e) {
            menu.style.cssText = `left:50%;top:50%;transform:translate(-50%,-50%)`;
            return;
        }
        const vw = window.innerWidth, vh = window.innerHeight;
        let x = e.clientX, y = e.clientY;
        const mw = menu.offsetWidth  || 200;
        const mh = menu.offsetHeight || 200;
        if (x + mw > vw) x = vw - mw - 8;
        if (y + mh > vh) y = vh - mh - 8;
        menu.style.cssText = `left:${x}px;top:${y}px;transform:none`;
        setTimeout(() => document.addEventListener('click', () => menu.hidden = true, { once: true }), 0);
    }

    /* ──────────────────────────────────────────────────────────
       6. CHAT ACTIONS
    ────────────────────────────────────────────────────────── */
    async function _markChatRead(chat) {
        try {
            await Http.patch(`/chats/${chat.id}/read`);
            chat.unread_count = 0;
            _applyFilter();
            _renderChatList();
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _togglePin(chat) {
        try {
            await Http.patch(`/chats/${chat.id}/pin`, { pinned: !chat.is_pinned });
            chat.is_pinned = !chat.is_pinned;
            // Move pinned to top
            _sidebar.chats.sort((a,b) => (b.is_pinned ? 1 : 0) - (a.is_pinned ? 1 : 0));
            _applyFilter();
            _renderChatList();
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _toggleMute(chat) {
        try {
            const until = chat.is_muted ? null : new Date(Date.now() + 8 * 3600000).toISOString();
            await Http.patch(`/chats/${chat.id}/mute`, { muted_until: until });
            chat.is_muted = !chat.is_muted;
            _applyFilter();
            _renderChatList();
            Toast.success(chat.is_muted ? I18n.t('muted') : I18n.t('unmuted'));
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _archiveChat(chat) {
        const prev = chat.is_archived;
        try {
            await Http.patch(`/chats/${chat.id}/archive`, { archived: !chat.is_archived });
            chat.is_archived = !chat.is_archived;
            _applyFilter();
            _renderChatList();
            Toast.success(prev ? I18n.t('unarchived') : I18n.t('archived'), {
                action: { label: I18n.t('undo'), fn: () => _archiveChat(chat) }
            });
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    function _promptDeleteChat(chat) {
        const m = Modal.open('modalDeleteChat');
        if (!m) return;
        m.el.querySelector('[data-action="delete-confirm"]')
            ?.addEventListener('click', async () => {
                Modal.close('modalDeleteChat');
                try {
                    await Http.delete(`/chats/${chat.id}`);
                    _sidebar.chats = _sidebar.chats.filter(c => c.id !== chat.id);
                    _applyFilter();
                    _renderChatList();
                    if (State.get('active_chat') === chat.id) Chat.close();
                } catch { Toast.error(I18n.t('error.generic')); }
            }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       7. SEARCH
    ────────────────────────────────────────────────────────── */
    function _bindSearch() {
        const input = D.searchInput;
        const clear = D.searchClear;
        if (!input) return;

        input.addEventListener('focus', () => {
            D.sidebar?.classList.add('sidebar--searching');
        });

        input.addEventListener('blur', () => {
            if (!input.value) D.sidebar?.classList.remove('sidebar--searching');
        });

        input.addEventListener('input', Utils.debounce(async () => {
            const q = input.value.trim();
            _sidebar.searchQuery = q;

            if (clear) clear.hidden = !q;

            if (!q) {
                _applyFilter();
                _renderChatList();
                _clearGlobalSearchResults();
                return;
            }

            // Local filter first (instant)
            _applyFilter();
            _renderChatList();

            // Then global search
            try {
                const res = await Http.get(`/search?q=${encodeURIComponent(q)}&limit=20`);
                _renderGlobalSearchResults(res);
            } catch { /* silent */ }
        }, 350));

        clear?.addEventListener('click', () => {
            input.value           = '';
            _sidebar.searchQuery  = '';
            clear.hidden          = true;
            D.sidebar?.classList.remove('sidebar--searching');
            _applyFilter();
            _renderChatList();
            _clearGlobalSearchResults();
        });

        input.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                input.value           = '';
                _sidebar.searchQuery  = '';
                if (clear) clear.hidden = true;
                input.blur();
                _applyFilter();
                _renderChatList();
                _clearGlobalSearchResults();
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                Utils.qs('.search-result-item')?.focus();
            }
        });
    }

    function _renderGlobalSearchResults(res) {
        const container = D.chatList;
        if (!container) return;

        const sections = [];

        if (res.contacts?.length) {
            sections.push(_tplSearchSection(
                I18n.t('search.contacts'),
                res.contacts.map(c => _tplSearchContactItem(c))
            ));
        }
        if (res.messages?.length) {
            sections.push(_tplSearchSection(
                I18n.t('search.messages'),
                res.messages.map(m => _tplSearchMessageItem(m))
            ));
        }
        if (res.groups?.length) {
            sections.push(_tplSearchSection(
                I18n.t('search.groups'),
                res.groups.map(g => _tplSearchGroupItem(g))
            ));
        }

        if (!sections.length) {
            container.innerHTML = _tplSearchEmpty(_sidebar.searchQuery);
            return;
        }

        container.innerHTML = sections.join('');

        // Bind click handlers
        Utils.delegate(container, '[data-search-chat-id]', 'click', (e, el) => {
            Router.navigate(`/chat/${el.dataset.searchChatId}`);
        });
        Utils.delegate(container, '[data-search-user-id]', 'click', (e, el) => {
            Router.navigate(`/profile/${el.dataset.searchUserId}`);
        });
    }

    function _tplSearchSection(title, items) {
        return `<div class="search-section">
        <div class="search-section__title">${title}</div>
        ${items.join('')}
    </div>`;
    }

    function _tplSearchContactItem(c) {
        const av = c.avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(c.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(c.name)}">${Avatar.initials(c.name)}</div>`;
        return `<div class="search-result-item chat-item" data-search-user-id="${c.id}" tabindex="0">
        <div class="chat-item__avatar-wrap">${av}</div>
        <div class="chat-item__body">
            <div class="chat-item__row1">
                <span class="chat-item__name">${Utils.escapeHtml(c.name)}</span>
            </div>
            <div class="chat-item__row2">
                <span class="chat-item__last-msg">${Utils.escapeHtml(c.username ? '@' + c.username : c.phone || '')}</span>
            </div>
        </div>
    </div>`;
    }

    function _tplSearchMessageItem(m) {
        const av = m.chat_avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(m.chat_avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(m.chat_name)}">${Avatar.initials(m.chat_name)}</div>`;
        return `<div class="search-result-item chat-item" data-search-chat-id="${m.chat_id}" tabindex="0">
        <div class="chat-item__avatar-wrap">${av}</div>
        <div class="chat-item__body">
            <div class="chat-item__row1">
                <span class="chat-item__name">${Utils.escapeHtml(m.chat_name)}</span>
                <span class="chat-item__time">${_chatTime(m.created_at)}</span>
            </div>
            <div class="chat-item__row2">
                <span class="chat-item__last-msg">${Utils.escapeHtml(Utils.truncate(m.text || '', 55))}</span>
            </div>
        </div>
    </div>`;
    }

    function _tplSearchGroupItem(g) {
        const av = g.avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(g.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(g.name)}">${Avatar.initials(g.name)}</div>`;
        return `<div class="search-result-item chat-item" data-search-chat-id="${g.id}" tabindex="0">
        <div class="chat-item__avatar-wrap">${av}</div>
        <div class="chat-item__body">
            <div class="chat-item__row1">
                <span class="chat-item__name">${Utils.escapeHtml(g.name)}</span>
            </div>
            <div class="chat-item__row2">
                <span class="chat-item__last-msg">${I18n.plural('members', g.member_count)}</span>
            </div>
        </div>
    </div>`;
    }

    function _clearGlobalSearchResults() {
        Utils.qsa('.search-section').forEach(el => el.remove());
    }

    /* ──────────────────────────────────────────────────────────
       8. FILTER BAR (All / Unread / Online)
    ────────────────────────────────────────────────────────── */
    function _bindFilterBar() {
        Utils.delegate(document, '.sidebar-filter-chip', 'click', (e, el) => {
            Utils.qsa('.sidebar-filter-chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            _sidebar.filter = el.dataset.filter || 'all';
            _applyFilter();
            _renderChatList();
        });
    }

    /* ──────────────────────────────────────────────────────────
       9. SIDEBAR TABS  (Chats / Groups / Saved / Archived)
    ────────────────────────────────────────────────────────── */
    function _bindTabs() {
        Utils.delegate(document, '.sidebar__nav-item', 'click', (e, el) => {
            const tab = el.dataset.tab;
            if (!tab || tab === _sidebar.activeTab) return;

            D.tabs.forEach(t => t.classList.remove('active'));
            el.classList.add('active');

            _sidebar.activeTab = tab;
            _sidebar.chats     = [];
            _sidebar.page      = 1;
            _sidebar.hasMore   = true;

            _animateTabIndicator(el);
            loadChatList(true);
            EventBus.emit('sidebar:tabChange', { tab });
        });
    }

    function _animateTabIndicator(activeEl) {
        const line = D.tabLine;
        if (!line || !activeEl) return;
        const rect    = activeEl.getBoundingClientRect();
        const parent  = activeEl.parentElement.getBoundingClientRect();
        line.style.width     = rect.width  + 'px';
        line.style.left      = (rect.left - parent.left) + 'px';
    }

    /* ──────────────────────────────────────────────────────────
       10. NEW CHAT FLOW
    ────────────────────────────────────────────────────────── */
    function _bindNewChat() {
        D.newChatBtn?.addEventListener('click', () => {
            Modal.open('modalNewChat');
        });
        D.newGroupBtn?.addEventListener('click', () => {
            Modal.open('modalNewGroup');
        });

        // New chat: search & pick contact
        const ncInput = Utils.qs('#newChatSearch');
        ncInput?.addEventListener('input', Utils.debounce(async () => {
            const q = ncInput.value.trim();
            if (!q) { Utils.qs('#newChatResults').innerHTML = ''; return; }
            try {
                const res = await Http.get(`/contacts/search?q=${encodeURIComponent(q)}`);
                _renderNewChatResults(res.contacts || []);
            } catch { /* silent */ }
        }, 300));
    }

    function _renderNewChatResults(contacts) {
        const el = Utils.qs('#newChatResults');
        if (!el) return;
        el.innerHTML = contacts.map(c => `
        <div class="contact-picker-item" data-user-id="${c.id}" tabindex="0">
            ${c.avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(c.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(c.name)}">${Avatar.initials(c.name)}</div>`}
            <div class="contact-picker-item__info">
                <div class="contact-picker-item__name">${Utils.escapeHtml(c.name)}</div>
                <div class="contact-picker-item__sub">${Utils.escapeHtml(c.username ? '@' + c.username : c.phone || '')}</div>
            </div>
        </div>
    `).join('');

        Utils.delegate(el, '.contact-picker-item', 'click', async (e, item) => {
            const userId = item.dataset.userId;
            Modal.close('modalNewChat');
            try {
                const chat = await Http.post('/chats', { user_id: userId, type: 'direct' });
                _upsertChatInList(chat);
                Router.navigate(`/chat/${chat.id}`);
            } catch { Toast.error(I18n.t('error.create_chat')); }
        });
    }

    /* ──────────────────────────────────────────────────────────
       11. FORWARD MODAL
    ────────────────────────────────────────────────────────── */
    function _bindForwardModal() {
        const input   = Utils.qs('#forwardSearch');
        const list    = Utils.qs('#forwardList');
        const sendBtn = Utils.qs('#forwardSend');
        let   _selected = new Set();
        let   _forwardMsg = null;

        EventBus.on('message:forward', ({ msg }) => {
            _forwardMsg = msg;
            _selected.clear();
            _renderForwardList(_sidebar.chats.slice(0, 30));
        });

        EventBus.on('message:forwardMultiple', ({ msgs }) => {
            _forwardMsg = msgs;
            _selected.clear();
            _renderForwardList(_sidebar.chats.slice(0, 30));
        });

        input?.addEventListener('input', Utils.debounce(async () => {
            const q = input.value.trim();
            if (!q) { _renderForwardList(_sidebar.chats.slice(0, 30)); return; }
            const filtered = _sidebar.chats.filter(c =>
                c.name.toLowerCase().includes(q.toLowerCase())
            );
            _renderForwardList(filtered);
        }, 250));

        function _renderForwardList(chats) {
            if (!list) return;
            list.innerHTML = chats.map(c => {
                const sel = _selected.has(c.id) ? 'forward-item--selected' : '';
                const av  = c.avatar
                    ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(c.avatar)}" alt="">`
                    : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(c.name)}">${Avatar.initials(c.name)}</div>`;
                return `<div class="forward-item ${sel}" data-chat-id="${c.id}">
                ${av}
                <div class="forward-item__name">${Utils.escapeHtml(c.name)}</div>
                <div class="forward-item__check"></div>
            </div>`;
            }).join('');

            Utils.delegate(list, '.forward-item', 'click', (e, el) => {
                const id = el.dataset.chatId;
                if (_selected.has(id)) { _selected.delete(id); el.classList.remove('forward-item--selected'); }
                else                   { _selected.add(id);    el.classList.add('forward-item--selected');    }
                if (sendBtn) sendBtn.disabled = !_selected.size;
            });
        }

        sendBtn?.addEventListener('click', async () => {
            if (!_selected.size || !_forwardMsg) return;
            const toChatIds = [..._selected];
            Modal.close('modalForward');
            try {
                const msgs = Array.isArray(_forwardMsg) ? _forwardMsg : [_forwardMsg];
                await Promise.all(msgs.map(msg =>
                    Socket.Actions.forwardMessage(msg.chat_id, msg.id, toChatIds)
                ));
                Toast.success(I18n.plural('forwarded_to', toChatIds.length));
            } catch { Toast.error(I18n.t('error.forward_failed')); }
        });
    }

    /* ──────────────────────────────────────────────────────────
       12. EMOJI PANEL
    ────────────────────────────────────────────────────────── */
    const EmojiPanel = (() => {
        const CATEGORIES = [
            { id: 'recent',  icon: '🕐', label: 'Recent'   },
            { id: 'smileys', icon: '😀', label: 'Smileys'  },
            { id: 'people',  icon: '👋', label: 'People'   },
            { id: 'nature',  icon: '🐶', label: 'Nature'   },
            { id: 'food',    icon: '🍕', label: 'Food'     },
            { id: 'travel',  icon: '✈️', label: 'Travel'   },
            { id: 'objects', icon: '💡', label: 'Objects'  },
            { id: 'symbols', icon: '❤️', label: 'Symbols'  },
            { id: 'flags',   icon: '🏳️', label: 'Flags'    },
        ];

        let _emojis   = {};
        let _loaded   = false;
        let _recent   = Store.get('recent_emojis', []);
        const MAX_RECENT = 36;

        async function _load() {
            if (_loaded) return;
            try {
                _emojis = await Http.get('/emoji/data.json');
                _loaded = true;
            } catch {
                // Minimal fallback
                _emojis = {
                    smileys: ['😀','😂','🥲','😍','🤔','😎','🤗','😤','😭','🥳','😴','🤯','🫡','🥹'],
                    people:  ['👋','👍','👎','✌️','🤞','👏','🙌','🤝','🫂','💪','🙏'],
                    nature:  ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯'],
                    food:    ['🍕','🍔','🌮','🍜','🍣','🍩','🎂','🍰','🧁','☕'],
                    symbols: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','💯','✨','⭐','🔥'],
                    objects: ['💡','📱','💻','🎮','📷','🎵','📚','✏️','🔑','💎'],
                };
                _loaded = true;
            }
        }

        function open(anchorEl) {
            const panel = Utils.qs('.emoji-panel');
            if (!panel) return;

            if (!panel.hidden) { panel.hidden = true; return; }

            _load().then(() => _render(panel));

            panel.hidden = false;

            if (anchorEl) {
                const rect = anchorEl.getBoundingClientRect();
                panel.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
                panel.style.left   = Math.max(8, rect.left - 200) + 'px';
            }
        }

        function _render(panel) {
            const tabs   = panel.querySelector('.emoji-panel__tabs');
            const grid   = panel.querySelector('.emoji-panel__grid');
            const search = panel.querySelector('.emoji-panel__search');
            if (!tabs || !grid) return;

            // Tabs
            tabs.innerHTML = CATEGORIES.map(c =>
                `<button class="emoji-tab" data-cat="${c.id}" title="${c.label}">${c.icon}</button>`
            ).join('');

            // Search
            search?.addEventListener('input', Utils.debounce(() => {
                const q = search.value.trim().toLowerCase();
                if (!q) { _renderCategory(grid, 'recent'); return; }
                const results = Object.values(_emojis).flat().filter(e =>
                    e.includes(q) || (typeof e === 'object' && e.keywords?.some(k => k.includes(q)))
                );
                _renderEmojis(grid, results.slice(0, 60));
            }, 200));

            // Category click
            tabs.addEventListener('click', e => {
                const btn = e.target.closest('.emoji-tab');
                if (!btn) return;
                tabs.querySelectorAll('.emoji-tab').forEach(t => t.classList.remove('active'));
                btn.classList.add('active');
                _renderCategory(grid, btn.dataset.cat);
            });

            // First render
            _renderCategory(grid, 'recent');
            tabs.querySelector('.emoji-tab')?.classList.add('active');

            // Emoji click
            grid.addEventListener('click', e => {
                const btn = e.target.closest('.emoji-btn');
                if (!btn) return;
                const emoji = btn.dataset.emoji;
                _insertEmoji(emoji);
                _addRecent(emoji);
            });
        }

        function _renderCategory(grid, cat) {
            const emojis = cat === 'recent'
                ? _recent
                : (_emojis[cat] || []);
            _renderEmojis(grid, emojis);
        }

        function _renderEmojis(grid, emojis) {
            grid.innerHTML = emojis.map(e =>
                `<button class="emoji-btn" data-emoji="${e}" title="${e}">${e}</button>`
            ).join('');
        }

        function _insertEmoji(emoji) {
            const input = Utils.qs('.chat-input');
            if (!input) return;

            const start = input.selectionStart;
            const end   = input.selectionEnd;
            const val   = input.value;
            input.value = val.slice(0, start) + emoji + val.slice(end);
            input.selectionStart = input.selectionEnd = start + emoji.length;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        }

        function _addRecent(emoji) {
            _recent = [emoji, ..._recent.filter(e => e !== emoji)].slice(0, MAX_RECENT);
            Store.set('recent_emojis', _recent);
        }

        return { open };
    })();

    /* ──────────────────────────────────────────────────────────
       13. DROPDOWN MENUS
    ────────────────────────────────────────────────────────── */
    function _bindDropdowns() {
        Utils.delegate(document, '[data-dropdown]', 'click', (e, el) => {
            e.stopPropagation();
            const menuId = el.dataset.dropdown;
            const menu   = Utils.qs(`#${menuId}`);
            if (!menu) return;

            const isOpen = !menu.hidden;
            // Close all others
            Utils.qsa('.dropdown-menu:not([hidden])').forEach(m => {
                if (m !== menu) m.hidden = true;
            });

            if (isOpen) { menu.hidden = true; return; }

            // Position
            const rect = el.getBoundingClientRect();
            const dir  = I18n.getDir();
            menu.hidden = false;
            const mw   = menu.offsetWidth;
            const mh   = menu.offsetHeight;
            const vw   = window.innerWidth;
            const vh   = window.innerHeight;

            let top  = rect.bottom + 6;
            let left = dir === 'rtl' ? rect.right - mw : rect.left;

            if (left + mw > vw) left = vw - mw - 8;
            if (left < 8)       left = 8;
            if (top  + mh > vh) top  = rect.top - mh - 6;

            menu.style.cssText = `top:${top}px;left:${left}px`;
        });
    }

    /* ──────────────────────────────────────────────────────────
       14. SWIPE GESTURES
    ────────────────────────────────────────────────────────── */
    function _bindSwipe(el, { onSwipeLeft, onSwipeRight, threshold = 60 } = {}) {
        let startX = 0, startY = 0, isDragging = false, dx = 0;
        const isTouchDevice = ('ontouchstart' in window);
        if (!isTouchDevice) return;

        el.addEventListener('touchstart', e => {
            startX     = e.touches[0].clientX;
            startY     = e.touches[0].clientY;
            isDragging = false;
            dx         = 0;
        }, { passive: true });

        el.addEventListener('touchmove', e => {
            dx = e.touches[0].clientX - startX;
            const dy = e.touches[0].clientY - startY;
            if (!isDragging && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) {
                isDragging = true;
            }
            if (isDragging) {
                e.preventDefault();
                el.style.transform = `translateX(${Utils.clamp(dx, -120, 120)}px)`;

                // Show swipe action hint
                const archiveHint = el.querySelector('.chat-item__swipe-action--archive');
                const readHint    = el.querySelector('.chat-item__swipe-action--read');
                if (archiveHint) archiveHint.style.opacity = Math.min(1, Math.abs(dx) / threshold);
                if (readHint)    readHint.style.opacity    = Math.min(1, Math.abs(dx) / threshold);
            }
        }, { passive: false });

        el.addEventListener('touchend', () => {
            if (!isDragging) return;
            el.style.transform = '';
            el.style.transition = 'transform 0.2s ease';
            setTimeout(() => el.style.transition = '', 200);

            if (dx < -threshold && onSwipeLeft)  onSwipeLeft();
            if (dx >  threshold && onSwipeRight) onSwipeRight();
        });
    }

    /* ──────────────────────────────────────────────────────────
       15. LONG PRESS
    ────────────────────────────────────────────────────────── */
    function _bindLongPress(el, fn, duration = 500) {
        let timer = null;
        const cancel = () => { clearTimeout(timer); timer = null; };

        el.addEventListener('pointerdown', e => {
            if (e.button && e.button !== 0) return;
            timer = setTimeout(() => {
                fn(e);
                navigator.vibrate?.(30);
            }, duration);
        });
        el.addEventListener('pointerup',     cancel);
        el.addEventListener('pointerleave',  cancel);
        el.addEventListener('pointermove',   e => {
            if (Math.abs(e.movementX) + Math.abs(e.movementY) > 5) cancel();
        });
        el.addEventListener('contextmenu',   e => { e.preventDefault(); cancel(); });
    }

    /* ──────────────────────────────────────────────────────────
       16. REALTIME — CHAT LIST UPDATES
    ────────────────────────────────────────────────────────── */
    function _bindSocket() {
        EventBus.on('chat:newMessage', msg => {
            const chat = _sidebar.chats.find(c => c.id === msg.chat_id);
            if (chat) {
                chat.last_message = msg;
                chat.updated_at   = msg.created_at;
                if (msg.sender_id !== App.getUser()?.id &&
                    State.get('active_chat') !== msg.chat_id) {
                    chat.unread_count = (chat.unread_count || 0) + 1;
                }
            } else {
                // New chat received — refresh
                loadChatList(true);
                return;
            }

            // Move to top (if not pinned)
            if (!chat.is_pinned) {
                _sidebar.chats = [
                    chat,
                    ..._sidebar.chats.filter(c => c.id !== msg.chat_id)
                ];
            }

            _applyFilter();
            _renderChatList();
        });

        EventBus.on('chat:created', chat => {
            _upsertChatInList(chat);
        });

        EventBus.on('chat:updated', chat => {
            _upsertChatInList(chat);
        });

        EventBus.on('presence:update', ({ userId, status }) => {
            _sidebar.chats.forEach(c => {
                if (c.other_user_id === userId) {
                    c.status = status;
                }
            });
            _applyFilter();
            _renderChatList();
        });

        // Typing indicators in chat list
        EventBus.on('typing:update', ({ chatId, typists }) => {
            const chat = _sidebar.chats.find(c => c.id === chatId);
            if (!chat) return;
            chat.is_typing = typists.length > 0;
            const el = Utils.qs(`.chat-item[data-chat-id="${chatId}"]`);
            if (el) _updateChatItem(el, chat);
        });

        // Update unread badge in sidebar tab
        State.watch('unread_total', count => {
            const badge = Utils.qs('.sidebar__nav-item[data-tab="chats"] .nav-badge');
            if (badge) {
                badge.textContent = count > 0 ? Utils.formatCount(count) : '';
                badge.hidden      = count === 0;
            }
        });
    }

    function _upsertChatInList(chat) {
        const idx = _sidebar.chats.findIndex(c => c.id === chat.id);
        if (idx > -1) _sidebar.chats[idx] = { ..._sidebar.chats[idx], ...chat };
        else          _sidebar.chats.unshift(chat);
        _applyFilter();
        _renderChatList();
    }

    /* ──────────────────────────────────────────────────────────
       17. SCROLL LOAD MORE  (sidebar infinite scroll)
    ────────────────────────────────────────────────────────── */
    function _bindSidebarScroll() {
        const body = D.sidebarBody;
        if (!body) return;
        body.addEventListener('scroll', Utils.throttle(() => {
            const near = body.scrollHeight - body.scrollTop - body.clientHeight < 100;
            if (near && _sidebar.hasMore && !_sidebar.loading) {
                loadChatList();
            }
        }, 300));
    }

    /* ──────────────────────────────────────────────────────────
       18. GLOBAL KEYBOARD SHORTCUT OPEN SEARCH
    ────────────────────────────────────────────────────────── */
    function _bindKeyboard() {
        EventBus.on('search:open', () => {
            D.searchInput?.focus();
            D.searchInput?.select();
        });
    }

    /* ──────────────────────────────────────────────────────────
       19. RESIZE — responsive panel management
    ────────────────────────────────────────────────────────── */
    function _bindResize() {
        const mq = window.matchMedia('(max-width: 767px)');
        function handle(e) {
            if (!e.matches && State.get('active_chat')) {
                // Desktop: show both panels
                Utils.qs('.app-shell')?.classList.remove('app-shell--chat-open');
            }
        }
        mq.addEventListener('change', handle);
        handle(mq);
    }

    /* ──────────────────────────────────────────────────────────
       20. BACK BUTTON (mobile)
    ────────────────────────────────────────────────────────── */
    function _bindBackButton() {
        Utils.delegate(document, '.topbar__back', 'click', () => {
            Chat.close();
            Router.navigate('/chats');
        });
    }

    /* ──────────────────────────────────────────────────────────
       21. EMOJI BTN BIND
    ────────────────────────────────────────────────────────── */
    function _bindEmoji() {
        Utils.delegate(document, '.chat-input-bar__emoji', 'click', (e, el) => {
            EmojiPanel.open(el);
        });
    }

    /* ──────────────────────────────────────────────────────────
       22. HEADER MENU DROPDOWN
    ────────────────────────────────────────────────────────── */
    function _bindHeaderMenu() {
        D.headerMenuBtn?.addEventListener('click', e => {
            e.stopPropagation();
            const menu = D.headerMenu;
            if (menu) menu.hidden = !menu.hidden;
        });

        Utils.delegate(document, '.sidebar__header-menu [data-action]', 'click', (e, el) => {
            const action = el.dataset.action;
            if (D.headerMenu) D.headerMenu.hidden = true;
            switch(action) {
                case 'new-group':    Modal.open('modalNewGroup');   break;
                case 'contacts':     Router.navigate('/contacts');  break;
                case 'archived':     _sidebar.filter = 'archived'; _applyFilter(); _renderChatList(); break;
                case 'settings':     Router.navigate('/settings');  break;
                case 'logout':       App.logout();                  break;
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       23. TOAST CONTAINER CSS  (injected dynamically)
    ────────────────────────────────────────────────────────── */
    function _injectToastStyles() {
        if (Utils.qs('#toast-styles')) return;
        const style = Utils.el('style', { id: 'toast-styles', html: `
        .toast-container {
            position:fixed; bottom:var(--space-5); left:50%;
            transform:translateX(-50%);
            z-index:var(--z-toast,9999);
            display:flex; flex-direction:column; gap:var(--space-2);
            align-items:center; pointer-events:none; width:max-content;
            max-width:calc(100vw - var(--space-8));
        }
        .toast {
            display:flex; align-items:center; gap:var(--space-2);
            padding:var(--space-3) var(--space-4);
            background:var(--surface-elevated,#fff);
            border:1px solid var(--border-light);
            border-radius:var(--radius-xl);
            box-shadow:var(--shadow-lg);
            font-size:var(--text-sm);
            color:var(--text);
            pointer-events:all;
            opacity:0;
            transform:translateY(12px) scale(0.96);
            transition:opacity .22s ease, transform .22s var(--transition-spring,ease);
            min-width:200px;
            max-width:420px;
        }
        .toast--visible { opacity:1; transform:translateY(0) scale(1); }
        .toast__icon { font-size:16px; flex-shrink:0; line-height:1; }
        .toast__msg  { flex:1; line-height:1.4; }
        .toast__action {
            background:none; border:none;
            color:var(--primary); font-size:var(--text-sm);
            font-weight:var(--weight-semibold); cursor:pointer;
            padding:0 var(--space-1); flex-shrink:0;
            white-space:nowrap;
        }
        .toast__close {
            background:none; border:none;
            color:var(--text-muted); font-size:18px;
            cursor:pointer; padding:0; line-height:1;
            flex-shrink:0;
        }
        .toast--success .toast__icon { color:var(--success,#4caf50); }
        .toast--error   .toast__icon { color:var(--danger,#f44336); }
        .toast--warning .toast__icon { color:var(--warning,#ff9800); }
        .toast--info    .toast__icon { color:var(--primary,#2196f3); }
        .toast--loading .toast__icon { animation:spin .8s linear infinite; display:inline-block; }
        @keyframes spin { to { transform:rotate(360deg); } }
        @media(max-width:767px){
            .toast-container { bottom:calc(var(--space-3) + var(--safe-bottom,0px)); }
            .toast { min-width:auto; max-width:calc(100vw - var(--space-6)); }
        }
    `});
        document.head.appendChild(style);
    }

    /* ──────────────────────────────────────────────────────────
       24. TEMPLATE HELPERS
    ────────────────────────────────────────────────────────── */
    function _tplChatListEmpty(tab) {
        const icons = { chats: '💬', groups: '👥', saved: '🔖', archived: '📦' };
        const msgs  = {
            chats:    I18n.t('empty.chats'),
            groups:   I18n.t('empty.groups'),
            saved:    I18n.t('empty.saved'),
            archived: I18n.t('empty.archived'),
        };
        return `<div class="chat-list-empty">
        <div class="chat-list-empty__icon">${icons[tab] || '💬'}</div>
        <div class="chat-list-empty__title">${msgs[tab] || I18n.t('empty.chats')}</div>
    </div>`;
    }

    function _tplSearchEmpty(q) {
        return `<div class="chat-list-empty">
        <div class="chat-list-empty__icon">🔍</div>
        <div class="chat-list-empty__title">${I18n.t('search.no_results')}</div>
        <div class="chat-list-empty__sub">"${Utils.escapeHtml(q)}"</div>
    </div>`;
    }

    function _renderChatSkeleton() {
        const container = D.chatList;
        if (!container) return;
        container.innerHTML = Array.from({ length: 8 }, () => `
        <div class="chat-skeleton">
            <div class="chat-skeleton__avatar"></div>
            <div class="chat-skeleton__body">
                <div class="chat-skeleton__line chat-skeleton__line--name"></div>
                <div class="chat-skeleton__line chat-skeleton__line--sub"></div>
            </div>
        </div>
    `).join('');
    }

    function _renderChatListError() {
        const container = D.chatList;
        if (!container) return;
        container.innerHTML = `
        <div class="chat-list-empty">
            <div class="chat-list-empty__icon">⚠️</div>
            <div class="chat-list-empty__title">${I18n.t('error.load_chats')}</div>
            <button class="btn btn--sm btn--primary" onclick="UI.loadChatList(true)">
                ${I18n.t('retry')}
            </button>
        </div>`;
    }

    /* ──────────────────────────────────────────────────────────
       25. PULL-TO-REFRESH  (mobile)
    ────────────────────────────────────────────────────────── */
    function _bindPullToRefresh() {
        const body     = D.sidebarBody;
        if (!body)     return;

        let startY     = 0;
        let pulling    = false;
        let pullDist   = 0;
        const MAX_PULL = 80;

        body.addEventListener('touchstart', e => {
            if (body.scrollTop === 0) {
                startY  = e.touches[0].clientY;
                pulling = true;
            }
        }, { passive: true });

        body.addEventListener('touchmove', e => {
            if (!pulling) return;
            pullDist = Math.max(0, e.touches[0].clientY - startY);
            if (pullDist > 0 && pullDist < MAX_PULL) {
                const indicator = Utils.qs('.pull-to-refresh');
                if (indicator) {
                    indicator.style.height  = pullDist + 'px';
                    indicator.style.opacity = pullDist / MAX_PULL;
                }
            }
        }, { passive: true });

        body.addEventListener('touchend', () => {
            if (pulling && pullDist >= MAX_PULL * 0.75) {
                loadChatList(true);
            }
            const indicator = Utils.qs('.pull-to-refresh');
            if (indicator) { indicator.style.height = '0'; indicator.style.opacity = '0'; }
            pulling  = false;
            pullDist = 0;
        });
    }

    /* ──────────────────────────────────────────────────────────
       26. ANIMATE NEW MESSAGE IN SIDEBAR
    ────────────────────────────────────────────────────────── */
    function _flashChatItem(chatId) {
        const el = Utils.qs(`.chat-item[data-chat-id="${chatId}"]`);
        if (!el) return;
        el.classList.add('chat-item--flash');
        el.addEventListener('animationend', () => el.classList.remove('chat-item--flash'), { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       27. ROUTE HANDLER
    ────────────────────────────────────────────────────────── */
    function _bindRoutes() {
        EventBus.on('page:chats',   () => {
            loadChatList(true);
        });

        EventBus.on('page:chat',    ({ id }) => {
            _setActiveChatItem(String(id));
        });

        EventBus.on('chat:newMessage', msg => {
            if (msg.sender_id !== App.getUser()?.id) {
                _flashChatItem(msg.chat_id);
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       28. INIT
    ────────────────────────────────────────────────────────── */
    function init() {
        _injectToastStyles();
        _bindSearch();
        _bindFilterBar();
        _bindTabs();
        _bindNewChat();
        _bindForwardModal();
        _bindDropdowns();
        _bindEmoji();
        _bindHeaderMenu();
        _bindBackButton();
        _bindSidebarScroll();
        _bindPullToRefresh();
        _bindResize();
        _bindSocket();
        _bindKeyboard();
        _bindRoutes();

        // Bind emoji btn in chat topbar
        Utils.delegate(document, '[data-action="chat-search"]', 'click', () => Chat.openSearch());
        Utils.delegate(document, '[data-action="chat-info"]',   'click', () => {
            const id = State.get('active_chat');
            if (id) Router.navigate(`/profile/${id}`);
        });

        // Close modals on overlay
        Utils.delegate(document, '[data-modal-close]', 'click', (e, el) => {
            Modal.close(el.closest('.modal-overlay')?.querySelector('.modal')?.id);
        });

        // Load initial chat list
        EventBus.on('app:ready', () => loadChatList(true));

        console.log('[UI] Initialized');
    }

    /* ──────────────────────────────────────────────────────────
       PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        init,
        loadChatList,
        EmojiPanel,
        get sidebar() { return { ..._sidebar }; },
    };

})();

window.UI = UI;

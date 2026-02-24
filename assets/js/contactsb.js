/* ============================================================
   CONTACTS.JS  —  Namak Messenger
   Contacts page: list, search, add, sync, import/export,
   invite, groups, favorites, requests, QR code scanner
   ============================================================ */

'use strict';

const Contacts = (() => {

    /* ──────────────────────────────────────────────────────────
       1. STATE
    ────────────────────────────────────────────────────────── */
    const _state = {
        all:          [],        // full contacts array
        filtered:     [],        // after search/filter
        groups:       new Map(), // letter → [contacts]
        favorites:    new Set(), // contact ids
        requests:     [],        // incoming contact requests
        searchQuery:  '',
        filter:       'all',     // all | online | favorites | recent
        loading:      false,
        page:         1,
        hasMore:      true,
        syncing:      false,
        selectedIds:  new Set(), // multi-select for group creation
        selectMode:   false,
    };

    const PAGE_SIZE = 50;

    /* ──────────────────────────────────────────────────────────
       2. DOM REFS
    ────────────────────────────────────────────────────────── */
    const D = {
        get page()          { return Utils.qs('.contacts-page'); },
        get list()          { return Utils.qs('.contacts-list'); },
        get searchInput()   { return Utils.qs('.contacts-search-input'); },
        get searchClear()   { return Utils.qs('.contacts-search-clear'); },
        get filterBar()     { return Utils.qs('.contacts-filter-bar'); },
        get fabAdd()        { return Utils.qs('[data-action="contacts-add"]'); },
        get fabGroup()      { return Utils.qs('[data-action="contacts-group"]'); },
        get sortBtn()       { return Utils.qs('[data-action="contacts-sort"]'); },
        get syncBtn()       { return Utils.qs('[data-action="contacts-sync"]'); },
        get selectBar()     { return Utils.qs('.contacts-select-bar'); },
        get selectCount()   { return Utils.qs('.contacts-select-bar__count'); },
        get requestsBadge() { return Utils.qs('.contacts-requests-badge'); },
        get alphabet()      { return Utils.qs('.contacts-alphabet'); },
        get emptyState()    { return Utils.qs('.contacts-empty'); },
    };

    /* ──────────────────────────────────────────────────────────
       3. LOAD CONTACTS
    ────────────────────────────────────────────────────────── */
    async function load(refresh = false) {
        if (_state.loading) return;
        if (refresh) {
            _state.all     = [];
            _state.page    = 1;
            _state.hasMore = true;
        }
        if (!_state.hasMore) return;
        _state.loading = true;

        if (refresh) _renderSkeleton();

        try {
            const [contactsRes, favsRes, reqRes] = await Promise.all([
                Http.get(`/contacts?page=${_state.page}&limit=${PAGE_SIZE}`),
                refresh ? Http.get('/contacts/favorites') : Promise.resolve(null),
                refresh ? Http.get('/contacts/requests')  : Promise.resolve(null),
            ]);

            const incoming = contactsRes.contacts || [];
            _state.all     = refresh ? incoming : [..._state.all, ...incoming];
            _state.hasMore = contactsRes.has_more;
            _state.page++;

            if (favsRes) {
                _state.favorites = new Set((favsRes.favorites || []).map(f => f.id));
            }
            if (reqRes) {
                _state.requests = reqRes.requests || [];
                _renderRequestsBadge();
            }

            _applyFilter();
            _buildGroups();
            _renderList();

        } catch(e) {
            _renderError(e);
        } finally {
            _state.loading = false;
        }
    }

    /* ──────────────────────────────────────────────────────────
       4. FILTER & GROUP
    ────────────────────────────────────────────────────────── */
    function _applyFilter() {
        const q = _state.searchQuery.toLowerCase();
        let list = [..._state.all];

        switch (_state.filter) {
            case 'online':
                list = list.filter(c => Socket.Presence.get(c.id)?.status === 'online'); break;
            case 'favorites':
                list = list.filter(c => _state.favorites.has(c.id));                      break;
            case 'recent':
                list = list.filter(c => c.last_interaction)
                    .sort((a,b) => new Date(b.last_interaction) - new Date(a.last_interaction))
                    .slice(0, 30);
                break;
        }

        if (q) {
            list = list.filter(c =>
                (c.name        || '').toLowerCase().includes(q) ||
                (c.username    || '').toLowerCase().includes(q) ||
                (c.phone       || '').toLowerCase().includes(q) ||
                (c.nickname    || '').toLowerCase().includes(q)
            );
        }

        _state.filtered = list;
    }

    function _buildGroups() {
        _state.groups.clear();

        // Sort alphabetically (Persian-aware)
        const sorted = [..._state.filtered].sort((a, b) => {
            const nameA = (a.sort_name || a.name || '').trim();
            const nameB = (b.sort_name || b.name || '').trim();
            return nameA.localeCompare(nameB, I18n.getLocale(), { sensitivity: 'base' });
        });

        sorted.forEach(c => {
            let letter = (c.sort_name || c.name || '?').trim()[0]?.toUpperCase() || '#';
            // Non-letter chars group under '#'
            if (!/[\p{L}]/u.test(letter)) letter = '#';
            if (!_state.groups.has(letter)) _state.groups.set(letter, []);
            _state.groups.get(letter).push(c);
        });
    }

    /* ──────────────────────────────────────────────────────────
       5. RENDER LIST
    ────────────────────────────────────────────────────────── */
    function _renderList() {
        const list = D.list;
        if (!list) return;

        if (!_state.filtered.length) {
            _showEmpty();
            _renderAlphabet([]);
            return;
        }
        _hideEmpty();

        list.innerHTML = '';
        const frag = document.createDocumentFragment();

        // Favorites section (if filter = all)
        if (_state.filter === 'all' && _state.favorites.size > 0 && !_state.searchQuery) {
            const favContacts = _state.all.filter(c => _state.favorites.has(c.id));
            if (favContacts.length) {
                frag.appendChild(_buildSectionHeader('⭐ ' + I18n.t('contacts.favorites')));
                frag.appendChild(_buildFavoritesRow(favContacts));
            }
        }

        // Requests section
        if (_state.requests.length && !_state.searchQuery && _state.filter === 'all') {
            frag.appendChild(_buildRequestsRow());
        }

        // Alphabetical groups
        const letters = [];
        _state.groups.forEach((contacts, letter) => {
            letters.push(letter);
            frag.appendChild(_buildSectionHeader(letter));
            contacts.forEach(c => frag.appendChild(_buildContactRow(c)));
        });

        list.appendChild(frag);
        _renderAlphabet(letters);
    }

    function _buildSectionHeader(letter) {
        return Utils.el('div', {
            class:        'contacts-section-header',
            text:         letter,
            'data-letter': letter,
        });
    }

    /* ──────────────────────────────────────────────────────────
       6. CONTACT ROW
    ────────────────────────────────────────────────────────── */
    function _buildContactRow(contact) {
        const presence  = Socket.Presence.get(contact.id);
        const isOnline  = presence?.status === 'online';
        const isFav     = _state.favorites.has(contact.id);
        const isSelected= _state.selectedIds.has(contact.id);

        const row = Utils.el('div', {
            class:          `contact-row${isSelected ? ' contact-row--selected' : ''}`,
            'data-id':      contact.id,
            role:           'button',
            tabindex:       '0',
        });

        // Checkbox (select mode)
        const checkbox = Utils.el('div', { class: 'contact-row__check' });
        checkbox.innerHTML = isSelected
            ? `<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
           <polyline points="22 4 12 14.01 9 11.01"/></svg>`
            : '';
        row.appendChild(checkbox);

        // Avatar
        const avWrap = Utils.el('div', { class: 'contact-row__avatar' });
        avWrap.appendChild(Avatar.render(contact.name, contact.avatar, 44, isOnline));
        row.appendChild(avWrap);

        // Info
        const info   = Utils.el('div',  { class: 'contact-row__info' });
        const name   = Utils.el('div',  { class: 'contact-row__name' });
        name.innerHTML = Utils.escapeHtml(contact.nickname || contact.name || '');
        if (contact.is_verified) {
            name.insertAdjacentHTML('beforeend',
                `<svg class="verified-badge verified-badge--sm" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
             </svg>`);
        }

        const sub = Utils.el('div', { class: 'contact-row__sub' });
        if (isOnline) {
            sub.textContent    = I18n.t('online');
            sub.dataset.status = 'online';
        } else if (contact.username) {
            sub.textContent = '@' + contact.username;
        } else if (contact.phone) {
            sub.textContent = contact.phone;
        } else if (presence?.lastSeen) {
            sub.textContent = I18n.formatRelative(presence.lastSeen);
        }

        info.append(name, sub);
        row.appendChild(info);

        // Fav star
        if (isFav) {
            row.appendChild(Utils.el('span', { class: 'contact-row__fav', text: '⭐' }));
        }

        // Events
        row.addEventListener('click', e => {
            if (_state.selectMode) {
                _toggleSelect(contact.id, row);
            } else {
                _openContactAction(contact);
            }
        });

        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                _state.selectMode ? _toggleSelect(contact.id, row) : _openContactAction(contact);
            }
        });

        row.addEventListener('contextmenu', e => {
            e.preventDefault();
            _showContactCtxMenu(e, contact);
        });

        Utils.delegate(row, '.contact-row__avatar', 'click', (e) => {
            if (!_state.selectMode) {
                e.stopPropagation();
                Profile.open(contact.id, 'user');
            }
        });

        // Long press → select mode
        _bindLongPress(row, () => {
            _enterSelectMode();
            _toggleSelect(contact.id, row);
        });

        return row;
    }

    /* ──────────────────────────────────────────────────────────
       7. FAVORITES HORIZONTAL SCROLL
    ────────────────────────────────────────────────────────── */
    function _buildFavoritesRow(contacts) {
        const wrap  = Utils.el('div', { class: 'contacts-favs' });
        const scroll= Utils.el('div', { class: 'contacts-favs__scroll' });

        contacts.slice(0, 12).forEach(c => {
            const item = Utils.el('div', { class: 'contacts-fav-item', 'data-id': c.id });
            const presence = Socket.Presence.get(c.id);
            const isOnline = presence?.status === 'online';

            item.appendChild(Avatar.render(c.name, c.avatar, 52, isOnline));
            item.appendChild(Utils.el('div', {
                class: 'contacts-fav-item__name',
                text:  Utils.truncate(c.nickname || c.name || '', 10),
            }));

            item.addEventListener('click', () => _openContactAction(c));
            scroll.appendChild(item);
        });

        wrap.appendChild(scroll);
        return wrap;
    }

    /* ──────────────────────────────────────────────────────────
       8. REQUESTS ROW
    ────────────────────────────────────────────────────────── */
    function _buildRequestsRow() {
        const row = Utils.el('div', { class: 'contact-requests-row', role: 'button', tabindex: '0' });
        row.innerHTML = `
        <div class="contact-requests-row__icon">👥</div>
        <div class="contact-requests-row__info">
            <div class="contact-requests-row__title">${I18n.t('contacts.requests')}</div>
            <div class="contact-requests-row__sub">
                ${I18n.plural('contact_requests', _state.requests.length)}
            </div>
        </div>
        <span class="contact-requests-row__badge">${_state.requests.length}</span>`;
        row.addEventListener('click', () => _openRequestsModal());
        return row;
    }

    function _renderRequestsBadge() {
        const badge = D.requestsBadge;
        if (!badge) return;
        badge.textContent = _state.requests.length || '';
        badge.hidden      = !_state.requests.length;
    }

    /* ──────────────────────────────────────────────────────────
       9. ALPHABET INDEX
    ────────────────────────────────────────────────────────── */
    function _renderAlphabet(letters) {
        const bar = D.alphabet;
        if (!bar) return;

        if (!letters.length) { bar.hidden = true; return; }
        bar.hidden   = false;
        bar.innerHTML= '';

        letters.forEach(letter => {
            const btn = Utils.el('button', {
                class: 'alphabet-btn',
                text:  letter,
            });
            btn.addEventListener('click', () => {
                const header = D.list?.querySelector(
                    `.contacts-section-header[data-letter="${letter}"]`
                );
                header?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            bar.appendChild(btn);
        });
    }

    /* ──────────────────────────────────────────────────────────
       10. CONTACT ACTION (tap → open)
    ────────────────────────────────────────────────────────── */
    function _openContactAction(contact) {
        // Open DM directly
        Http.post('/chats', { user_id: contact.id, type: 'direct' })
            .then(chat => Router.navigate(`/chat/${chat.id}`))
            .catch(() => Toast.error(I18n.t('error.create_chat')));
    }

    /* ──────────────────────────────────────────────────────────
       11. CONTEXT MENU
    ────────────────────────────────────────────────────────── */
    function _showContactCtxMenu(e, contact) {
        const isFav = _state.favorites.has(contact.id);
        const menu  = Utils.qs('.contact-ctx-menu') || _createCtxMenu();

        menu.innerHTML = '';
        const items = [
            { label: I18n.t('contacts.send_message'), fn: () => _openContactAction(contact) },
            { label: I18n.t('contacts.view_profile'), fn: () => Profile.open(contact.id, 'user') },
            { label: isFav ? I18n.t('contacts.unfav') : I18n.t('contacts.fav'),
                fn: () => _toggleFavorite(contact) },
            { label: I18n.t('contacts.set_nickname'), fn: () => _setNickname(contact) },
            { label: I18n.t('contacts.share'),        fn: () => _shareContact(contact) },
            { label: I18n.t('contacts.block'),        fn: () => _blockContact(contact), danger: true },
            { label: I18n.t('contacts.delete'),       fn: () => _promptDelete(contact), danger: true },
        ];

        items.forEach(({ label, fn, danger }) => {
            const item = Utils.el('div', {
                class: `ctx-menu-item${danger ? ' ctx-menu-item--danger' : ''}`,
                text:  label,
            });
            item.addEventListener('click', () => { fn(); menu.hidden = true; });
            menu.appendChild(item);
        });

        menu.hidden  = false;
        const vw = window.innerWidth, vh = window.innerHeight;
        let x = e.clientX, y = e.clientY;
        const mw = menu.offsetWidth, mh = menu.offsetHeight;
        if (x + mw > vw) x = vw - mw - 8;
        if (y + mh > vh) y = vh - mh - 8;
        menu.style.cssText = `left:${x}px;top:${y}px`;

        setTimeout(() => document.addEventListener('click', () => menu.hidden = true, { once: true }), 0);
    }

    function _createCtxMenu() {
        const menu = Utils.el('div', { class: 'ctx-menu contact-ctx-menu' });
        document.body.appendChild(menu);
        return menu;
    }

    /* ──────────────────────────────────────────────────────────
       12. ADD CONTACT MODAL
    ────────────────────────────────────────────────────────── */
    function openAddModal() {
        const m = Modal.open('modalAddContact');
        if (!m) return;

        const tabs     = m.el.querySelectorAll('[data-add-tab]');
        const panels   = m.el.querySelectorAll('[data-add-panel]');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t   => t.classList.remove('active'));
                panels.forEach(p => p.hidden = true);
                tab.classList.add('active');
                const panelId = tab.dataset.addTab;
                m.el.querySelector(`[data-add-panel="${panelId}"]`)?.removeAttribute('hidden');
            });
        });

        // Tab 1: by username/phone
        _bindAddBySearch(m.el);

        // Tab 2: QR code scan
        _bindQRScanner(m.el);

        // Tab 3: invite link
        _bindInviteLink(m.el);
    }

    function _bindAddBySearch(container) {
        const input   = container.querySelector('#addContactSearch');
        const results = container.querySelector('#addContactResults');
        const panel   = container.querySelector('[data-add-panel="search"]');
        if (!input || !results) return;

        input.addEventListener('input', Utils.debounce(async () => {
            const q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }

            results.innerHTML = `<div class="spinner spinner--sm"></div>`;

            try {
                const res = await Http.get(`/users/find?q=${encodeURIComponent(q)}`);
                const users = res.users || [];

                if (!users.length) {
                    results.innerHTML = `<div class="search-empty">${I18n.t('contacts.not_found')}</div>`;
                    return;
                }

                results.innerHTML = '';
                users.forEach(u => {
                    const isContact = _state.all.some(c => c.id === u.id);
                    const isMe      = u.id === App.getUser()?.id;
                    const item      = Utils.el('div', { class: 'user-search-item' });
                    item.innerHTML  = `
                    ${u.avatar
                        ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(u.avatar)}" alt="">`
                        : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(u.name)}">${Avatar.initials(u.name)}</div>`}
                    <div class="user-search-item__info">
                        <div class="user-search-item__name">${Utils.escapeHtml(u.name)}</div>
                        <div class="user-search-item__sub">${Utils.escapeHtml(u.username ? '@'+u.username : u.phone || '')}</div>
                    </div>
                    ${isMe ? `<span class="user-search-item__tag">${I18n.t('you')}</span>` :
                        isContact ? `<span class="user-search-item__tag">${I18n.t('contacts.already')}</span>` :
                            `<button class="btn btn--sm btn--primary user-search-item__add" data-user-id="${u.id}">
                          ${I18n.t('contacts.add')}
                       </button>`}`;

                    item.querySelector('.user-search-item__add')?.addEventListener('click', async btn => {
                        try {
                            await _sendContactRequest(u.id);
                            item.querySelector('.user-search-item__add').replaceWith(
                                Utils.el('span', { class: 'user-search-item__tag', text: I18n.t('contacts.request_sent') })
                            );
                        } catch { Toast.error(I18n.t('error.generic')); }
                    });

                    results.appendChild(item);
                });
            } catch { results.innerHTML = `<div class="search-empty">${I18n.t('error.generic')}</div>`; }
        }, 350));

        // Direct phone/username entry
        const submitBtn = container.querySelector('#addContactSubmit');
        submitBtn?.addEventListener('click', async () => {
            const val = input.value.trim();
            if (!val) return;
            try {
                await _sendContactRequest(null, val);
                Toast.success(I18n.t('contacts.request_sent'));
                Modal.close('modalAddContact');
            } catch(e) {
                Toast.error(e.body?.message || I18n.t('error.not_found'));
            }
        });
    }

    async function _sendContactRequest(userId, identifier = null) {
        const body = userId ? { user_id: userId } : { identifier };
        const res  = await Http.post('/contacts/request', body);

        if (res.auto_accepted) {
            // If mutual — add directly
            _upsertContact(res.contact);
            Toast.success(I18n.t('contacts.added'));
        } else {
            Toast.info(I18n.t('contacts.request_sent'));
        }
        return res;
    }

    /* ──────────────────────────────────────────────────────────
       13. QR CODE
    ────────────────────────────────────────────────────────── */
    function _bindQRScanner(container) {
        const panel     = container.querySelector('[data-add-panel="qr"]');
        const myQrWrap  = container.querySelector('#myQrCode');
        const scanBtn   = container.querySelector('#startQrScan');
        const video     = container.querySelector('#qrVideo');
        let   _scanning = false;
        let   _stream   = null;

        // Generate my QR
        if (myQrWrap) {
            const user = App.getUser();
            const link = `${location.origin}/add/${user?.username || user?.id}`;
            Http.get(`/qr/generate?data=${encodeURIComponent(link)}&size=200`)
                .then(res => {
                    myQrWrap.innerHTML = '';
                    const img = Utils.el('img', { src: res.url, alt: 'QR', class: 'qr-image' });
                    myQrWrap.appendChild(img);
                    // Share button
                    const shareBtn = Utils.el('button', {
                        class: 'btn btn--sm btn--ghost',
                        text:  I18n.t('share'),
                    });
                    shareBtn.addEventListener('click', () => _shareQR(res.url, link));
                    myQrWrap.appendChild(shareBtn);
                }).catch(() => {
                if (myQrWrap) myQrWrap.innerHTML = `<code class="qr-fallback">${App.getUser()?.username || ''}</code>`;
            });
        }

        // Scan
        scanBtn?.addEventListener('click', async () => {
            if (_scanning) {
                _stopScan();
                return;
            }
            try {
                _stream   = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                if (video) { video.srcObject = _stream; video.hidden = false; video.play(); }
                _scanning = true;
                scanBtn.textContent = I18n.t('contacts.stop_scan');
                _scanLoop(video, result => {
                    _stopScan();
                    _handleQRResult(result);
                });
            } catch { Toast.error(I18n.t('error.camera_denied')); }
        });

        function _stopScan() {
            _stream?.getTracks().forEach(t => t.stop());
            if (video) { video.hidden = true; video.srcObject = null; }
            _scanning = false;
            if (scanBtn) scanBtn.textContent = I18n.t('contacts.scan_qr');
        }
    }

    function _scanLoop(video, onResult) {
        if (!video || video.hidden) return;
        const canvas  = document.createElement('canvas');
        const ctx     = canvas.getContext('2d');
        let   running = true;

        function tick() {
            if (!running || video.readyState < 2) { requestAnimationFrame(tick); return; }
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            try {
                // Use BarcodeDetector if available
                if ('BarcodeDetector' in window) {
                    new BarcodeDetector({ formats: ['qr_code'] })
                        .detect(canvas)
                        .then(codes => {
                            if (codes.length) { running = false; onResult(codes[0].rawValue); }
                            else requestAnimationFrame(tick);
                        });
                } else {
                    // Fallback: ImageData to server
                    canvas.toBlob(blob => {
                        const form = new FormData();
                        form.append('image', blob, 'qr.jpg');
                        Http.upload('/qr/scan', form)
                            .then(res => { if (res.data) { running = false; onResult(res.data); } else requestAnimationFrame(tick); })
                            .catch(() => requestAnimationFrame(tick));
                    });
                }
            } catch { requestAnimationFrame(tick); }
        }
        requestAnimationFrame(tick);
    }

    async function _handleQRResult(data) {
        try {
            // Extract username or id from URL
            const match = data.match(/\/add\/([^/?#]+)/);
            const identifier = match?.[1] || data;
            await _sendContactRequest(null, identifier);
            Modal.close('modalAddContact');
        } catch { Toast.error(I18n.t('error.qr_invalid')); }
    }

    async function _shareQR(imgUrl, link) {
        if (navigator.share) {
            try {
                await navigator.share({ title: I18n.t('contacts.my_qr'), url: link });
                return;
            } catch { /* fall through */ }
        }
        Utils.copyText(link);
    }

    /* ──────────────────────────────────────────────────────────
       14. INVITE LINK
    ────────────────────────────────────────────────────────── */
    function _bindInviteLink(container) {
        const wrap   = container.querySelector('#inviteLinkWrap');
        const copyBtn= container.querySelector('#copyInviteLink');
        const shareBtn= container.querySelector('#shareInviteLink');

        const user   = App.getUser();
        const link   = `${location.origin}/add/${user?.username || user?.id}`;

        if (wrap) wrap.textContent = link;

        copyBtn?.addEventListener('click', () => Utils.copyText(link));

        shareBtn?.addEventListener('click', async () => {
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: I18n.t('contacts.invite_title'),
                        text:  I18n.t('contacts.invite_text', { name: user?.name }),
                        url:   link,
                    });
                } catch { Utils.copyText(link); }
            } else {
                Utils.copyText(link);
            }
        });

        // Social share buttons
        const socials = [
            { name: 'whatsapp', url: `https://wa.me/?text=${encodeURIComponent(link)}` },
            { name: 'telegram', url: `https://t.me/share/url?url=${encodeURIComponent(link)}` },
            { name: 'sms',      url: `sms:?body=${encodeURIComponent(link)}` },
            { name: 'email',    url: `mailto:?body=${encodeURIComponent(link)}` },
        ];

        const socialWrap = container.querySelector('#inviteSocials');
        if (socialWrap) {
            socialWrap.innerHTML = socials.map(s => `
            <a class="social-share-btn social-share-btn--${s.name}"
               href="${s.url}" target="_blank" rel="noopener noreferrer"
               aria-label="${s.name}">
               ${_socialIcon(s.name)}
            </a>`).join('');
        }
    }

    function _socialIcon(name) {
        const icons = {
            whatsapp: '💬',
            telegram: '✈️',
            sms:      '📱',
            email:    '📧',
        };
        return icons[name] || '🔗';
    }

    /* ──────────────────────────────────────────────────────────
       15. CONTACT REQUESTS MODAL
    ────────────────────────────────────────────────────────── */
    function _openRequestsModal() {
        const m = Modal.open('modalContactRequests');
        if (!m) return;

        const list = m.el.querySelector('#requestsList');
        if (!list) return;

        if (!_state.requests.length) {
            list.innerHTML = `<div class="empty-state">${I18n.t('contacts.no_requests')}</div>`;
            return;
        }

        list.innerHTML = _state.requests.map(req => `
        <div class="request-item" data-req-id="${req.id}" data-user-id="${req.from_user.id}">
            ${req.from_user.avatar
            ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(req.from_user.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(req.from_user.name)}">${Avatar.initials(req.from_user.name)}</div>`}
            <div class="request-item__info">
                <div class="request-item__name">${Utils.escapeHtml(req.from_user.name)}</div>
                <div class="request-item__sub">${Utils.escapeHtml(req.from_user.username ? '@'+req.from_user.username : req.from_user.phone || '')}</div>
                <div class="request-item__time">${I18n.formatRelative(req.created_at)}</div>
            </div>
            <div class="request-item__actions">
                <button class="btn btn--sm btn--primary request-accept" data-req-id="${req.id}">
                    ${I18n.t('contacts.accept')}
                </button>
                <button class="btn btn--sm btn--ghost request-decline" data-req-id="${req.id}">
                    ${I18n.t('contacts.decline')}
                </button>
            </div>
        </div>
    `).join('');

        Utils.delegate(list, '.request-accept', 'click', async (e, btn) => {
            const reqId = btn.dataset.reqId;
            try {
                const res = await Http.post(`/contacts/requests/${reqId}/accept`);
                _upsertContact(res.contact);
                _state.requests = _state.requests.filter(r => r.id !== reqId);
                btn.closest('.request-item').remove();
                _renderRequestsBadge();
                Toast.success(I18n.t('contacts.accepted'));
            } catch { Toast.error(I18n.t('error.generic')); }
        });

        Utils.delegate(list, '.request-decline', 'click', async (e, btn) => {
            const reqId = btn.dataset.reqId;
            try {
                await Http.post(`/contacts/requests/${reqId}/decline`);
                _state.requests = _state.requests.filter(r => r.id !== reqId);
                btn.closest('.request-item').remove();
                _renderRequestsBadge();
            } catch { Toast.error(I18n.t('error.generic')); }
        });
    }

    /* ──────────────────────────────────────────────────────────
       16. FAVORITE TOGGLE
    ────────────────────────────────────────────────────────── */
    async function _toggleFavorite(contact) {
        const isFav = _state.favorites.has(contact.id);
        try {
            if (isFav) {
                await Http.delete(`/contacts/${contact.id}/favorite`);
                _state.favorites.delete(contact.id);
                Toast.info(I18n.t('contacts.unfaved'));
            } else {
                await Http.post('/contacts/favorite', { contact_id: contact.id });
                _state.favorites.add(contact.id);
                Toast.success(I18n.t('contacts.faved'));
            }
            _applyFilter();
            _buildGroups();
            _renderList();
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    /* ──────────────────────────────────────────────────────────
       17. NICKNAME
    ────────────────────────────────────────────────────────── */
    function _setNickname(contact) {
        const m = Modal.open('modalNickname', { name: contact.name });
        if (!m) return;

        const input = m.el.querySelector('#nicknameInput');
        if (input) {
            input.value = contact.nickname || '';
            input.focus();
        }

        m.el.querySelector('#nicknameSave')?.addEventListener('click', async () => {
            const nickname = input?.value.trim() || '';
            try {
                await Http.patch(`/contacts/${contact.id}`, { nickname });
                contact.nickname = nickname;
                _applyFilter();
                _buildGroups();
                _renderList();
                Modal.close('modalNickname');
                Toast.success(I18n.t('contacts.nickname_saved'));
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       18. SHARE CONTACT
    ────────────────────────────────────────────────────────── */
    async function _shareContact(contact) {
        const link = `${location.origin}/add/${contact.username || contact.id}`;
        if (navigator.share) {
            try {
                await navigator.share({ title: contact.name, url: link });
                return;
            } catch { /* fall through */ }
        }
        Utils.copyText(link);
    }

    /* ──────────────────────────────────────────────────────────
       19. BLOCK CONTACT
    ────────────────────────────────────────────────────────── */
    async function _blockContact(contact) {
        try {
            await Http.post('/users/block', { user_id: contact.id });
            _state.all = _state.all.filter(c => c.id !== contact.id);
            _applyFilter();
            _buildGroups();
            _renderList();
            Toast.success(I18n.t('blocked'), {
                action: {
                    label: I18n.t('undo'),
                    fn: async () => {
                        await Http.delete(`/users/${contact.id}/block`);
                        _upsertContact(contact);
                        Toast.info(I18n.t('unblocked'));
                    }
                }
            });
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    /* ──────────────────────────────────────────────────────────
       20. DELETE CONTACT
    ────────────────────────────────────────────────────────── */
    function _promptDelete(contact) {
        const m = Modal.open('modalConfirm', {
            title:   I18n.t('contacts.delete'),
            message: I18n.t('contacts.delete_confirm', { name: contact.name }),
        });
        if (!m) return;

        m.el.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            Modal.close('modalConfirm');
            try {
                await Http.delete(`/contacts/${contact.id}`);
                _state.all = _state.all.filter(c => c.id !== contact.id);
                _state.favorites.delete(contact.id);
                _applyFilter();
                _buildGroups();
                _renderList();
                Toast.success(I18n.t('contacts.deleted'));
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       21. MULTI-SELECT MODE
    ────────────────────────────────────────────────────────── */
    function _enterSelectMode() {
        _state.selectMode = true;
        _state.selectedIds.clear();
        document.body.classList.add('contacts-select-mode');
        const bar = D.selectBar;
        if (bar) { bar.hidden = false; }
        _updateSelectBar();
    }

    function _exitSelectMode() {
        _state.selectMode = false;
        _state.selectedIds.clear();
        document.body.classList.remove('contacts-select-mode');
        const bar = D.selectBar;
        if (bar) bar.hidden = true;
        Utils.qsa('.contact-row--selected').forEach(r => r.classList.remove('contact-row--selected'));
    }

    function _toggleSelect(id, row) {
        if (_state.selectedIds.has(id)) {
            _state.selectedIds.delete(id);
            row.classList.remove('contact-row--selected');
            row.querySelector('.contact-row__check').innerHTML = '';
        } else {
            _state.selectedIds.add(id);
            row.classList.add('contact-row--selected');
            row.querySelector('.contact-row__check').innerHTML =
                `<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
             <polyline points="22 4 12 14.01 9 11.01"/></svg>`;
        }
        _updateSelectBar();
        if (!_state.selectedIds.size) _exitSelectMode();
    }

    function _updateSelectBar() {
        const count = D.selectCount;
        if (count) count.textContent = I18n.plural('selected', _state.selectedIds.size);
    }

    function _bindSelectBar() {
        Utils.delegate(document, '[data-action="select-cancel"]', 'click', () => _exitSelectMode());

        Utils.delegate(document, '[data-action="select-new-group"]', 'click', async () => {
            if (!_state.selectedIds.size) return;
            _exitSelectMode();
            _openNewGroupModal([..._state.selectedIds]);
        });

        Utils.delegate(document, '[data-action="select-delete"]', 'click', () => {
            if (!_state.selectedIds.size) return;
            const m = Modal.open('modalConfirm', {
                title:   I18n.t('contacts.delete_selected'),
                message: I18n.plural('contacts.delete_n', _state.selectedIds.size),
            });
            m?.el.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
                Modal.close('modalConfirm');
                const ids = [..._state.selectedIds];
                await Promise.allSettled(ids.map(id => Http.delete(`/contacts/${id}`)));
                _state.all = _state.all.filter(c => !ids.includes(c.id));
                _applyFilter();
                _buildGroups();
                _renderList();
                _exitSelectMode();
                Toast.success(I18n.plural('contacts.deleted_n', ids.length));
            }, { once: true });
        });
    }

    /* ──────────────────────────────────────────────────────────
       22. NEW GROUP FLOW
    ────────────────────────────────────────────────────────── */
    function _openNewGroupModal(preSelected = []) {
        const m = Modal.open('modalNewGroup');
        if (!m) return;

        const selected = new Set(preSelected);
        const input    = m.el.querySelector('#groupSearch');
        const list     = m.el.querySelector('#groupMemberList');
        const chips    = m.el.querySelector('#groupSelectedChips');
        const nextBtn  = m.el.querySelector('#groupNextBtn');

        function _renderPicker(contacts) {
            if (!list) return;
            list.innerHTML = contacts.map(c => {
                const isSel = selected.has(c.id);
                return `<div class="contact-picker-item${isSel ? ' contact-picker-item--selected' : ''}" data-id="${c.id}">
                ${c.avatar
                    ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(c.avatar)}" alt="">`
                    : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(c.name)}">${Avatar.initials(c.name)}</div>`}
                <div class="contact-picker-item__info">
                    <div class="contact-picker-item__name">${Utils.escapeHtml(c.name)}</div>
                    <div class="contact-picker-item__sub">${Utils.escapeHtml(c.username ? '@'+c.username : '')}</div>
                </div>
                <span class="contact-picker-item__check"></span>
            </div>`;
            }).join('');

            Utils.delegate(list, '.contact-picker-item', 'click', (e, el) => {
                const id = el.dataset.id;
                if (selected.has(id)) { selected.delete(id); el.classList.remove('contact-picker-item--selected'); }
                else                  { selected.add(id);    el.classList.add('contact-picker-item--selected');    }
                _renderChips();
                if (nextBtn) nextBtn.disabled = selected.size < 1;
            });
        }

        function _renderChips() {
            if (!chips) return;
            chips.innerHTML = [...selected].map(id => {
                const c = _state.all.find(x => x.id === id);
                if (!c) return '';
                return `<div class="chip" data-id="${id}">
                <span>${Utils.escapeHtml(c.nickname || c.name)}</span>
                <button class="chip__remove" data-id="${id}">×</button>
            </div>`;
            }).join('');

            Utils.delegate(chips, '.chip__remove', 'click', (e, btn) => {
                const id = btn.dataset.id;
                selected.delete(id);
                chips.querySelector(`.chip[data-id="${id}"]`)?.remove();
                list?.querySelector(`.contact-picker-item[data-id="${id}"]`)
                    ?.classList.remove('contact-picker-item--selected');
                if (nextBtn) nextBtn.disabled = !selected.size;
            });
        }

        _renderPicker(_state.all);
        _renderChips();
        if (nextBtn) nextBtn.disabled = selected.size < 1;

        input?.addEventListener('input', Utils.debounce(() => {
            const q = input.value.toLowerCase();
            const filtered = q
                ? _state.all.filter(c => c.name.toLowerCase().includes(q))
                : _state.all;
            _renderPicker(filtered);
        }, 200));

        // Step 2: name & avatar
        nextBtn?.addEventListener('click', () => {
            if (selected.size < 1) return;
            _showGroupInfoStep(m.el, [...selected]);
        });
    }

    function _showGroupInfoStep(container, memberIds) {
        const step1 = container.querySelector('[data-group-step="1"]');
        const step2 = container.querySelector('[data-group-step="2"]');
        if (step1) step1.hidden = true;
        if (step2) step2.hidden = false;

        // Avatar picker
        const avatarBtn = container.querySelector('.group-avatar-btn');
        let _avatarBlob = null;
        avatarBtn?.addEventListener('click', () => {
            const inp = document.createElement('input');
            inp.type  = 'file';
            inp.accept= 'image/*';
            inp.addEventListener('change', async () => {
                const file = inp.files?.[0];
                if (!file || !Utils.isImage(file)) return;
                _avatarBlob = await Utils.resizeImage(file, 400, 400, 0.9);
                const url   = URL.createObjectURL(_avatarBlob);
                const img   = avatarBtn.querySelector('img') || Utils.el('img', { alt: 'avatar' });
                img.src     = url;
                avatarBtn.innerHTML = '';
                avatarBtn.appendChild(img);
            });
            inp.click();
        });

        // Submit
        const createBtn  = container.querySelector('#createGroupBtn');
        const nameInput  = container.querySelector('#groupNameInput');
        const descInput  = container.querySelector('#groupDescInput');

        createBtn?.addEventListener('click', async () => {
            const name = nameInput?.value?.trim();
            if (!name) { Toast.error(I18n.t('contacts.group_name_required')); return; }

            createBtn.disabled = true;
            try {
                const form = new FormData();
                form.append('name',        name);
                form.append('description', descInput?.value?.trim() || '');
                form.append('member_ids',  JSON.stringify(memberIds));
                if (_avatarBlob) form.append('avatar', _avatarBlob, 'avatar.jpg');

                const group = await Http.upload('/chats/group', form);
                Modal.close('modalNewGroup');
                Router.navigate(`/chat/${group.id}`);
                Toast.success(I18n.t('contacts.group_created'));
            } catch { Toast.error(I18n.t('error.generic')); }
            finally { createBtn.disabled = false; }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       23. PHONE CONTACTS SYNC
    ────────────────────────────────────────────────────────── */
    async function syncPhoneContacts() {
        if (_state.syncing) return;
        if (!('contacts' in navigator && 'ContactsManager' in window)) {
            Toast.warning(I18n.t('contacts.sync_not_supported'));
            return;
        }

        try {
            const props     = ['name', 'tel', 'email'];
            const contacts  = await navigator.contacts.select(props, { multiple: true });
            if (!contacts.length) return;

            _state.syncing = true;
            const btn = D.syncBtn;
            if (btn) btn.classList.add('spinning');

            const payload = contacts.map(c => ({
                name:  (c.name || [])[0] || '',
                phone: (c.tel  || [])[0] || '',
                email: (c.email|| [])[0] || '',
            })).filter(c => c.phone || c.email);

            const res = await Http.post('/contacts/sync', { contacts: payload });
            const matched = res.matched || [];
            const imported= res.imported|| 0;

            matched.forEach(c => _upsertContact(c));
            _applyFilter();
            _buildGroups();
            _renderList();

            Toast.success(I18n.t('contacts.sync_done', { count: imported }));
        } catch(e) {
            if (e.name !== 'AbortError') Toast.error(I18n.t('error.generic'));
        } finally {
            _state.syncing = false;
            const btn = D.syncBtn;
            if (btn) btn.classList.remove('spinning');
        }
    }

    /* ──────────────────────────────────────────────────────────
       24. IMPORT / EXPORT
    ────────────────────────────────────────────────────────── */
    function importContacts() {
        const input  = document.createElement('input');
        input.type   = 'file';
        input.accept = '.vcf,.csv,.json';
        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            if (!file) return;
            const form = new FormData();
            form.append('file', file);
            const t = Toast.loading(I18n.t('contacts.importing'));
            try {
                const res = await Http.upload('/contacts/import', form);
                t.dismiss();
                Toast.success(I18n.t('contacts.import_done', { count: res.imported || 0 }));
                load(true);
            } catch { t.dismiss(); Toast.error(I18n.t('error.import_failed')); }
        });
        input.click();
    }

    async function exportContacts(format = 'vcf') {
        const t = Toast.loading(I18n.t('contacts.exporting'));
        try {
            const res = await Http.post('/contacts/export', { format });
            t.dismiss();
            const a   = document.createElement('a');
            a.href    = res.url;
            a.download= `contacts_${Date.now()}.${format}`;
            a.click();
        } catch { t.dismiss(); Toast.error(I18n.t('error.generic')); }
    }

    /* ──────────────────────────────────────────────────────────
       25. UPSERT CONTACT HELPER
    ────────────────────────────────────────────────────────── */
    function _upsertContact(contact) {
        const idx = _state.all.findIndex(c => c.id === contact.id);
        if (idx > -1) _state.all[idx] = { ..._state.all[idx], ...contact };
        else          _state.all.unshift(contact);
        _applyFilter();
        _buildGroups();
        _renderList();
    }

    /* ──────────────────────────────────────────────────────────
       26. SEARCH
    ────────────────────────────────────────────────────────── */
    function _bindSearch() {
        const input = D.searchInput;
        const clear = D.searchClear;
        if (!input) return;

        input.addEventListener('input', Utils.debounce(() => {
            _state.searchQuery = input.value.trim();
            if (clear) clear.hidden = !_state.searchQuery;
            _applyFilter();
            _buildGroups();
            _renderList();
        }, 250));

        clear?.addEventListener('click', () => {
            input.value        = '';
            _state.searchQuery = '';
            clear.hidden       = true;
            _applyFilter();
            _buildGroups();
            _renderList();
        });

        input.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                input.value        = '';
                _state.searchQuery = '';
                if (clear) clear.hidden = true;
                input.blur();
                _applyFilter();
                _buildGroups();
                _renderList();
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       27. FILTER BAR
    ────────────────────────────────────────────────────────── */
    function _bindFilterBar() {
        Utils.delegate(document, '.contacts-filter-chip', 'click', (e, el) => {
            Utils.qsa('.contacts-filter-chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            _state.filter = el.dataset.filter || 'all';
            _applyFilter();
            _buildGroups();
            _renderList();
        });
    }

    /* ──────────────────────────────────────────────────────────
       28. SORT
    ────────────────────────────────────────────────────────── */
    function _bindSort() {
        D.sortBtn?.addEventListener('click', e => {
            const menu = Utils.qs('.contacts-sort-menu') || _buildSortMenu();
            menu.hidden = !menu.hidden;
            if (!menu.hidden) {
                const rect = D.sortBtn.getBoundingClientRect();
                menu.style.cssText = `top:${rect.bottom+4}px;right:${window.innerWidth - rect.right}px`;
            }
        });
    }

    function _buildSortMenu() {
        const menu = Utils.el('div', { class: 'ctx-menu contacts-sort-menu' });
        document.body.appendChild(menu);

        const sorts = [
            { label: I18n.t('contacts.sort_name_az'),  key: 'name_az'   },
            { label: I18n.t('contacts.sort_name_za'),  key: 'name_za'   },
            { label: I18n.t('contacts.sort_recent'),   key: 'recent'    },
            { label: I18n.t('contacts.sort_online'),   key: 'online'    },
        ];

        sorts.forEach(s => {
            const item = Utils.el('div', { class: 'ctx-menu-item', text: s.label });
            item.addEventListener('click', () => {
                menu.hidden = true;
                _sortContacts(s.key);
            });
            menu.appendChild(item);
        });

        setTimeout(() => document.addEventListener('click', () => menu.hidden = true, { once: true }), 0);
        return menu;
    }

    function _sortContacts(key) {
        switch (key) {
            case 'name_az':
                _state.all.sort((a,b) => (a.name||'').localeCompare(b.name||'', I18n.getLocale()));  break;
            case 'name_za':
                _state.all.sort((a,b) => (b.name||'').localeCompare(a.name||'', I18n.getLocale()));  break;
            case 'recent':
                _state.all.sort((a,b) => new Date(b.last_interaction||0) - new Date(a.last_interaction||0)); break;
            case 'online':
                _state.all.sort((a,b) => {
                    const sa = Socket.Presence.get(a.id)?.status === 'online' ? 1 : 0;
                    const sb = Socket.Presence.get(b.id)?.status === 'online' ? 1 : 0;
                    return sb - sa;
                }); break;
        }
        _applyFilter();
        _buildGroups();
        _renderList();
    }

    /* ──────────────────────────────────────────────────────────
       29. INFINITE SCROLL
    ────────────────────────────────────────────────────────── */
    function _bindScroll() {
        const page = D.page;
        if (!page) return;
        page.addEventListener('scroll', Utils.throttle(() => {
            const near = page.scrollHeight - page.scrollTop - page.clientHeight < 100;
            if (near && _state.hasMore && !_state.loading) load();
        }, 300));
    }

    /* ──────────────────────────────────────────────────────────
       30. REALTIME UPDATES
    ────────────────────────────────────────────────────────── */
    function _bindSocket() {
        EventBus.on('presence:update', ({ userId, status }) => {
            if (_state.filter !== 'online') return;
            const row = D.list?.querySelector(`.contact-row[data-id="${userId}"]`);
            if (!row) return;
            const sub = row.querySelector('.contact-row__sub');
            if (sub) {
                sub.textContent    = status === 'online' ? I18n.t('online') : '';
                sub.dataset.status = status;
            }
            const dot = row.querySelector('.avatar__online');
            if (dot) dot.hidden = status !== 'online';
        });

        // New incoming contact request via WebSocket
        EventBus.on('ws:contact_request', msg => {
            _state.requests.unshift(msg.request);
            _renderRequestsBadge();
            Toast.info(I18n.t('contacts.new_request', { name: msg.request.from_user.name }), {
                action: { label: I18n.t('view'), fn: () => _openRequestsModal() }
            });
        });

        // Contact accepted
        EventBus.on('ws:contact_accepted', msg => {
            _upsertContact(msg.contact);
            Toast.success(I18n.t('contacts.request_accepted', { name: msg.contact.name }));
        });
    }

    /* ──────────────────────────────────────────────────────────
       31. LONG PRESS HELPER
    ────────────────────────────────────────────────────────── */
    function _bindLongPress(el, fn, duration = 500) {
        let timer = null;
        const cancel = () => { clearTimeout(timer); };
        el.addEventListener('pointerdown', e => {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            timer = setTimeout(() => { navigator.vibrate?.(25); fn(e); }, duration);
        });
        ['pointerup','pointerleave','pointermove'].forEach(evt =>
            el.addEventListener(evt, cancel)
        );
        el.addEventListener('contextmenu', e => { e.preventDefault(); cancel(); });
    }

    /* ──────────────────────────────────────────────────────────
       32. SKELETON / EMPTY / ERROR
    ────────────────────────────────────────────────────────── */
    function _renderSkeleton() {
        const list = D.list;
        if (!list) return;
        list.innerHTML = Array.from({ length: 12 }, () => `
        <div class="contact-skeleton">
            <div class="contact-skeleton__avatar"></div>
            <div class="contact-skeleton__body">
                <div class="contact-skeleton__name"></div>
                <div class="contact-skeleton__sub"></div>
            </div>
        </div>
    `).join('');
    }

    function _renderError(e) {
        const list = D.list;
        if (!list) return;
        list.innerHTML = `
        <div class="contacts-error">
            <div class="contacts-error__icon">⚠️</div>
            <div class="contacts-error__title">${I18n.t('error.load_contacts')}</div>
            <button class="btn btn--primary btn--sm" onclick="Contacts.load(true)">
                ${I18n.t('retry')}
            </button>
        </div>`;
    }

    function _showEmpty() {
        const el = D.emptyState;
        if (el) el.hidden = false;
        if (D.list) D.list.innerHTML = '';
    }

    function _hideEmpty() {
        const el = D.emptyState;
        if (el) el.hidden = true;
    }

    /* ──────────────────────────────────────────────────────────
       33. FAB ACTIONS
    ────────────────────────────────────────────────────────── */
    function _bindFAB() {
        D.fabAdd?.addEventListener('click',   () => openAddModal());
        D.fabGroup?.addEventListener('click', () => _openNewGroupModal());
        D.syncBtn?.addEventListener('click',  () => syncPhoneContacts());
    }

    /* ──────────────────────────────────────────────────────────
       34. ROUTE HANDLER
    ────────────────────────────────────────────────────────── */
    function _bindRoutes() {
        EventBus.on('page:contacts', () => {
            load(true);
        });

        // Handle /add/:username deep-link
        Router.define('/add/:identifier', ({ identifier }) => {
            openAddModal();
            const input = Utils.qs('#addContactSearch');
            if (input) { input.value = identifier; input.dispatchEvent(new Event('input')); }
        });

        // Handle contact add from chat message
        EventBus.on('contact:addFromMsg', contact => {
            openAddModal();
            // pre-fill
            const input = Utils.qs('#addContactSearch');
            if (input) { input.value = contact.phone || contact.username || ''; input.dispatchEvent(new Event('input')); }
        });
    }

    /* ──────────────────────────────────────────────────────────
       35. INIT
    ────────────────────────────────────────────────────────── */
    function init() {
        _bindSearch();
        _bindFilterBar();
        _bindSort();
        _bindFAB();
        _bindSelectBar();
        _bindScroll();
        _bindSocket();
        _bindRoutes();

        EventBus.on('ui:escape', () => {
            if (_state.selectMode) { _exitSelectMode(); return; }
        });

        // Global shortcut
        Shortcuts.register('ctrl+shift+n', () => openAddModal());

        console.log('[Contacts] Initialized');
    }

    /* ──────────────────────────────────────────────────────────
       PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        init,
        load,
        openAddModal,
        syncPhoneContacts,
        importContacts,
        exportContacts,
        get all()       { return [..._state.all]; },
        get favorites() { return new Set(_state.favorites); },
    };

})();

window.Contacts = Contacts;

/* ============================================================
   PROFILE.JS  —  Namak Messenger
   User profile, group info, shared media, members list,
   block/report, edit profile, mutual groups, story viewer
   ============================================================ */

'use strict';

const Profile = (() => {

    /* ──────────────────────────────────────────────────────────
       1. STATE
    ────────────────────────────────────────────────────────── */
    const _state = {
        currentId:    null,    // userId or chatId being viewed
        type:         null,    // 'user' | 'group' | 'channel'
        data:         null,    // full profile object
        media:        [],      // shared media
        docs:         [],      // shared documents
        links:        [],      // shared links
        members:      [],      // group/channel members
        mutualGroups: [],      // mutual groups with user
        mediaPage:    1,
        hasMoreMedia: true,
        memberPage:   1,
        hasMoreMembers: true,
        activeTab:    'media', // media | docs | links | members
        loading:      false,
        myId:         null,
    };

    const MEDIA_PAGE_SIZE   = 24;
    const MEMBER_PAGE_SIZE  = 30;

    /* ──────────────────────────────────────────────────────────
       2. DOM REFS
    ────────────────────────────────────────────────────────── */
    const D = {
        get panel()           { return Utils.qs('.profile-panel'); },
        get header()          { return Utils.qs('.profile-header'); },
        get avatar()          { return Utils.qs('.profile-avatar-wrap'); },
        get avatarImg()       { return Utils.qs('.profile-avatar'); },
        get name()            { return Utils.qs('.profile-name'); },
        get status()          { return Utils.qs('.profile-status'); },
        get bio()             { return Utils.qs('.profile-bio'); },
        get username()        { return Utils.qs('.profile-username'); },
        get phone()           { return Utils.qs('.profile-phone'); },
        get actionsBar()      { return Utils.qs('.profile-actions'); },
        get tabs()            { return Utils.qs('.profile-tabs'); },
        get mediaGrid()       { return Utils.qs('.profile-media-grid'); },
        get docList()         { return Utils.qs('.profile-doc-list'); },
        get linkList()        { return Utils.qs('.profile-link-list'); },
        get memberList()      { return Utils.qs('.profile-member-list'); },
        get mutualGroups()    { return Utils.qs('.profile-mutual-groups'); },
        get backBtn()         { return Utils.qs('.profile-panel__back'); },
        get editBtn()         { return Utils.qs('[data-action="edit-profile"]'); },
        get closeBtn()        { return Utils.qs('.profile-panel__close'); },
        get notifBtn()        { return Utils.qs('[data-action="toggle-notif"]'); },
        get blockBtn()        { return Utils.qs('[data-action="block-user"]'); },
        get reportBtn()       { return Utils.qs('[data-action="report-user"]'); },
        get leaveBtn()        { return Utils.qs('[data-action="leave-group"]'); },
        get addMemberBtn()    { return Utils.qs('[data-action="add-member"]'); },
        get mediaCount()      { return Utils.qs('.profile-tab-media .tab-count'); },
        get docCount()        { return Utils.qs('.profile-tab-docs .tab-count'); },
        get linkCount()       { return Utils.qs('.profile-tab-links .tab-count'); },
        get memberCount()     { return Utils.qs('.profile-tab-members .tab-count'); },
        get loadMoreMedia()   { return Utils.qs('.profile-media-loadmore'); },
    };

    /* ──────────────────────────────────────────────────────────
       3. OPEN PROFILE
    ────────────────────────────────────────────────────────── */
    async function open(id, type = 'user') {
        if (_state.loading) return;
        _state.currentId    = id;
        _state.type         = type;
        _state.myId         = App.getUser()?.id;
        _state.media        = [];
        _state.docs         = [];
        _state.links        = [];
        _state.members      = [];
        _state.mutualGroups = [];
        _state.mediaPage    = 1;
        _state.hasMoreMedia = true;
        _state.memberPage   = 1;
        _state.activeTab    = type === 'group' || type === 'channel' ? 'members' : 'media';
        _state.loading      = true;

        _showPanel();
        _renderSkeleton();

        try {
            const endpoint = type === 'user'
                ? `/users/${id}/profile`
                : `/chats/${id}/info`;

            const [profile, shared] = await Promise.all([
                Http.get(endpoint),
                Http.get(`/${type === 'user' ? 'users' : 'chats'}/${id}/shared?limit=${MEDIA_PAGE_SIZE}`),
            ]);

            _state.data         = profile;
            _state.media        = shared.media    || [];
            _state.docs         = shared.docs     || [];
            _state.links        = shared.links    || [];
            _state.hasMoreMedia = shared.has_more;

            if (type === 'group' || type === 'channel') {
                await _loadMembers(true);
            } else {
                const mutual = await Http.get(`/users/${id}/mutual-groups`).catch(() => ({ groups: [] }));
                _state.mutualGroups = mutual.groups || [];
            }

            _renderProfile();
            _renderTabs();
            _renderActiveTab();

        } catch(e) {
            _renderError(e);
        } finally {
            _state.loading = false;
        }
    }

    function close() {
        const panel = D.panel;
        if (!panel) return;
        panel.classList.remove('profile-panel--open');
        panel.setAttribute('aria-hidden', 'true');
        setTimeout(() => { if (panel) panel.hidden = true; }, 280);
        EventBus.emit('profile:closed');
    }

    function _showPanel() {
        const panel = D.panel;
        if (!panel) return;
        panel.hidden = false;
        panel.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => panel.classList.add('profile-panel--open'));
    }

    /* ──────────────────────────────────────────────────────────
       4. RENDER PROFILE HEADER
    ────────────────────────────────────────────────────────── */
    function _renderProfile() {
        const p = _state.data;
        if (!p) return;

        // Avatar
        if (D.avatar) {
            D.avatar.innerHTML = '';
            const av = Avatar.render(
                p.name || p.title,
                p.avatar,
                80,
                p.status === 'online'
            );
            av.addEventListener('click', () => {
                if (p.avatar) _openAvatarViewer(p.avatar, p.name || p.title);
            });
            D.avatar.appendChild(av);

            // Story ring
            if (p.has_story) D.avatar.classList.add('has-story');
            if (p.has_story) {
                D.avatar.addEventListener('click', () => _openStory(p.id), { once: true });
            }
        }

        // Name
        if (D.name) {
            D.name.innerHTML = Utils.escapeHtml(p.name || p.title || '');
            if (p.is_verified) {
                D.name.insertAdjacentHTML('beforeend',
                    `<svg class="verified-badge" viewBox="0 0 24 24">
                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                 </svg>`);
            }
        }

        // Status / member count
        if (D.status) {
            if (_state.type === 'user') {
                const presence = Socket.Presence.get(p.id);
                const status   = presence.status || p.status;
                D.status.textContent    = status === 'online'
                    ? I18n.t('online')
                    : p.last_seen
                        ? I18n.t('last_seen') + ' ' + I18n.formatRelative(p.last_seen)
                        : I18n.t('offline');
                D.status.dataset.status = status;
            } else {
                D.status.textContent = I18n.plural('members', p.member_count || 0);
            }
        }

        // Bio / description
        if (D.bio) {
            D.bio.innerHTML  = p.bio || p.description
                ? Utils.parseLinks(Utils.escapeHtml(p.bio || p.description))
                : '';
            D.bio.hidden     = !p.bio && !p.description;
        }

        // Username
        if (D.username) {
            D.username.textContent = p.username ? '@' + p.username : '';
            D.username.hidden      = !p.username;
            if (p.username) {
                D.username.addEventListener('click', () => Utils.copyText('@' + p.username), { once: false });
                D.username.title = I18n.t('click_to_copy');
            }
        }

        // Phone
        if (D.phone) {
            D.phone.textContent = p.phone || '';
            D.phone.hidden      = !p.phone;
        }

        // Actions bar
        _renderActions(p);
    }

    /* ──────────────────────────────────────────────────────────
       5. ACTION BUTTONS
    ────────────────────────────────────────────────────────── */
    function _renderActions(p) {
        const bar = D.actionsBar;
        if (!bar) return;
        bar.innerHTML = '';

        const isMe    = p.id === _state.myId;
        const isGroup = _state.type === 'group' || _state.type === 'channel';

        if (isMe) {
            // Edit profile
            _addActionBtn(bar, 'edit',   I18n.t('edit_profile'),  () => openEditModal());
        } else if (!isGroup) {
            // DM / Call / Video
            _addActionBtn(bar, 'chat',   I18n.t('message'),   () => _openDM(p));
            _addActionBtn(bar, 'call',   I18n.t('call'),      () => _startCall(p, 'audio'));
            _addActionBtn(bar, 'video',  I18n.t('video'),     () => _startCall(p, 'video'));
            _addActionBtn(bar, 'mute',   p.is_muted ? I18n.t('unmute') : I18n.t('mute'), () => _toggleMute(p));
        } else {
            // Group actions
            _addActionBtn(bar, 'mute',  p.is_muted ? I18n.t('unmute') : I18n.t('mute'), () => _toggleMute(p));
            if (p.my_role === 'admin' || p.my_role === 'owner') {
                _addActionBtn(bar, 'edit', I18n.t('edit_group'), () => openEditGroupModal());
            }
        }

        // Notification toggle
        const notifActive = !p.is_muted;
        const notifBtn = _addActionBtn(bar, 'notif', notifActive ? I18n.t('mute_notif') : I18n.t('unmute_notif'),
            () => _toggleMute(p));
        if (!notifActive) notifBtn.classList.add('action-btn--inactive');
    }

    function _addActionBtn(bar, icon, label, fn) {
        const btn = Utils.el('button', {
            class:        'action-btn',
            'aria-label': label,
            title:        label,
        });
        btn.innerHTML = `
        <span class="action-btn__icon">${_profileIcon(icon)}</span>
        <span class="action-btn__label">${Utils.escapeHtml(label)}</span>`;
        btn.addEventListener('click', fn);
        bar.appendChild(btn);
        return btn;
    }

    function _profileIcon(name) {
        const icons = {
            edit:  '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            chat:  '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
            call:  '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.69A2 2 0 012 .84h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>',
            video: '<svg viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
            mute:  '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
            notif: '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
            add:   '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
            leave: '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
            block: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
            report:'<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        };
        return icons[name] || '';
    }

    /* ──────────────────────────────────────────────────────────
       6. TABS
    ────────────────────────────────────────────────────────── */
    function _renderTabs() {
        const tabs = D.tabs;
        if (!tabs) return;

        // Update counts
        if (D.mediaCount)  D.mediaCount.textContent  = _state.media.length  || '';
        if (D.docCount)    D.docCount.textContent    = _state.docs.length   || '';
        if (D.linkCount)   D.linkCount.textContent   = _state.links.length  || '';
        if (D.memberCount) D.memberCount.textContent = _state.members.length|| '';

        // Hide members tab for direct chats
        const memberTab = tabs.querySelector('.profile-tab-members');
        if (memberTab) {
            memberTab.hidden = _state.type === 'user';
        }

        // Set active
        tabs.querySelectorAll('.profile-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === _state.activeTab);
        });
    }

    function _bindTabs() {
        Utils.delegate(document, '.profile-tab', 'click', (e, el) => {
            const tab = el.dataset.tab;
            if (!tab || tab === _state.activeTab) return;

            Utils.qsa('.profile-tab').forEach(t => t.classList.remove('active'));
            el.classList.add('active');
            _state.activeTab = tab;
            _renderActiveTab();
        });
    }

    function _renderActiveTab() {
        switch(_state.activeTab) {
            case 'media':   _renderMediaGrid();   break;
            case 'docs':    _renderDocList();     break;
            case 'links':   _renderLinkList();    break;
            case 'members': _renderMemberList();  break;
        }
    }

    /* ──────────────────────────────────────────────────────────
       7. SHARED MEDIA GRID
    ────────────────────────────────────────────────────────── */
    function _renderMediaGrid() {
        const grid = D.mediaGrid;
        if (!grid) return;

        if (!_state.media.length) {
            grid.innerHTML = _tplEmpty('🖼️', I18n.t('empty.media'));
            return;
        }

        grid.innerHTML = '';
        const frag = document.createDocumentFragment();

        _state.media.forEach((item, idx) => {
            const el = Utils.el('div', {
                class:        `media-thumb media-thumb--${item.type}`,
                'data-index': idx,
            });

            if (item.type === 'image') {
                const img = Utils.el('img', {
                    src:     item.thumbnail_url || item.url,
                    alt:     '',
                    loading: 'lazy',
                });
                img.addEventListener('load', () => el.classList.add('loaded'));
                el.appendChild(img);
            } else if (item.type === 'video') {
                el.innerHTML = `
                <img src="${Utils.escapeHtml(item.thumbnail_url || '')}" alt="" loading="lazy">
                <span class="media-thumb__play">▶</span>
                <span class="media-thumb__dur">${item.duration ? _formatDur(item.duration) : ''}</span>`;
            }

            el.addEventListener('click', () => _openMediaViewer(idx));
            frag.appendChild(el);
        });

        grid.appendChild(frag);

        // Load more button
        if (_state.hasMoreMedia) {
            const btn = Utils.el('button', {
                class: 'profile-media-loadmore btn btn--ghost btn--sm',
                text:  I18n.t('load_more'),
            });
            btn.addEventListener('click', () => _loadMoreMedia());
            grid.after(btn);
        }
    }

    async function _loadMoreMedia() {
        if (!_state.hasMoreMedia) return;
        _state.mediaPage++;
        try {
            const endpoint = _state.type === 'user'
                ? `/users/${_state.currentId}/shared`
                : `/chats/${_state.currentId}/shared`;
            const res = await Http.get(`${endpoint}?page=${_state.mediaPage}&limit=${MEDIA_PAGE_SIZE}`);
            _state.media        = [..._state.media, ...(res.media || [])];
            _state.hasMoreMedia = res.has_more;
            _renderMediaGrid();
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    /* ──────────────────────────────────────────────────────────
       8. SHARED DOCS LIST
    ────────────────────────────────────────────────────────── */
    function _renderDocList() {
        const list = D.docList;
        if (!list) return;

        if (!_state.docs.length) {
            list.innerHTML = _tplEmpty('📎', I18n.t('empty.docs'));
            return;
        }

        list.innerHTML = _state.docs.map(doc => `
        <a class="shared-doc" href="${Utils.escapeHtml(doc.url)}" target="_blank" rel="noopener noreferrer">
            <div class="shared-doc__icon">${Utils.fileIcon(doc.name)}</div>
            <div class="shared-doc__info">
                <div class="shared-doc__name">${Utils.escapeHtml(doc.name)}</div>
                <div class="shared-doc__meta">
                    ${I18n.formatFileSize(doc.size || 0)}
                    · ${I18n.formatDate(doc.created_at, { day:'numeric', month:'short' })}
                </div>
            </div>
            <button class="shared-doc__dl"
                data-url="${Utils.escapeHtml(doc.url)}"
                data-name="${Utils.escapeHtml(doc.name)}"
                aria-label="${I18n.t('download')}">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
        </a>
    `).join('');

        Utils.delegate(list, '.shared-doc__dl', 'click', (e, btn) => {
            e.preventDefault();
            _downloadFile(btn.dataset.url, btn.dataset.name);
        });
    }

    /* ──────────────────────────────────────────────────────────
       9. SHARED LINKS LIST
    ────────────────────────────────────────────────────────── */
    function _renderLinkList() {
        const list = D.linkList;
        if (!list) return;

        if (!_state.links.length) {
            list.innerHTML = _tplEmpty('🔗', I18n.t('empty.links'));
            return;
        }

        list.innerHTML = _state.links.map(link => {
            const domain = (() => {
                try { return new URL(link.url).hostname.replace('www.',''); }
                catch { return link.url; }
            })();
            return `
        <a class="shared-link" href="${Utils.escapeHtml(link.url)}" target="_blank" rel="noopener noreferrer">
            ${link.og_image
                ? `<img class="shared-link__img" src="${Utils.escapeHtml(link.og_image)}" alt="" loading="lazy">`
                : `<div class="shared-link__img-placeholder">${_profileIcon('chat')}</div>`
            }
            <div class="shared-link__body">
                <div class="shared-link__title">${Utils.escapeHtml(link.og_title || link.url)}</div>
                <div class="shared-link__domain">${Utils.escapeHtml(domain)}</div>
                <div class="shared-link__date">${I18n.formatDate(link.created_at, { day:'numeric', month:'short' })}</div>
            </div>
        </a>`;
        }).join('');
    }

    /* ──────────────────────────────────────────────────────────
       10. MEMBER LIST  (groups & channels)
    ────────────────────────────────────────────────────────── */
    async function _loadMembers(reset = false) {
        if (reset) {
            _state.members    = [];
            _state.memberPage = 1;
            _state.hasMoreMembers = true;
        }
        if (!_state.hasMoreMembers) return;

        try {
            const res = await Http.get(
                `/chats/${_state.currentId}/members?page=${_state.memberPage}&limit=${MEMBER_PAGE_SIZE}`
            );
            _state.members = [..._state.members, ...(res.members || [])];
            _state.hasMoreMembers = res.has_more;
            _state.memberPage++;
        } catch { /* silent */ }
    }

    function _renderMemberList() {
        const list = D.memberList;
        if (!list) return;

        // Add member button (admin only)
        const p = _state.data;
        const isAdmin = p?.my_role === 'admin' || p?.my_role === 'owner';

        list.innerHTML = '';

        if (isAdmin) {
            const addBtn = Utils.el('div', {
                class: 'member-item member-item--add',
                html:  `<div class="member-item__avatar member-item__avatar--add">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </div>
                    <div class="member-item__info">
                        <div class="member-item__name">${I18n.t('add_member')}</div>
                    </div>`,
            });
            addBtn.addEventListener('click', () => _openAddMemberModal());
            list.appendChild(addBtn);
        }

        const frag = document.createDocumentFragment();
        _state.members.forEach(m => {
            frag.appendChild(_buildMemberRow(m, isAdmin));
        });
        list.appendChild(frag);

        // Load more
        if (_state.hasMoreMembers) {
            const btn = Utils.el('button', {
                class: 'btn btn--ghost btn--sm profile-member-loadmore',
                text:  I18n.t('load_more'),
            });
            btn.addEventListener('click', async () => {
                await _loadMembers();
                _renderMemberList();
            });
            list.appendChild(btn);
        }
    }

    function _buildMemberRow(m, isAdmin) {
        const row = Utils.el('div', {
            class:          'member-item',
            'data-user-id': m.id,
        });

        const avatarWrap = Utils.el('div', { class: 'member-item__avatar' });
        avatarWrap.appendChild(Avatar.render(m.name, m.avatar, 40,
            Socket.Presence.get(m.id)?.status === 'online'));
        row.appendChild(avatarWrap);

        const info = Utils.el('div', { class: 'member-item__info' });
        const name = Utils.el('div', { class: 'member-item__name' });
        name.innerHTML = Utils.escapeHtml(m.name || '');
        if (m.is_verified) {
            name.insertAdjacentHTML('beforeend',
                `<svg class="verified-badge verified-badge--sm" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
             </svg>`);
        }

        const role = Utils.el('div', { class: `member-item__role member-item__role--${m.role}` });
        const roleLabels = {
            owner: I18n.t('role.owner'),
            admin: I18n.t('role.admin'),
            member:I18n.t('role.member'),
        };
        role.textContent = roleLabels[m.role] || '';

        info.append(name, role);

        // Online status
        const status = Utils.el('div', {
            class: 'member-item__status',
            text:  Socket.Presence.get(m.id)?.status === 'online'
                ? I18n.t('online') : '',
        });
        info.appendChild(status);

        row.appendChild(info);

        // Actions (admin can kick/promote)
        if (isAdmin && m.id !== _state.myId && m.role !== 'owner') {
            const moreBtn = Utils.el('button', {
                class: 'member-item__more',
                html:  `<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/>
                    <circle cx="12" cy="12" r="1"/>
                    <circle cx="12" cy="19" r="1"/></svg>`,
                'aria-label': I18n.t('more'),
            });
            moreBtn.addEventListener('click', e => {
                e.stopPropagation();
                _showMemberCtxMenu(e, m);
            });
            row.appendChild(moreBtn);
        }

        // Click → open user profile
        row.addEventListener('click', () => {
            if (m.id === _state.myId) openSelf();
            else open(m.id, 'user');
        });

        return row;
    }

    /* ──────────────────────────────────────────────────────────
       11. MEMBER CONTEXT MENU (admin)
    ────────────────────────────────────────────────────────── */
    function _showMemberCtxMenu(e, member) {
        const menu  = Utils.qs('.member-ctx-menu') || _createMemberCtxMenu();
        const isAdmin = member.role === 'admin';

        menu.innerHTML = '';
        const items = [
            { label: I18n.t('view_profile'),   fn: () => open(member.id, 'user') },
            { label: I18n.t('send_message'),   fn: () => { close(); Router.navigate(`/chat/${member.id}`); } },
            { label: isAdmin ? I18n.t('demote_admin') : I18n.t('make_admin'),
                fn: () => _setAdminRole(member, !isAdmin) },
            { label: I18n.t('remove_member'),  fn: () => _removeMember(member), danger: true },
            { label: I18n.t('ban_member'),     fn: () => _banMember(member),    danger: true },
        ];

        items.forEach(({ label, fn, danger }) => {
            const item = Utils.el('div', {
                class: `ctx-menu-item${danger ? ' ctx-menu-item--danger' : ''}`,
                text:  label,
            });
            item.addEventListener('click', () => { fn(); menu.hidden = true; });
            menu.appendChild(item);
        });

        const rect = e.currentTarget.getBoundingClientRect();
        menu.hidden = false;
        const mw = menu.offsetWidth, mh = menu.offsetHeight;
        const vw = window.innerWidth,  vh = window.innerHeight;
        let x = rect.right + 4, y = rect.top;
        if (x + mw > vw) x = rect.left - mw - 4;
        if (y + mh > vh) y = vh - mh - 8;
        menu.style.cssText = `left:${x}px;top:${y}px`;

        setTimeout(() => document.addEventListener('click', () => menu.hidden = true, { once: true }), 0);
    }

    function _createMemberCtxMenu() {
        const menu = Utils.el('div', { class: 'ctx-menu member-ctx-menu' });
        document.body.appendChild(menu);
        return menu;
    }

    async function _setAdminRole(member, makeAdmin) {
        try {
            await Http.patch(`/chats/${_state.currentId}/members/${member.id}`, {
                role: makeAdmin ? 'admin' : 'member'
            });
            member.role = makeAdmin ? 'admin' : 'member';
            _renderMemberList();
            Toast.success(makeAdmin ? I18n.t('promoted') : I18n.t('demoted'));
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _removeMember(member) {
        try {
            await Http.delete(`/chats/${_state.currentId}/members/${member.id}`);
            _state.members = _state.members.filter(m => m.id !== member.id);
            _renderMemberList();
            Toast.success(I18n.t('member_removed'));
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _banMember(member) {
        try {
            await Http.post(`/chats/${_state.currentId}/ban`, { user_id: member.id });
            _state.members = _state.members.filter(m => m.id !== member.id);
            _renderMemberList();
            Toast.success(I18n.t('member_banned'));
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    /* ──────────────────────────────────────────────────────────
       12. ADD MEMBER MODAL
    ────────────────────────────────────────────────────────── */
    function _openAddMemberModal() {
        const m = Modal.open('modalAddMember');
        if (!m) return;

        const input   = m.el.querySelector('#addMemberSearch');
        const results = m.el.querySelector('#addMemberResults');
        const selected= new Set();

        input?.addEventListener('input', Utils.debounce(async () => {
            const q = input.value.trim();
            if (!q) { if (results) results.innerHTML = ''; return; }
            try {
                const res = await Http.get(`/contacts/search?q=${encodeURIComponent(q)}`);
                if (!results) return;
                results.innerHTML = (res.contacts || []).map(c => {
                    const isMember  = _state.members.some(m => m.id === c.id);
                    const isSelected= selected.has(c.id);
                    return `<div class="contact-picker-item${isMember ? ' contact-picker-item--member' : ''}
                              ${isSelected ? ' contact-picker-item--selected' : ''}"
                              data-user-id="${c.id}" data-name="${Utils.escapeHtml(c.name)}">
                    ${c.avatar
                        ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(c.avatar)}" alt="">`
                        : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(c.name)}">${Avatar.initials(c.name)}</div>`}
                    <div class="contact-picker-item__info">
                        <div class="contact-picker-item__name">${Utils.escapeHtml(c.name)}</div>
                        <div class="contact-picker-item__sub">${isMember ? I18n.t('already_member') : ''}</div>
                    </div>
                    <span class="contact-picker-item__check"></span>
                </div>`;
                }).join('');

                Utils.delegate(results, '.contact-picker-item:not(.contact-picker-item--member)', 'click', (e, el) => {
                    const id = el.dataset.userId;
                    if (selected.has(id)) {
                        selected.delete(id); el.classList.remove('contact-picker-item--selected');
                    } else {
                        selected.add(id);   el.classList.add('contact-picker-item--selected');
                    }
                    const addBtn = m.el.querySelector('#addMemberConfirm');
                    if (addBtn) addBtn.disabled = !selected.size;
                });
            } catch { /* silent */ }
        }, 300));

        m.el.querySelector('#addMemberConfirm')?.addEventListener('click', async () => {
            if (!selected.size) return;
            Modal.close('modalAddMember');
            try {
                await Http.post(`/chats/${_state.currentId}/members`, { user_ids: [...selected] });
                await _loadMembers(true);
                _renderMemberList();
                Toast.success(I18n.plural('members_added', selected.size));
            } catch { Toast.error(I18n.t('error.generic')); }
        });
    }

    /* ──────────────────────────────────────────────────────────
       13. MUTUAL GROUPS SECTION
    ────────────────────────────────────────────────────────── */
    function _renderMutualGroups() {
        const container = D.mutualGroups;
        if (!container || _state.type !== 'user') return;

        if (!_state.mutualGroups.length) {
            container.hidden = true;
            return;
        }

        container.hidden  = false;
        const title = container.querySelector('.mutual-groups__title');
        if (title) title.textContent = I18n.plural('mutual_groups', _state.mutualGroups.length);

        const list = container.querySelector('.mutual-groups__list');
        if (!list) return;

        list.innerHTML = _state.mutualGroups.map(g => `
        <div class="mutual-group-item" data-chat-id="${g.id}" role="button" tabindex="0">
            ${g.avatar
            ? `<img class="avatar avatar--img avatar--sm" src="${Utils.escapeHtml(g.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback avatar--sm" data-color="${Utils.stringToColor(g.name)}">${Avatar.initials(g.name)}</div>`}
            <div class="mutual-group-item__name">${Utils.escapeHtml(g.name)}</div>
            <div class="mutual-group-item__count">${I18n.plural('members', g.member_count)}</div>
        </div>
    `).join('');

        Utils.delegate(list, '.mutual-group-item', 'click', (e, el) => {
            Router.navigate(`/chat/${el.dataset.chatId}`);
        });
    }

    /* ──────────────────────────────────────────────────────────
       14. EDIT OWN PROFILE
    ────────────────────────────────────────────────────────── */
    function openEditModal() {
        const user = App.getUser();
        const m    = Modal.open('modalEditProfile', {
            name:     user?.name     || '',
            username: user?.username || '',
            bio:      user?.bio      || '',
            phone:    user?.phone    || '',
        });
        if (!m) return;

        // Avatar upload
        const avatarBtn = m.el.querySelector('.edit-profile-avatar');
        avatarBtn?.addEventListener('click', () => {
            const input = document.createElement('input');
            input.type  = 'file';
            input.accept= 'image/*';
            input.addEventListener('change', async () => {
                const file = input.files?.[0];
                if (!file) return;
                if (!Utils.isImage(file)) { Toast.error(I18n.t('error.invalid_image')); return; }

                const blob = await Utils.resizeImage(file, 800, 800, 0.9);
                const form = new FormData();
                form.append('avatar', blob, 'avatar.jpg');

                const t = Toast.loading(I18n.t('uploading'));
                try {
                    const res = await Http.upload('/users/me/avatar', form);
                    t.dismiss();
                    const img = m.el.querySelector('.edit-profile-avatar img');
                    if (img) img.src = res.url;
                    const state = App.getUser();
                    if (state) { state.avatar = res.url; Store.set('user', state); State.set('user', state); }
                } catch { t.dismiss(); Toast.error(I18n.t('error.upload_failed')); }
            });
            input.click();
        });

        // Character counter for bio
        const bioInput = m.el.querySelector('[name="bio"]');
        const bioCount = m.el.querySelector('.bio-char-count');
        bioInput?.addEventListener('input', () => {
            const len = bioInput.value.length;
            if (bioCount) {
                bioCount.textContent = `${len}/120`;
                bioCount.classList.toggle('over', len > 120);
            }
        });

        // Username availability check
        const usernameInput = m.el.querySelector('[name="username"]');
        const usernameHint  = m.el.querySelector('.username-hint');
        usernameInput?.addEventListener('input', Utils.debounce(async () => {
            const val = usernameInput.value.trim().toLowerCase();
            if (!val || val === user?.username) {
                if (usernameHint) usernameHint.textContent = '';
                return;
            }
            if (!/^[a-z0-9_]{3,32}$/.test(val)) {
                if (usernameHint) {
                    usernameHint.textContent  = I18n.t('username.invalid');
                    usernameHint.dataset.status = 'error';
                }
                return;
            }
            try {
                const res = await Http.get(`/users/username/${val}/check`);
                if (usernameHint) {
                    usernameHint.textContent    = res.available ? I18n.t('username.available') : I18n.t('username.taken');
                    usernameHint.dataset.status = res.available ? 'ok' : 'error';
                }
            } catch { /* silent */ }
        }, 500));

        // Submit
        const form = m.el.querySelector('#formEditProfile');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form));

            if (data.bio && data.bio.length > 120) {
                Toast.error(I18n.t('error.bio_too_long')); return;
            }

            const btn = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;

            try {
                const updated = await Http.patch('/users/me', {
                    name:     data.name?.trim(),
                    username: data.username?.trim().toLowerCase(),
                    bio:      data.bio?.trim(),
                });
                Modal.close('modalEditProfile');
                Store.set('user', updated);
                State.set('user', updated);
                EventBus.emit('profile:updated', updated);
                Toast.success(I18n.t('profile_updated'));

                // Refresh profile panel if open
                if (_state.currentId === updated.id) {
                    _state.data = { ..._state.data, ...updated };
                    _renderProfile();
                }
            } catch(e) {
                Toast.error(e.body?.message || I18n.t('error.generic'));
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    }

    /* ──────────────────────────────────────────────────────────
       15. EDIT GROUP / CHANNEL
    ────────────────────────────────────────────────────────── */
    function openEditGroupModal() {
        const g = _state.data;
        const m = Modal.open('modalEditGroup', {
            title:       g?.title || g?.name || '',
            description: g?.description || '',
        });
        if (!m) return;

        // Avatar
        const avatarBtn = m.el.querySelector('.edit-group-avatar');
        avatarBtn?.addEventListener('click', () => {
            const input = document.createElement('input');
            input.type  = 'file';
            input.accept= 'image/*';
            input.addEventListener('change', async () => {
                const file = input.files?.[0];
                if (!file || !Utils.isImage(file)) return;
                const blob = await Utils.resizeImage(file, 400, 400, 0.9);
                const form = new FormData();
                form.append('avatar', blob, 'avatar.jpg');
                try {
                    const res = await Http.upload(`/chats/${_state.currentId}/avatar`, form);
                    const img = m.el.querySelector('.edit-group-avatar img');
                    if (img) img.src = res.url;
                } catch { Toast.error(I18n.t('error.upload_failed')); }
            });
            input.click();
        });

        const form = m.el.querySelector('#formEditGroup');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form));
            const btn  = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;
            try {
                const updated = await Http.patch(`/chats/${_state.currentId}`, {
                    title:       data.title?.trim(),
                    description: data.description?.trim(),
                });
                Modal.close('modalEditGroup');
                _state.data = { ..._state.data, ...updated };
                _renderProfile();
                Toast.success(I18n.t('group_updated'));
            } catch { Toast.error(I18n.t('error.generic')); }
            finally { if (btn) btn.disabled = false; }
        });
    }

    /* ──────────────────────────────────────────────────────────
       16. BLOCK / REPORT / LEAVE
    ────────────────────────────────────────────────────────── */
    async function _toggleBlock(user) {
        const isBlocked = user.is_blocked;
        try {
            if (isBlocked) {
                await Http.delete(`/users/${user.id}/block`);
                user.is_blocked = false;
                Toast.success(I18n.t('unblocked'));
            } else {
                await Http.post('/users/block', { user_id: user.id });
                user.is_blocked = true;
                Toast.success(I18n.t('blocked'));
            }
            _renderActions(user);
            EventBus.emit('user:blockChanged', { userId: user.id, blocked: user.is_blocked });
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    function _showReportModal(target) {
        const m = Modal.open('modalReport');
        if (!m) return;

        m.el.querySelector('#reportSubmit')?.addEventListener('click', async () => {
            const reason = m.el.querySelector('[name="report_reason"]:checked')?.value;
            const detail = m.el.querySelector('[name="report_detail"]')?.value?.trim();
            if (!reason) { Toast.error(I18n.t('error.select_reason')); return; }
            Modal.close('modalReport');
            try {
                await Http.post('/reports', {
                    target_type: _state.type,
                    target_id:   _state.currentId,
                    reason,
                    detail,
                });
                Toast.success(I18n.t('reported'));
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    async function _leaveGroup() {
        const m = Modal.open('modalLeaveGroup');
        if (!m) return;

        m.el.querySelector('[data-action="leave-confirm"]')?.addEventListener('click', async () => {
            Modal.close('modalLeaveGroup');
            try {
                await Http.delete(`/chats/${_state.currentId}/members/me`);
                close();
                Chat.close();
                Router.navigate('/chats');
                Toast.success(I18n.t('left_group'));
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    async function _toggleMute(target) {
        try {
            const muted = !target.is_muted;
            const until = muted ? new Date(Date.now() + 8 * 3600000).toISOString() : null;
            await Http.patch(`/chats/${target.id}/mute`, { muted_until: until });
            target.is_muted = muted;
            _renderActions(target);
            Toast.success(muted ? I18n.t('muted') : I18n.t('unmuted'));
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _openDM(user) {
        try {
            const chat = await Http.post('/chats', { user_id: user.id, type: 'direct' });
            close();
            Router.navigate(`/chat/${chat.id}`);
        } catch { Toast.error(I18n.t('error.create_chat')); }
    }

    function _startCall(user, type) {
        EventBus.emit('call:start', { userId: user.id, type });
        close();
    }

    /* ──────────────────────────────────────────────────────────
       17. MEDIA VIEWER (full-screen carousel)
    ────────────────────────────────────────────────────────── */
    function _openMediaViewer(startIdx) {
        const items  = _state.media;
        if (!items.length) return;

        let cur = startIdx;
        const overlay = Utils.el('div', { class: 'media-viewer' });
        const img     = Utils.el('img', { class: 'media-viewer__img', alt: '' });
        const vid     = Utils.el('video', { class: 'media-viewer__video', controls: '', playsinline: '' });
        const closeBtn= Utils.el('button', { class: 'media-viewer__close', html: '×', 'aria-label': I18n.t('close') });
        const prevBtn = Utils.el('button', { class: 'media-viewer__prev', html: '‹', 'aria-label': I18n.t('prev') });
        const nextBtn = Utils.el('button', { class: 'media-viewer__next', html: '›', 'aria-label': I18n.t('next') });
        const counter = Utils.el('div',   { class: 'media-viewer__counter' });
        const dlBtn   = Utils.el('a',     { class: 'media-viewer__dl', html: '⬇', 'aria-label': I18n.t('download'), target:'_blank', rel:'noopener noreferrer' });

        overlay.append(img, vid, closeBtn, prevBtn, nextBtn, counter, dlBtn);
        document.body.appendChild(overlay);
        document.body.classList.add('media-viewer-open');

        function _show(idx) {
            cur = idx;
            const item = items[idx];
            if (item.type === 'video') {
                img.hidden = true;
                vid.hidden = false;
                vid.src = item.url;
                vid.play();
            } else {
                vid.hidden = true; vid.pause?.(); vid.src = '';
                img.hidden = false;
                img.src    = item.url;
            }
            counter.textContent = `${idx + 1} / ${items.length}`;
            dlBtn.href          = item.url;
            prevBtn.disabled    = idx === 0;
            nextBtn.disabled    = idx === items.length - 1;
        }

        function _close() {
            vid.pause?.();
            overlay.remove();
            document.body.classList.remove('media-viewer-open');
            document.removeEventListener('keydown', _onKey);
        }

        function _onKey(e) {
            if (e.key === 'ArrowLeft'  && cur > 0)              _show(cur - 1);
            if (e.key === 'ArrowRight' && cur < items.length-1) _show(cur + 1);
            if (e.key === 'Escape')                              _close();
        }

        closeBtn.addEventListener('click',  _close);
        prevBtn.addEventListener('click',   () => { if (cur > 0) _show(cur - 1); });
        nextBtn.addEventListener('click',   () => { if (cur < items.length-1) _show(cur + 1); });
        overlay.addEventListener('click',   e  => { if (e.target === overlay) _close(); });
        document.addEventListener('keydown', _onKey);

        _show(cur);
        requestAnimationFrame(() => overlay.classList.add('media-viewer--open'));
    }

    /* ──────────────────────────────────────────────────────────
       18. AVATAR VIEWER
    ────────────────────────────────────────────────────────── */
    function _openAvatarViewer(src, name) {
        const overlay = Utils.el('div', { class: 'avatar-viewer' });
        const img     = Utils.el('img', { src, alt: name || '', class: 'avatar-viewer__img' });
        const close   = Utils.el('button', { class: 'avatar-viewer__close', html: '×' });
        const title   = Utils.el('div',   { class: 'avatar-viewer__title', text: name || '' });
        overlay.append(img, close, title);
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('avatar-viewer--open'));

        const dismiss = () => {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
        };
        const onKey = e => { if (e.key === 'Escape') dismiss(); };
        close.addEventListener('click', dismiss);
        overlay.addEventListener('click', e => { if (e.target === overlay) dismiss(); });
        document.addEventListener('keydown', onKey);
    }

    /* ──────────────────────────────────────────────────────────
       19. STORY VIEWER  (placeholder)
    ────────────────────────────────────────────────────────── */
    function _openStory(userId) {
        EventBus.emit('story:open', { userId });
    }

    /* ──────────────────────────────────────────────────────────
       20. OPEN SELF PROFILE
    ────────────────────────────────────────────────────────── */
    function openSelf() {
        const user = App.getUser();
        if (user) open(user.id, 'user');
    }

    /* ──────────────────────────────────────────────────────────
       21. SKELETON / ERROR
    ────────────────────────────────────────────────────────── */
    function _renderSkeleton() {
        const panel = D.panel;
        if (!panel) return;

        const header = panel.querySelector('.profile-header');
        if (header) {
            header.innerHTML = `
            <div class="profile-skeleton">
                <div class="profile-skeleton__avatar"></div>
                <div class="profile-skeleton__name"></div>
                <div class="profile-skeleton__status"></div>
                <div class="profile-skeleton__bio"></div>
                <div class="profile-skeleton__actions">
                    ${Array(3).fill('<div class="profile-skeleton__action-btn"></div>').join('')}
                </div>
            </div>`;
        }

        const grid = D.mediaGrid;
        if (grid) {
            grid.innerHTML = Array.from({ length: 9 }, () =>
                `<div class="media-thumb media-thumb--skeleton"></div>`
            ).join('');
        }
    }

    function _renderError(e) {
        const panel = D.panel;
        if (!panel) return;
        const header = panel.querySelector('.profile-header');
        if (header) {
            header.innerHTML = `
            <div class="profile-error">
                <div class="profile-error__icon">⚠️</div>
                <div class="profile-error__title">${I18n.t('error.load_profile')}</div>
                <button class="btn btn--primary btn--sm" onclick="Profile.open('${_state.currentId}','${_state.type}')">
                    ${I18n.t('retry')}
                </button>
            </div>`;
        }
    }

    /* ──────────────────────────────────────────────────────────
       22. REALTIME UPDATES
    ────────────────────────────────────────────────────────── */
    function _bindSocket() {
        EventBus.on('presence:update', ({ userId, status, lastSeen }) => {
            if (_state.currentId !== userId || _state.type !== 'user') return;
            if (D.status) {
                D.status.textContent    = status === 'online'
                    ? I18n.t('online')
                    : lastSeen
                        ? I18n.t('last_seen') + ' ' + I18n.formatRelative(lastSeen)
                        : I18n.t('offline');
                D.status.dataset.status = status;
            }

            // Update online dot on avatar
            const dot = D.avatar?.querySelector('.avatar__online');
            if (dot) dot.hidden = status !== 'online';
        });

        EventBus.on('group:memberJoined', ({ chat_id, user }) => {
            if (chat_id !== _state.currentId) return;
            if (!_state.members.find(m => m.id === user.id)) {
                _state.members.push(user);
                _renderMemberList();
            }
        });

        EventBus.on('group:memberLeft', ({ chat_id, user_id }) => {
            if (chat_id !== _state.currentId) return;
            _state.members = _state.members.filter(m => m.id !== user_id);
            _renderMemberList();
        });

        EventBus.on('chat:updated', data => {
            if (data.id !== _state.currentId) return;
            _state.data = { ..._state.data, ...data };
            _renderProfile();
        });
    }

    /* ──────────────────────────────────────────────────────────
       23. HELPERS
    ────────────────────────────────────────────────────────── */
    function _tplEmpty(icon, text) {
        return `<div class="profile-tab-empty">
        <div class="profile-tab-empty__icon">${icon}</div>
        <div class="profile-tab-empty__text">${text}</div>
    </div>`;
    }

    function _formatDur(secs) {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return `${m}:${String(s).padStart(2,'0')}`;
    }

    function _downloadFile(url, name) {
        const a   = document.createElement('a');
        a.href    = url;
        a.download= name || 'file';
        a.rel     = 'noopener noreferrer';
        a.click();
    }

    /* ──────────────────────────────────────────────────────────
       24. BOTTOM ACTIONS  (block / report / leave)
    ────────────────────────────────────────────────────────── */
    function _bindBottomActions() {
        Utils.delegate(document, '[data-action="block-user"]', 'click', () => {
            if (_state.data) _toggleBlock(_state.data);
        });
        Utils.delegate(document, '[data-action="report-user"]', 'click', () => {
            _showReportModal(_state.data);
        });
        Utils.delegate(document, '[data-action="leave-group"]', 'click', () => {
            _leaveGroup();
        });
    }

    /* ──────────────────────────────────────────────────────────
       25. INIT
    ────────────────────────────────────────────────────────── */
    function init() {
        _bindTabs();
        _bindSocket();
        _bindBottomActions();

        // Back / close
        D.backBtn?.addEventListener('click',  () => close());
        D.closeBtn?.addEventListener('click', () => close());

        // Route
        EventBus.on('page:profile', ({ id, type }) => open(id, type || 'user'));

        // Edit own profile shortcut
        EventBus.on('profile:editSelf', () => openEditModal());

        // Keyboard: Esc closes panel
        EventBus.on('ui:escape', () => {
            const panel = D.panel;
            if (panel && !panel.hidden) close();
        });

        // Update avatar in panel when profile updated
        EventBus.on('profile:updated', user => {
            if (_state.currentId === user.id) {
                _state.data = { ..._state.data, ...user };
                _renderProfile();
            }
        });

        console.log('[Profile] Initialized');
    }

    /* ──────────────────────────────────────────────────────────
       PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        init,
        open,
        close,
        openSelf,
        openEditModal,
        openEditGroupModal,
        get data() { return _state.data; },
    };

})();

window.Profile = Profile;

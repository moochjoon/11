/* ============================================================
   SETTINGS.JS  —  Namak Messenger
   App settings: appearance, notifications, privacy, security,
   language, storage, linked devices, data export, account
   ============================================================ */

'use strict';

const Settings = (() => {

    /* ──────────────────────────────────────────────────────────
       1. STATE
    ────────────────────────────────────────────────────────── */
    const _state = {
        activeSection: 'appearance',
        devices:       [],
        storageInfo:   null,
        loading:       false,
    };

    /* ──────────────────────────────────────────────────────────
       2. DEFAULT SETTINGS SCHEMA
    ────────────────────────────────────────────────────────── */
    const DEFAULTS = {
        /* Appearance */
        theme:                  'system',
        accent:                 'blue',
        chat_bg:                'none',
        bubble_style:           'rounded',
        font_size:              14,
        compact_mode:           false,
        animate_emoji:          true,
        show_avatars:           true,
        /* Notifications */
        notif_enabled:          true,
        notif_sound:            true,
        notif_vibrate:          true,
        notif_preview:          true,     // show message text in notif
        notif_mute_until:       null,
        notif_sound_file:       'default',
        /* Privacy */
        last_seen:              'everyone',   // everyone | contacts | nobody
        profile_photo:          'everyone',
        online_status:          'everyone',
        read_receipts:          true,
        typing_indicator:       true,
        forwarded_tag:          true,
        groups_add:             'everyone',   // everyone | contacts | nobody
        /* Security */
        two_factor:             false,
        passcode_lock:          false,
        passcode_timeout:       5,            // minutes: 1|5|30|60|never
        biometric_lock:         false,
        auto_delete_messages:   'off',        // off | 1d | 7d | 30d
        /* Chat */
        enter_to_send:          true,
        auto_download_images:   'wifi',       // always | wifi | never
        auto_download_videos:   'never',
        auto_download_files:    'never',
        auto_play_gifs:         true,
        auto_play_videos:       false,
        save_to_gallery:        false,
        /* Language */
        locale:                 'fa',
        /* Storage */
        cache_limit_mb:         500,
    };

    /* ──────────────────────────────────────────────────────────
       3. SETTINGS STORE
    ────────────────────────────────────────────────────────── */
    const Prefs = {
        _cache: null,

        all() {
            if (!this._cache) {
                this._cache = { ...DEFAULTS, ...Store.get('settings', {}) };
            }
            return this._cache;
        },

        get(key) {
            return this.all()[key] ?? DEFAULTS[key];
        },

        set(key, value) {
            this._cache        = { ...this.all(), [key]: value };
            Store.set('settings', this._cache);
            EventBus.emit('settings:change', { key, value });
            _applyLiveSetting(key, value);
        },

        setMany(obj) {
            this._cache = { ...this.all(), ...obj };
            Store.set('settings', this._cache);
            Object.entries(obj).forEach(([k, v]) => {
                EventBus.emit('settings:change', { key: k, value: v });
                _applyLiveSetting(k, v);
            });
        },

        reset(key) {
            if (key) this.set(key, DEFAULTS[key]);
            else {
                this._cache = { ...DEFAULTS };
                Store.set('settings', this._cache);
                _applyAllSettings();
                EventBus.emit('settings:reset');
            }
        },
    };

    /* ──────────────────────────────────────────────────────────
       4. LIVE SETTING APPLIERS
    ────────────────────────────────────────────────────────── */
    function _applyLiveSetting(key, value) {
        switch(key) {
            case 'theme':               ThemeManager.setTheme(value);          break;
            case 'accent':              ThemeManager.setAccent(value);         break;
            case 'chat_bg':             ThemeManager.setChatBg(value);         break;
            case 'bubble_style':        ThemeManager.setBubbleStyle(value);    break;
            case 'font_size':           ThemeManager.setFontSize(value);       break;
            case 'compact_mode':
                document.body.classList.toggle('compact-mode', !!value);       break;
            case 'animate_emoji':
                document.body.classList.toggle('no-animate-emoji', !value);    break;
            case 'show_avatars':
                document.body.classList.toggle('hide-avatars', !value);        break;
            case 'locale':              I18n.load(value);                      break;
            case 'notif_enabled':
                if (value) Notif.requestPermission();                          break;
            case 'enter_to_send':
                State.set('enter_to_send', value);                             break;
        }
    }

    function _applyAllSettings() {
        const s = Prefs.all();
        ThemeManager.setTheme(s.theme);
        ThemeManager.setAccent(s.accent);
        ThemeManager.setChatBg(s.chat_bg);
        ThemeManager.setBubbleStyle(s.bubble_style);
        ThemeManager.setFontSize(s.font_size);
        document.body.classList.toggle('compact-mode',      !!s.compact_mode);
        document.body.classList.toggle('no-animate-emoji',  !s.animate_emoji);
        document.body.classList.toggle('hide-avatars',      !s.show_avatars);
        State.set('enter_to_send', s.enter_to_send);
    }

    /* ──────────────────────────────────────────────────────────
       5. DOM REFS
    ────────────────────────────────────────────────────────── */
    const D = {
        get page()      { return Utils.qs('.settings-page'); },
        get nav()       { return Utils.qs('.settings-nav'); },
        get content()   { return Utils.qs('.settings-content'); },
        get backBtn()   { return Utils.qs('.settings-back'); },
        get header()    { return Utils.qs('.settings-content__header'); },
        get body()      { return Utils.qs('.settings-content__body'); },
    };

    /* ──────────────────────────────────────────────────────────
       6. OPEN / NAVIGATE
    ────────────────────────────────────────────────────────── */
    function open(section = 'appearance') {
        _state.activeSection = section;
        const page = D.page;
        if (page) {
            page.hidden = false;
            requestAnimationFrame(() => page.classList.add('settings-page--open'));
        }
        _highlightNav(section);
        _renderSection(section);
        EventBus.emit('settings:opened', { section });
    }

    function close() {
        const page = D.page;
        if (!page) return;
        page.classList.remove('settings-page--open');
        setTimeout(() => { page.hidden = true; }, 260);
        EventBus.emit('settings:closed');
    }

    function _highlightNav(section) {
        Utils.qsa('.settings-nav-item').forEach(item => {
            item.classList.toggle('active', item.dataset.section === section);
        });
    }

    /* ──────────────────────────────────────────────────────────
       7. SECTION RENDERER (router)
    ────────────────────────────────────────────────────────── */
    function _renderSection(section) {
        const body   = D.body;
        const header = D.header;
        if (!body) return;

        const titles = {
            appearance:   I18n.t('settings.appearance'),
            notifications:I18n.t('settings.notifications'),
            privacy:      I18n.t('settings.privacy'),
            security:     I18n.t('settings.security'),
            chat:         I18n.t('settings.chat'),
            language:     I18n.t('settings.language'),
            storage:      I18n.t('settings.storage'),
            devices:      I18n.t('settings.devices'),
            account:      I18n.t('settings.account'),
            help:         I18n.t('settings.help'),
        };

        if (header) header.textContent = titles[section] || section;

        body.innerHTML = '';
        body.classList.add('settings-loading');

        const renderers = {
            appearance:    _renderAppearance,
            notifications: _renderNotifications,
            privacy:       _renderPrivacy,
            security:      _renderSecurity,
            chat:          _renderChat,
            language:      _renderLanguage,
            storage:       _renderStorage,
            devices:       _renderDevices,
            account:       _renderAccount,
            help:          _renderHelp,
        };

        const fn = renderers[section];
        if (fn) {
            Promise.resolve(fn()).then(() => {
                body.classList.remove('settings-loading');
            });
        } else {
            body.innerHTML = _tplEmpty('⚙️', I18n.t('settings.not_found'));
            body.classList.remove('settings-loading');
        }
    }

    /* ──────────────────────────────────────────────────────────
       8. SECTION: APPEARANCE
    ────────────────────────────────────────────────────────── */
    function _renderAppearance() {
        const s   = Prefs.all();
        const body= D.body;
        body.innerHTML = '';

        /* Theme */
        body.appendChild(_buildGroup(I18n.t('appearance.theme'), [
            _buildSegment('theme', [
                { value: 'light',  icon: '☀️',  label: I18n.t('theme.light')  },
                { value: 'dark',   icon: '🌙',  label: I18n.t('theme.dark')   },
                { value: 'system', icon: '🖥️', label: I18n.t('theme.system') },
            ], s.theme),
        ]));

        /* Accent colour */
        body.appendChild(_buildGroup(I18n.t('appearance.accent'), [
            _buildColorPicker(ThemeManager.ACCENTS, s.accent),
        ]));

        /* Chat background */
        body.appendChild(_buildGroup(I18n.t('appearance.chat_bg'), [
            _buildChatBgPicker(s.chat_bg),
        ]));

        /* Bubble style */
        body.appendChild(_buildGroup(I18n.t('appearance.bubble_style'), [
            _buildSegment('bubble_style', [
                { value: 'rounded',  label: I18n.t('bubble.rounded')  },
                { value: 'square',   label: I18n.t('bubble.square')   },
                { value: 'minimal',  label: I18n.t('bubble.minimal')  },
            ], s.bubble_style),
        ]));

        /* Font size */
        body.appendChild(_buildGroup(I18n.t('appearance.font_size'), [
            _buildSlider('font_size', 12, 20, 1, s.font_size,
                v => v + 'px',
                v => _previewFontSize(v)),
        ]));

        /* Toggles */
        body.appendChild(_buildGroup(I18n.t('appearance.display'), [
            _buildToggle('compact_mode',   I18n.t('appearance.compact'),       s.compact_mode),
            _buildToggle('animate_emoji',  I18n.t('appearance.animate_emoji'), s.animate_emoji),
            _buildToggle('show_avatars',   I18n.t('appearance.show_avatars'),  s.show_avatars),
        ]));

        /* Live preview bubble */
        const preview = Utils.el('div', { class: 'appearance-preview' });
        preview.innerHTML = `
        <div class="appearance-preview__label">${I18n.t('appearance.preview')}</div>
        <div class="appearance-preview__chat">
            <div class="msg-row msg-row--in">
                <div class="msg-bubble">
                    <div class="msg-text" id="previewText"
                         style="font-size:${s.font_size}px">
                        ${I18n.t('appearance.preview_text')}
                    </div>
                    <div class="msg-meta">
                        <span class="msg-meta__time">12:00</span>
                    </div>
                </div>
            </div>
            <div class="msg-row msg-row--out">
                <div class="msg-bubble">
                    <div class="msg-text" id="previewTextOut"
                         style="font-size:${s.font_size}px">
                        ${I18n.t('appearance.preview_reply')}
                    </div>
                    <div class="msg-meta">
                        <span class="msg-meta__time">12:01</span>
                        <span class="msg-meta__tick" data-status="read"></span>
                    </div>
                </div>
            </div>
        </div>`;
        body.appendChild(preview);
    }

    function _previewFontSize(v) {
        Utils.qsa('#previewText, #previewTextOut').forEach(el => {
            el.style.fontSize = v + 'px';
        });
    }

    /* ──────────────────────────────────────────────────────────
       9. SECTION: NOTIFICATIONS
    ────────────────────────────────────────────────────────── */
    function _renderNotifications() {
        const s    = Prefs.all();
        const body = D.body;
        body.innerHTML = '';

        const masterToggle = _buildToggle('notif_enabled', I18n.t('notif.enable'), s.notif_enabled, {
            onChange: v => {
                if (v) Notif.requestPermission().then(granted => {
                    if (!granted) {
                        Prefs.set('notif_enabled', false);
                        master.querySelector('input').checked = false;
                        Toast.warning(I18n.t('notif.permission_denied'));
                    }
                });
                _toggleGroupDisabled('notif-sub', !v);
            }
        });
        const master = _buildGroup(I18n.t('notif.master'), [masterToggle]);
        body.appendChild(master);

        const subGroup = _buildGroup(I18n.t('notif.settings'), [
            _buildToggle('notif_sound',   I18n.t('notif.sound'),    s.notif_sound,   { disabled: !s.notif_enabled }),
            _buildToggle('notif_vibrate', I18n.t('notif.vibrate'),  s.notif_vibrate, { disabled: !s.notif_enabled }),
            _buildToggle('notif_preview', I18n.t('notif.preview'),  s.notif_preview, { disabled: !s.notif_enabled }),
            _buildSelect('notif_sound_file', I18n.t('notif.sound_file'), [
                { value: 'default',  label: I18n.t('notif.sound_default')  },
                { value: 'ding',     label: I18n.t('notif.sound_ding')     },
                { value: 'chime',    label: I18n.t('notif.sound_chime')    },
                { value: 'pop',      label: I18n.t('notif.sound_pop')      },
                { value: 'silent',   label: I18n.t('notif.sound_silent')   },
            ], s.notif_sound_file, { disabled: !s.notif_enabled || !s.notif_sound }),
        ], 'notif-sub');
        body.appendChild(subGroup);

        /* Mute all */
        body.appendChild(_buildGroup(I18n.t('notif.mute_all'), [
            _buildSelect('notif_mute_until', I18n.t('notif.mute_for'), [
                { value: 'off',  label: I18n.t('notif.mute_off')    },
                { value: '1h',   label: I18n.t('notif.mute_1h')     },
                { value: '8h',   label: I18n.t('notif.mute_8h')     },
                { value: '24h',  label: I18n.t('notif.mute_24h')    },
                { value: 'week', label: I18n.t('notif.mute_week')   },
                { value: 'ever', label: I18n.t('notif.mute_ever')   },
            ], s.notif_mute_until || 'off', {
                onChange: v => {
                    const muteUntil = _muteUntilDate(v);
                    Prefs.set('notif_mute_until', muteUntil);
                    Http.patch('/users/me/settings', { notif_mute_until: muteUntil }).catch(() => {});
                }
            }),
        ]));

        /* Test notification */
        const testBtn = _buildAction(
            I18n.t('notif.test'),
            I18n.t('notif.test_desc'),
            I18n.t('notif.send_test'),
            () => _sendTestNotif()
        );
        body.appendChild(testBtn);
    }

    function _muteUntilDate(v) {
        if (v === 'off')  return null;
        if (v === 'ever') return new Date('2099-01-01').toISOString();
        const map = { '1h': 3600000, '8h': 28800000, '24h': 86400000, 'week': 604800000 };
        return new Date(Date.now() + (map[v] || 0)).toISOString();
    }

    function _sendTestNotif() {
        Notif.show(I18n.t('notif.test_title'), {
            body:  I18n.t('notif.test_body'),
            force: true,
        });
        Toast.info(I18n.t('notif.test_sent'));
    }

    /* ──────────────────────────────────────────────────────────
       10. SECTION: PRIVACY
    ────────────────────────────────────────────────────────── */
    function _renderPrivacy() {
        const s    = Prefs.all();
        const body = D.body;
        body.innerHTML = '';

        const visibilityOpts = [
            { value: 'everyone',  label: I18n.t('privacy.everyone')  },
            { value: 'contacts',  label: I18n.t('privacy.contacts')  },
            { value: 'nobody',    label: I18n.t('privacy.nobody')    },
        ];

        body.appendChild(_buildGroup(I18n.t('privacy.visibility'), [
            _buildSelect('last_seen',       I18n.t('privacy.last_seen'),       visibilityOpts,  s.last_seen,       { server: true }),
            _buildSelect('profile_photo',   I18n.t('privacy.profile_photo'),   visibilityOpts,  s.profile_photo,   { server: true }),
            _buildSelect('online_status',   I18n.t('privacy.online_status'),   visibilityOpts,  s.online_status,   { server: true }),
            _buildSelect('groups_add',      I18n.t('privacy.groups_add'),      visibilityOpts,  s.groups_add,      { server: true }),
        ]));

        body.appendChild(_buildGroup(I18n.t('privacy.messaging'), [
            _buildToggle('read_receipts',    I18n.t('privacy.read_receipts'),   s.read_receipts,   { server: true }),
            _buildToggle('typing_indicator', I18n.t('privacy.typing'),          s.typing_indicator,{ server: true }),
            _buildToggle('forwarded_tag',    I18n.t('privacy.forwarded_tag'),   s.forwarded_tag,   { server: true }),
        ]));

        /* Blocked users */
        body.appendChild(_buildSection(
            I18n.t('privacy.blocked'),
            I18n.t('privacy.blocked_desc'),
            [_buildNavRow(I18n.t('privacy.manage_blocked'), () => _openBlockedList())]
        ));
    }

    function _openBlockedList() {
        Modal.open('modalBlockedUsers');
        const list = Utils.qs('#blockedUsersList');
        if (!list) return;

        list.innerHTML = `<div class="spinner"></div>`;
        Http.get('/users/blocked').then(res => {
            const users = res.users || [];
            if (!users.length) {
                list.innerHTML = `<div class="empty-state">${I18n.t('privacy.no_blocked')}</div>`;
                return;
            }
            list.innerHTML = users.map(u => `
            <div class="blocked-item" data-user-id="${u.id}">
                ${u.avatar
                ? `<img class="avatar avatar--img" src="${Utils.escapeHtml(u.avatar)}" alt="">`
                : `<div class="avatar avatar--fallback" data-color="${Utils.stringToColor(u.name)}">${Avatar.initials(u.name)}</div>`}
                <div class="blocked-item__name">${Utils.escapeHtml(u.name)}</div>
                <button class="btn btn--sm btn--ghost blocked-item__unblock"
                    data-user-id="${u.id}">
                    ${I18n.t('unblock')}
                </button>
            </div>
        `).join('');

            Utils.delegate(list, '.blocked-item__unblock', 'click', async (e, btn) => {
                const userId = btn.dataset.userId;
                try {
                    await Http.delete(`/users/${userId}/block`);
                    btn.closest('.blocked-item').remove();
                    if (!list.querySelector('.blocked-item')) {
                        list.innerHTML = `<div class="empty-state">${I18n.t('privacy.no_blocked')}</div>`;
                    }
                } catch { Toast.error(I18n.t('error.generic')); }
            });
        }).catch(() => {
            list.innerHTML = `<div class="empty-state">${I18n.t('error.generic')}</div>`;
        });
    }

    /* ──────────────────────────────────────────────────────────
       11. SECTION: SECURITY
    ────────────────────────────────────────────────────────── */
    function _renderSecurity() {
        const s    = Prefs.all();
        const body = D.body;
        body.innerHTML = '';

        /* Two-Factor Auth */
        body.appendChild(_buildSection(
            I18n.t('security.two_factor'),
            I18n.t('security.two_factor_desc'),
            [_buildToggle('two_factor', I18n.t('security.two_factor_enable'), s.two_factor, {
                server: true,
                onChange: v => v ? _setup2FA() : _disable2FA(),
            })]
        ));

        /* Passcode Lock */
        body.appendChild(_buildSection(
            I18n.t('security.passcode'),
            I18n.t('security.passcode_desc'),
            [
                _buildToggle('passcode_lock', I18n.t('security.passcode_enable'), s.passcode_lock, {
                    onChange: v => v ? _setupPasscode() : _removePasscode(),
                }),
                _buildSelect('passcode_timeout', I18n.t('security.passcode_timeout'), [
                    { value: 1,     label: I18n.t('security.timeout_1m')  },
                    { value: 5,     label: I18n.t('security.timeout_5m')  },
                    { value: 30,    label: I18n.t('security.timeout_30m') },
                    { value: 60,    label: I18n.t('security.timeout_1h')  },
                    { value: 0,     label: I18n.t('security.timeout_never')},
                ], s.passcode_timeout, { disabled: !s.passcode_lock }),
            ]
        ));

        /* Biometric */
        if ('credentials' in navigator) {
            body.appendChild(_buildGroup(I18n.t('security.biometric'), [
                _buildToggle('biometric_lock', I18n.t('security.biometric_enable'), s.biometric_lock, {
                    disabled: !s.passcode_lock,
                }),
            ]));
        }

        /* Auto Delete */
        body.appendChild(_buildGroup(I18n.t('security.auto_delete'), [
            _buildSelect('auto_delete_messages', I18n.t('security.auto_delete_after'), [
                { value: 'off', label: I18n.t('security.auto_delete_off') },
                { value: '1d',  label: I18n.t('security.auto_delete_1d')  },
                { value: '7d',  label: I18n.t('security.auto_delete_7d')  },
                { value: '30d', label: I18n.t('security.auto_delete_30d') },
            ], s.auto_delete_messages, { server: true }),
        ]));

        /* Active sessions */
        body.appendChild(_buildSection(
            I18n.t('security.sessions'),
            I18n.t('security.sessions_desc'),
            [_buildNavRow(I18n.t('security.view_sessions'), () => open('devices'))]
        ));

        /* Change password */
        body.appendChild(_buildSection(
            I18n.t('security.change_password'),
            I18n.t('security.change_password_desc'),
            [_buildNavRow(I18n.t('security.change_password'), () => _openChangePasswordModal())]
        ));
    }

    function _setup2FA() {
        Modal.open('modal2FASetup');
        const body = Utils.qs('#modal2FASetup .modal__body');
        if (!body) return;

        body.innerHTML = `<div class="spinner"></div>`;
        Http.post('/auth/2fa/setup').then(res => {
            body.innerHTML = `
            <p class="modal__text">${I18n.t('security.2fa_scan')}</p>
            <img class="qr-code" src="${Utils.escapeHtml(res.qr_url)}" alt="QR Code">
            <p class="modal__text modal__text--muted">${I18n.t('security.2fa_manual')}:</p>
            <code class="secret-code">${Utils.escapeHtml(res.secret)}</code>
            <input class="input" type="text" id="twoFACode"
                   placeholder="${I18n.t('security.2fa_code_placeholder')}"
                   maxlength="6" inputmode="numeric" autocomplete="one-time-code">
            <button class="btn btn--primary btn--full" id="verify2FA">
                ${I18n.t('security.2fa_verify')}
            </button>`;

            Utils.qs('#verify2FA')?.addEventListener('click', async () => {
                const code = Utils.qs('#twoFACode')?.value?.trim();
                if (!code || code.length !== 6) { Toast.error(I18n.t('security.2fa_invalid')); return; }
                try {
                    await Http.post('/auth/2fa/verify', { code, secret: res.secret });
                    Prefs.set('two_factor', true);
                    Modal.close('modal2FASetup');
                    Toast.success(I18n.t('security.2fa_enabled'));
                } catch { Toast.error(I18n.t('security.2fa_wrong_code')); }
            });
        }).catch(() => Modal.close('modal2FASetup'));
    }

    function _disable2FA() {
        const m = Modal.open('modal2FADisable');
        if (!m) return;
        m.el.querySelector('[data-action="disable-2fa"]')?.addEventListener('click', async () => {
            const code = m.el.querySelector('#disable2FACode')?.value?.trim();
            if (!code) return;
            try {
                await Http.delete('/auth/2fa', { code });
                Prefs.set('two_factor', false);
                Modal.close('modal2FADisable');
                Toast.success(I18n.t('security.2fa_disabled'));
            } catch { Toast.error(I18n.t('security.2fa_wrong_code')); }
        }, { once: true });
    }

    function _setupPasscode() {
        Modal.open('modalSetPasscode');
        const form = Utils.qs('#formSetPasscode');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const p1 = form.querySelector('[name="passcode"]')?.value;
            const p2 = form.querySelector('[name="passcode_confirm"]')?.value;
            if (!p1 || p1.length < 4) { Toast.error(I18n.t('security.passcode_short')); return; }
            if (p1 !== p2)             { Toast.error(I18n.t('security.passcode_mismatch')); return; }
            Store.set('passcode_hash', _hashPasscode(p1));
            Prefs.set('passcode_lock', true);
            Modal.close('modalSetPasscode');
            Toast.success(I18n.t('security.passcode_set'));
        }, { once: true });
    }

    function _removePasscode() {
        Store.remove('passcode_hash');
        Prefs.set('passcode_lock', false);
        Prefs.set('biometric_lock', false);
        Toast.info(I18n.t('security.passcode_removed'));
    }

    function _hashPasscode(code) {
        // Simple hash — production should use crypto.subtle
        let h = 0;
        for (let i = 0; i < code.length; i++) {
            h = Math.imul(31, h) + code.charCodeAt(i) | 0;
        }
        return h.toString(16);
    }

    function _openChangePasswordModal() {
        const m = Modal.open('modalChangePassword');
        if (!m) return;
        const form = m.el.querySelector('#formChangePassword');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form));
            if (!data.new_password || data.new_password.length < 8) {
                Toast.error(I18n.t('security.password_short')); return;
            }
            if (data.new_password !== data.confirm_password) {
                Toast.error(I18n.t('security.password_mismatch')); return;
            }
            const btn = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;
            try {
                await Http.post('/auth/change-password', {
                    current_password: data.current_password,
                    new_password:     data.new_password,
                });
                Modal.close('modalChangePassword');
                Toast.success(I18n.t('security.password_changed'));
            } catch(e) {
                Toast.error(e.body?.message || I18n.t('error.generic'));
            } finally { if (btn) btn.disabled = false; }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       12. SECTION: CHAT
    ────────────────────────────────────────────────────────── */
    function _renderChat() {
        const s    = Prefs.all();
        const body = D.body;
        body.innerHTML = '';

        body.appendChild(_buildGroup(I18n.t('chat.input'), [
            _buildToggle('enter_to_send', I18n.t('chat.enter_to_send'), s.enter_to_send),
        ]));

        body.appendChild(_buildGroup(I18n.t('chat.auto_download'), [
            _buildSelect('auto_download_images', I18n.t('chat.images'), _dlOpts(), s.auto_download_images),
            _buildSelect('auto_download_videos', I18n.t('chat.videos'), _dlOpts(), s.auto_download_videos),
            _buildSelect('auto_download_files',  I18n.t('chat.files'),  _dlOpts(), s.auto_download_files),
        ]));

        body.appendChild(_buildGroup(I18n.t('chat.playback'), [
            _buildToggle('auto_play_gifs',   I18n.t('chat.auto_play_gifs'),   s.auto_play_gifs),
            _buildToggle('auto_play_videos', I18n.t('chat.auto_play_videos'),  s.auto_play_videos),
            _buildToggle('save_to_gallery',  I18n.t('chat.save_to_gallery'),   s.save_to_gallery),
        ]));
    }

    function _dlOpts() {
        return [
            { value: 'always', label: I18n.t('chat.dl_always') },
            { value: 'wifi',   label: I18n.t('chat.dl_wifi')   },
            { value: 'never',  label: I18n.t('chat.dl_never')  },
        ];
    }

    /* ──────────────────────────────────────────────────────────
       13. SECTION: LANGUAGE
    ────────────────────────────────────────────────────────── */
    function _renderLanguage() {
        const s    = Prefs.all();
        const body = D.body;
        body.innerHTML = '';

        const langs = [
            { code: 'fa', name: 'فارسی',     native: 'fa',   flag: '🇮🇷' },
            { code: 'en', name: 'English',    native: 'en',   flag: '🇬🇧' },
            { code: 'ar', name: 'العربية',    native: 'ar',   flag: '🇸🇦' },
            { code: 'tr', name: 'Türkçe',     native: 'tr',   flag: '🇹🇷' },
            { code: 'de', name: 'Deutsch',    native: 'de',   flag: '🇩🇪' },
            { code: 'fr', name: 'Français',   native: 'fr',   flag: '🇫🇷' },
            { code: 'ru', name: 'Русский',    native: 'ru',   flag: '🇷🇺' },
            { code: 'zh', name: '中文',        native: 'zh',   flag: '🇨🇳' },
            { code: 'es', name: 'Español',    native: 'es',   flag: '🇪🇸' },
        ];

        const group = Utils.el('div', { class: 'settings-group' });
        const list  = Utils.el('div', { class: 'lang-list' });

        langs.forEach(lang => {
            const item = Utils.el('div', {
                class: `lang-item${lang.code === s.locale ? ' lang-item--active' : ''}`,
                'data-code': lang.code,
            });
            item.innerHTML = `
            <span class="lang-item__flag">${lang.flag}</span>
            <span class="lang-item__name">${lang.name}</span>
            ${lang.code === s.locale
                ? `<svg class="lang-item__check" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"/>
                   </svg>`
                : ''}`;

            item.addEventListener('click', async () => {
                if (lang.code === Prefs.get('locale')) return;
                Prefs.set('locale', lang.code);
                Store.set('locale', lang.code);
                await I18n.load(lang.code);

                // Re-render to apply new language
                Toast.success(I18n.t('language.changed'));
                _renderLanguage();
                _renderSection(_state.activeSection);
            });
            list.appendChild(item);
        });

        group.appendChild(list);
        body.appendChild(group);
    }

    /* ──────────────────────────────────────────────────────────
       14. SECTION: STORAGE
    ────────────────────────────────────────────────────────── */
    async function _renderStorage() {
        const body = D.body;
        body.innerHTML = `<div class="spinner"></div>`;

        try {
            const info = await Http.get('/users/me/storage');
            _state.storageInfo = info;

            body.innerHTML = '';

            /* Usage chart */
            const total   = info.total_bytes || 1;
            const pct     = Math.min(100, Math.round((info.used_bytes / total) * 100));
            const usageEl = Utils.el('div', { class: 'storage-usage' });
            usageEl.innerHTML = `
            <div class="storage-usage__bar-wrap">
                <div class="storage-usage__bar" style="width:${pct}%"></div>
            </div>
            <div class="storage-usage__labels">
                <span>${I18n.formatFileSize(info.used_bytes)} ${I18n.t('storage.used')}</span>
                <span>${I18n.formatFileSize(total - info.used_bytes)} ${I18n.t('storage.free')}</span>
            </div>
            <div class="storage-usage__total">
                ${I18n.t('storage.total')}: ${I18n.formatFileSize(total)}
            </div>`;
            body.appendChild(usageEl);

            /* Breakdown */
            const cats = [
                { key: 'images',    icon: '🖼️', label: I18n.t('storage.images') },
                { key: 'videos',    icon: '🎬', label: I18n.t('storage.videos') },
                { key: 'audio',     icon: '🎵', label: I18n.t('storage.audio')  },
                { key: 'documents', icon: '📄', label: I18n.t('storage.docs')   },
                { key: 'cache',     icon: '⚡', label: I18n.t('storage.cache')  },
            ];

            const breakdown = _buildGroup(I18n.t('storage.breakdown'), cats.map(c => {
                const bytes = info.breakdown?.[c.key] || 0;
                const pctCat = total > 0 ? Math.round((bytes / info.used_bytes) * 100) : 0;
                const row    = Utils.el('div', { class: 'storage-cat-row' });
                row.innerHTML = `
                <span class="storage-cat-row__icon">${c.icon}</span>
                <div class="storage-cat-row__info">
                    <div class="storage-cat-row__label">${c.label}</div>
                    <div class="storage-cat-row__bar-wrap">
                        <div class="storage-cat-row__bar" style="width:${pctCat}%"></div>
                    </div>
                </div>
                <span class="storage-cat-row__size">${I18n.formatFileSize(bytes)}</span>
                <button class="btn btn--sm btn--ghost storage-cat-row__clear" data-key="${c.key}">
                    ${I18n.t('clear')}
                </button>`;
                return row;
            }));
            body.appendChild(breakdown);

            /* Clear actions */
            Utils.delegate(body, '.storage-cat-row__clear', 'click', async (e, btn) => {
                const key = btn.dataset.key;
                try {
                    await Http.delete(`/users/me/storage/${key}`);
                    Toast.success(I18n.t('storage.cleared'));
                    _renderStorage();
                } catch { Toast.error(I18n.t('error.generic')); }
            });

            /* Cache limit */
            body.appendChild(_buildGroup(I18n.t('storage.cache_limit'), [
                _buildSlider('cache_limit_mb', 100, 2000, 100,
                    Prefs.get('cache_limit_mb'),
                    v => I18n.formatFileSize(v * 1024 * 1024)),
            ]));

            /* Clear all */
            const clearAll = _buildAction(
                I18n.t('storage.clear_all'),
                I18n.t('storage.clear_all_desc'),
                I18n.t('storage.clear_all_btn'),
                () => _promptClearAll(),
                true
            );
            body.appendChild(clearAll);

        } catch {
            body.innerHTML = _tplEmpty('⚠️', I18n.t('error.generic'));
        }
    }

    function _promptClearAll() {
        const m = Modal.open('modalConfirm', {
            title:   I18n.t('storage.clear_all'),
            message: I18n.t('storage.clear_all_confirm'),
        });
        if (!m) return;
        m.el.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            Modal.close('modalConfirm');
            try {
                await Http.delete('/users/me/storage');
                Toast.success(I18n.t('storage.cleared'));
                _renderStorage();
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       15. SECTION: LINKED DEVICES
    ────────────────────────────────────────────────────────── */
    async function _renderDevices() {
        const body = D.body;
        body.innerHTML = `<div class="spinner"></div>`;

        try {
            const res  = await Http.get('/auth/sessions');
            _state.devices = res.sessions || [];
            body.innerHTML = '';

            const current = _state.devices.find(d => d.is_current);
            const others  = _state.devices.filter(d => !d.is_current);

            /* Current device */
            if (current) {
                body.appendChild(_buildGroup(I18n.t('devices.current'), [
                    _buildDeviceRow(current, false),
                ]));
            }

            /* Other devices */
            if (others.length) {
                const group = _buildGroup(I18n.t('devices.other'), others.map(d => _buildDeviceRow(d, true)));
                body.appendChild(group);

                /* Terminate all */
                const termAll = _buildAction(
                    I18n.t('devices.terminate_all'),
                    I18n.t('devices.terminate_all_desc'),
                    I18n.t('devices.terminate_btn'),
                    () => _terminateAllSessions(),
                    true
                );
                body.appendChild(termAll);
            } else if (!current) {
                body.innerHTML = _tplEmpty('📱', I18n.t('devices.no_devices'));
            }

        } catch {
            body.innerHTML = _tplEmpty('⚠️', I18n.t('error.generic'));
        }
    }

    function _buildDeviceRow(session, canTerminate) {
        const icons = {
            desktop: '💻', mobile: '📱', tablet: '📱', web: '🌐',
        };
        const row = Utils.el('div', { class: 'device-row' });
        row.innerHTML = `
        <div class="device-row__icon">${icons[session.device_type] || '📱'}</div>
        <div class="device-row__info">
            <div class="device-row__name">${Utils.escapeHtml(session.device_name || I18n.t('devices.unknown'))}</div>
            <div class="device-row__meta">
                <span>${Utils.escapeHtml(session.platform || '')}</span>
                ${session.ip ? `· <span>${Utils.escapeHtml(session.ip)}</span>` : ''}
                · <span>${I18n.formatRelative(session.last_active)}</span>
            </div>
            <div class="device-row__location">${Utils.escapeHtml(session.location || '')}</div>
        </div>
        ${session.is_current
            ? `<span class="device-row__badge">${I18n.t('devices.this_device')}</span>`
            : ''}`;

        if (canTerminate) {
            const btn = Utils.el('button', {
                class: 'btn btn--sm btn--ghost device-row__terminate',
                text:  I18n.t('devices.terminate'),
            });
            btn.addEventListener('click', () => _terminateSession(session.id));
            row.appendChild(btn);
        }
        return row;
    }

    async function _terminateSession(sessionId) {
        try {
            await Http.delete(`/auth/sessions/${sessionId}`);
            _state.devices = _state.devices.filter(d => d.id !== sessionId);
            Toast.success(I18n.t('devices.terminated'));
            _renderDevices();
        } catch { Toast.error(I18n.t('error.generic')); }
    }

    async function _terminateAllSessions() {
        const m = Modal.open('modalConfirm', {
            title:   I18n.t('devices.terminate_all'),
            message: I18n.t('devices.terminate_all_confirm'),
        });
        if (!m) return;
        m.el.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            Modal.close('modalConfirm');
            try {
                await Http.delete('/auth/sessions');
                Toast.success(I18n.t('devices.all_terminated'));
                _renderDevices();
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       16. SECTION: ACCOUNT
    ────────────────────────────────────────────────────────── */
    function _renderAccount() {
        const user = App.getUser();
        const body = D.body;
        body.innerHTML = '';

        /* Profile shortcut */
        const profileCard = Utils.el('div', { class: 'account-profile-card' });
        profileCard.innerHTML = `
        ${user?.avatar
            ? `<img class="avatar avatar--img avatar--lg" src="${Utils.escapeHtml(user.avatar)}" alt="">`
            : `<div class="avatar avatar--fallback avatar--lg" data-color="${Utils.stringToColor(user?.name||'')}">${Avatar.initials(user?.name)}</div>`}
        <div class="account-profile-card__info">
            <div class="account-profile-card__name">${Utils.escapeHtml(user?.name || '')}</div>
            <div class="account-profile-card__username">${user?.username ? '@'+user.username : ''}</div>
            <div class="account-profile-card__phone">${Utils.escapeHtml(user?.phone || '')}</div>
        </div>
        <button class="btn btn--sm btn--ghost account-profile-card__edit">
            ${I18n.t('edit')}
        </button>`;
        profileCard.querySelector('.account-profile-card__edit')
            ?.addEventListener('click', () => Profile.openEditModal());
        body.appendChild(profileCard);

        /* Account actions */
        body.appendChild(_buildGroup(I18n.t('account.data'), [
            _buildNavRow(I18n.t('account.export_data'),   () => _exportData()),
            _buildNavRow(I18n.t('account.import_data'),   () => _importData()),
        ]));

        /* Phone/email change */
        body.appendChild(_buildGroup(I18n.t('account.contact_info'), [
            _buildNavRow(I18n.t('account.change_phone'),   () => _openChangePhoneModal()),
            _buildNavRow(I18n.t('account.change_email'),   () => _openChangeEmailModal()),
        ]));

        /* Danger zone */
        body.appendChild(_buildSection(
            I18n.t('account.danger_zone'),
            '',
            [
                _buildNavRow(I18n.t('account.deactivate'), () => _promptDeactivate(), true),
                _buildNavRow(I18n.t('account.delete'),     () => _promptDelete(),     true),
            ],
            true
        ));
    }

    async function _exportData() {
        const t = Toast.loading(I18n.t('account.exporting'));
        try {
            const res = await Http.post('/users/me/export');
            t.dismiss();
            Toast.success(I18n.t('account.export_ready'), {
                action: { label: I18n.t('download'), fn: () => window.open(res.download_url, '_blank') }
            });
        } catch { t.dismiss(); Toast.error(I18n.t('error.generic')); }
    }

    function _importData() {
        const input = document.createElement('input');
        input.type  = 'file';
        input.accept= '.json,.zip';
        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            if (!file) return;
            const form = new FormData();
            form.append('file', file);
            const t = Toast.loading(I18n.t('account.importing'));
            try {
                await Http.upload('/users/me/import', form);
                t.dismiss();
                Toast.success(I18n.t('account.imported'));
            } catch { t.dismiss(); Toast.error(I18n.t('error.import_failed')); }
        });
        input.click();
    }

    function _openChangePhoneModal() {
        Modal.open('modalChangePhone');
        const form = Utils.qs('#formChangePhone');
        let  _step = 1;

        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;

            try {
                if (_step === 1) {
                    const phone = form.querySelector('[name="new_phone"]')?.value?.trim();
                    if (!phone) return;
                    await Http.post('/auth/phone/change', { phone });
                    _step = 2;
                    _renderPhoneStep2(form);
                } else {
                    const code = form.querySelector('[name="sms_code"]')?.value?.trim();
                    if (!code) return;
                    const res = await Http.post('/auth/phone/verify', { code });
                    Modal.close('modalChangePhone');
                    const user = App.getUser();
                    if (user) { user.phone = res.phone; Store.set('user', user); State.set('user', user); }
                    Toast.success(I18n.t('account.phone_changed'));
                }
            } catch(e) {
                Toast.error(e.body?.message || I18n.t('error.generic'));
            } finally { if (btn) btn.disabled = false; }
        });
    }

    function _renderPhoneStep2(form) {
        const step1 = form.querySelector('.step-1');
        const step2 = form.querySelector('.step-2');
        if (step1) step1.hidden = true;
        if (step2) step2.hidden = false;
        form.querySelector('[name="sms_code"]')?.focus();
    }

    function _openChangeEmailModal() {
        Modal.open('modalChangeEmail');
        const form = Utils.qs('#formChangeEmail');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const email = form.querySelector('[name="new_email"]')?.value?.trim();
            if (!email) return;
            const btn = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;
            try {
                await Http.post('/auth/email/change', { email });
                Modal.close('modalChangeEmail');
                Toast.success(I18n.t('account.email_verify_sent'));
            } catch(e) {
                Toast.error(e.body?.message || I18n.t('error.generic'));
            } finally { if (btn) btn.disabled = false; }
        }, { once: true });
    }

    function _promptDeactivate() {
        const m = Modal.open('modalDeactivate');
        if (!m) return;
        m.el.querySelector('[data-action="deactivate-confirm"]')?.addEventListener('click', async () => {
            Modal.close('modalDeactivate');
            try {
                await Http.post('/users/me/deactivate');
                App.logout();
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    function _promptDelete() {
        const m = Modal.open('modalDeleteAccount');
        if (!m) return;
        const confirmInput = m.el.querySelector('#deleteAccountConfirm');

        m.el.querySelector('[data-action="delete-account"]')?.addEventListener('click', async () => {
            if (confirmInput?.value !== I18n.t('account.delete_confirm_word')) {
                Toast.error(I18n.t('account.delete_type_confirm')); return;
            }
            Modal.close('modalDeleteAccount');
            try {
                await Http.delete('/users/me');
                App.logout();
            } catch { Toast.error(I18n.t('error.generic')); }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       17. SECTION: HELP
    ────────────────────────────────────────────────────────── */
    function _renderHelp() {
        const body = D.body;
        body.innerHTML = '';

        const user = App.getUser();

        /* App info */
        body.appendChild(_buildGroup(I18n.t('help.app_info'), [
            _buildInfoRow(I18n.t('help.version'),    APP_VERSION),
            _buildInfoRow(I18n.t('help.user_id'),    user?.id || '–'),
            _buildInfoRow(I18n.t('help.username'),   user?.username ? '@' + user.username : '–'),
            _buildInfoRow(I18n.t('help.platform'),   navigator.userAgent.includes('Mobile') ? 'Mobile' : 'Desktop'),
        ]));

        /* Links */
        body.appendChild(_buildGroup(I18n.t('help.resources'), [
            _buildNavRow(I18n.t('help.faq'),             () => window.open('/help', '_blank')),
            _buildNavRow(I18n.t('help.report_bug'),       () => _openBugReport()),
            _buildNavRow(I18n.t('help.privacy_policy'),   () => window.open('/privacy', '_blank')),
            _buildNavRow(I18n.t('help.terms'),            () => window.open('/terms', '_blank')),
        ]));

        /* Debug */
        if (State.get('dev_mode')) {
            body.appendChild(_buildGroup('Debug', [
                _buildAction('Clear LocalStorage', '', 'Clear', () => { Store.clear(); location.reload(); }, true),
                _buildAction('Simulate WS Message', '', 'Send', () => {
                    Socket.Debug.simulate({ type: 'new_message', chat_id: 'test', text: 'Debug msg', id: Utils.uuid(), sender_id: 'other', created_at: new Date().toISOString() });
                }),
                _buildInfoRow('WS Status',    Socket.Debug.status()),
                _buildInfoRow('Queue',        String(Socket.Debug.queueSize())),
                _buildInfoRow('Pending ACKs', String(Socket.Debug.pendingAcks())),
            ]));
        }

        /* Logout */
        const logoutBtn = Utils.el('button', {
            class: 'btn btn--full btn--danger settings-logout',
            text:  I18n.t('logout'),
        });
        logoutBtn.addEventListener('click', () => App.logout());
        body.appendChild(logoutBtn);
    }

    function _openBugReport() {
        Modal.open('modalBugReport');
        const form = Utils.qs('#formBugReport');
        form?.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form));
            const btn  = form.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;
            try {
                await Http.post('/feedback', {
                    type:    'bug',
                    message: data.message,
                    email:   data.email || App.getUser()?.email,
                    meta: {
                        version:   APP_VERSION,
                        userAgent: navigator.userAgent,
                        locale:    I18n.getLocale(),
                        wsStatus:  Socket.Debug.status(),
                    }
                });
                Modal.close('modalBugReport');
                Toast.success(I18n.t('help.report_sent'));
            } catch { Toast.error(I18n.t('error.generic')); }
            finally { if (btn) btn.disabled = false; }
        }, { once: true });
    }

    /* ──────────────────────────────────────────────────────────
       18. BUILD HELPERS — Settings UI Primitives
    ────────────────────────────────────────────────────────── */

    /** Group wrapper with optional title */
    function _buildGroup(title, children, cls = '') {
        const group = Utils.el('div', { class: `settings-group${cls ? ' ' + cls : ''}` });
        if (title) {
            group.appendChild(Utils.el('div', { class: 'settings-group__title', text: title }));
        }
        const inner = Utils.el('div', { class: 'settings-group__inner' });
        children.forEach(c => { if (c) inner.appendChild(c); });
        group.appendChild(inner);
        return group;
    }

    /** Section with title + description */
    function _buildSection(title, desc, children, danger = false) {
        const wrap = Utils.el('div', { class: `settings-section${danger ? ' settings-section--danger' : ''}` });
        if (title) wrap.appendChild(Utils.el('div', { class: 'settings-section__title', text: title }));
        if (desc)  wrap.appendChild(Utils.el('div', { class: 'settings-section__desc',  text: desc  }));
        const inner = Utils.el('div', { class: 'settings-group__inner' });
        children.forEach(c => { if (c) inner.appendChild(c); });
        wrap.appendChild(inner);
        return wrap;
    }

    /** Toggle (checkbox as switch) */
    function _buildToggle(key, label, value, opts = {}) {
        const row   = Utils.el('div', { class: `settings-row settings-row--toggle${opts.disabled ? ' disabled' : ''}` });
        const id    = 'toggle_' + key;
        const lbl   = Utils.el('label', { class: 'settings-row__label', for: id, text: label });
        const wrap  = Utils.el('div',   { class: 'settings-row__control' });
        const input = Utils.el('input', { type: 'checkbox', id, class: 'switch-input' });
        input.checked  = !!value;
        input.disabled = !!opts.disabled;

        input.addEventListener('change', () => {
            Prefs.set(key, input.checked);
            opts.onChange?.(input.checked);
            if (opts.server) _syncToServer({ [key]: input.checked });
        });

        const thumb = Utils.el('span', { class: 'switch-thumb' });
        wrap.append(input, thumb);
        row.append(lbl, wrap);
        return row;
    }

    /** Select / dropdown */
    function _buildSelect(key, label, options, value, opts = {}) {
        const row    = Utils.el('div', { class: `settings-row settings-row--select${opts.disabled ? ' disabled' : ''}` });
        const lbl    = Utils.el('div', { class: 'settings-row__label', text: label });
        const select = Utils.el('select', { class: 'settings-select' });
        select.disabled = !!opts.disabled;

        options.forEach(o => {
            const opt     = Utils.el('option', { value: o.value, text: o.label });
            opt.selected  = String(o.value) === String(value);
            select.appendChild(opt);
        });

        select.addEventListener('change', () => {
            const val = select.value;
            const typed = isNaN(val) ? val : Number(val);
            Prefs.set(key, typed);
            opts.onChange?.(typed);
            if (opts.server) _syncToServer({ [key]: typed });
        });

        const arrow = Utils.el('span', { class: 'settings-select-arrow', html: '▾' });
        const wrap  = Utils.el('div',  { class: 'settings-select-wrap' });
        wrap.append(select, arrow);
        row.append(lbl, wrap);
        return row;
    }

    /** Segmented control */
    function _buildSegment(key, options, value) {
        const wrap = Utils.el('div', { class: 'settings-segment' });
        options.forEach(opt => {
            const btn = Utils.el('button', {
                class: `settings-segment__btn${String(opt.value) === String(value) ? ' active' : ''}`,
                html:  `${opt.icon ? `<span>${opt.icon}</span>` : ''}<span>${opt.label}</span>`,
            });
            btn.addEventListener('click', () => {
                wrap.querySelectorAll('.settings-segment__btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                Prefs.set(key, opt.value);
            });
            wrap.appendChild(btn);
        });
        return wrap;
    }

    /** Range slider */
    function _buildSlider(key, min, max, step, value, formatFn, onInput) {
        const wrap  = Utils.el('div',   { class: 'settings-slider-wrap' });
        const track = Utils.el('div',   { class: 'settings-slider-track' });
        const input = Utils.el('input', {
            type:  'range',
            class: 'settings-slider',
            min:   String(min),
            max:   String(max),
            step:  String(step),
            value: String(value),
        });
        const label = Utils.el('span',  { class: 'settings-slider-val', text: formatFn ? formatFn(value) : value });

        input.addEventListener('input', () => {
            const v = Number(input.value);
            label.textContent = formatFn ? formatFn(v) : v;
            onInput?.(v);
        });
        input.addEventListener('change', () => {
            Prefs.set(key, Number(input.value));
        });

        // Fill track
        const pct = ((value - min) / (max - min)) * 100;
        input.style.setProperty('--pct', pct + '%');
        input.addEventListener('input', () => {
            const p = ((Number(input.value) - min) / (max - min)) * 100;
            input.style.setProperty('--pct', p + '%');
        });

        track.append(input);
        wrap.append(track, label);
        return wrap;
    }

    /** Color picker (accent) */
    function _buildColorPicker(colors, current) {
        const wrap = Utils.el('div', { class: 'color-picker' });
        colors.forEach(color => {
            const btn = Utils.el('button', {
                class:       `color-swatch${color === current ? ' active' : ''}`,
                'data-color': color,
                'aria-label': color,
            });
            btn.style.setProperty('--swatch',
                getComputedStyle(document.documentElement)
                    .getPropertyValue(`--accent-${color}`).trim() || color);
            btn.addEventListener('click', () => {
                wrap.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
                btn.classList.add('active');
                Prefs.set('accent', color);
            });
            wrap.appendChild(btn);
        });
        return wrap;
    }

    /** Chat background picker */
    function _buildChatBgPicker(current) {
        const options = [
            { value: 'none',     label: '⬜', style: 'background:var(--chat-bg)' },
            { value: 'dots',     label: '',   style: 'background-image:radial-gradient(var(--border) 1px,transparent 1px);background-size:16px 16px' },
            { value: 'lines',    label: '',   style: 'background-image:repeating-linear-gradient(0deg,var(--border) 0,var(--border) 1px,transparent 1px,transparent 24px)' },
            { value: 'gradient', label: '',   style: 'background:linear-gradient(135deg,#e0f7fa,#e8eaf6)' },
            { value: 'dark',     label: '',   style: 'background:#1a1a2e' },
        ];
        const wrap = Utils.el('div', { class: 'chat-bg-picker' });
        options.forEach(opt => {
            const btn = Utils.el('button', {
                class: `chat-bg-option${opt.value === current ? ' active' : ''}`,
            });
            btn.setAttribute('style', opt.style);
            if (opt.label) btn.textContent = opt.label;
            btn.addEventListener('click', () => {
                wrap.querySelectorAll('.chat-bg-option').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                Prefs.set('chat_bg', opt.value);
            });
            wrap.appendChild(btn);
        });
        return wrap;
    }

    /** Navigation row (arrow link) */
    function _buildNavRow(label, fn, danger = false) {
        const row = Utils.el('div', {
            class:    `settings-row settings-row--nav${danger ? ' settings-row--danger' : ''}`,
            role:     'button',
            tabindex: '0',
        });
        row.innerHTML = `
        <span class="settings-row__label">${Utils.escapeHtml(label)}</span>
        <svg class="settings-row__arrow" viewBox="0 0 24 24">
            <polyline points="${I18n.getDir() === 'rtl' ? '15 18 9 12 15 6' : '9 18 15 12 9 6'}"/>
        </svg>`;
        row.addEventListener('click',   fn);
        row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') fn(); });
        return row;
    }

    /** Info row (read-only label + value) */
    function _buildInfoRow(label, value) {
        const row = Utils.el('div', { class: 'settings-row settings-row--info' });
        row.innerHTML = `
        <span class="settings-row__label">${Utils.escapeHtml(label)}</span>
        <span class="settings-row__value">${Utils.escapeHtml(String(value))}</span>`;
        return row;
    }

    /** Action row (description + button) */
    function _buildAction(title, desc, btnLabel, fn, danger = false) {
        const row = Utils.el('div', { class: `settings-action-row${danger ? ' settings-action-row--danger' : ''}` });
        row.innerHTML = `
        <div class="settings-action-row__text">
            <div class="settings-action-row__title">${Utils.escapeHtml(title)}</div>
            ${desc ? `<div class="settings-action-row__desc">${Utils.escapeHtml(desc)}</div>` : ''}
        </div>`;
        const btn = Utils.el('button', {
            class: `btn btn--sm ${danger ? 'btn--danger' : 'btn--ghost'}`,
            text:  btnLabel,
        });
        btn.addEventListener('click', fn);
        row.appendChild(btn);
        return row;
    }

    /** Disable/enable a group of rows */
    function _toggleGroupDisabled(cls, disabled) {
        Utils.qsa(`.${cls} .settings-row`).forEach(row => {
            row.classList.toggle('disabled', disabled);
            row.querySelectorAll('input, select, button').forEach(el => {
                el.disabled = disabled;
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       19. SERVER SYNC
    ────────────────────────────────────────────────────────── */
    const _syncToServer = Utils.debounce(async (data) => {
        try {
            await Http.patch('/users/me/settings', data);
        } catch { /* silent — local change already applied */ }
    }, 800);

    /* ──────────────────────────────────────────────────────────
       20. TEMPLATE HELPERS
    ────────────────────────────────────────────────────────── */
    function _tplEmpty(icon, text) {
        return `<div class="settings-empty">
        <div class="settings-empty__icon">${icon}</div>
        <div class="settings-empty__text">${text}</div>
    </div>`;
    }

    /* ──────────────────────────────────────────────────────────
       21. BIND NAV
    ────────────────────────────────────────────────────────── */
    function _bindNav() {
        Utils.delegate(document, '.settings-nav-item', 'click', (e, el) => {
            const section = el.dataset.section;
            if (!section) return;
            _state.activeSection = section;
            _highlightNav(section);
            _renderSection(section);

            // Mobile: show content panel
            D.page?.classList.add('settings-page--content-open');
        });

        D.backBtn?.addEventListener('click', () => {
            D.page?.classList.remove('settings-page--content-open');
        });
    }

    /* ──────────────────────────────────────────────────────────
       22. INIT
    ────────────────────────────────────────────────────────── */
    function init() {
        _applyAllSettings();
        _bindNav();

        EventBus.on('page:settings', ({ section } = {}) => open(section || 'appearance'));

        // Keyboard: Esc closes settings
        EventBus.on('ui:escape', () => {
            const page = D.page;
            if (page && !page.hidden) close();
        });

        // Sync when coming back online
        EventBus.on('connection:reconnect', () => {
            _syncToServer(Prefs.all());
        });

        console.log('[Settings] Initialized');
    }

    /* ──────────────────────────────────────────────────────────
       PUBLIC API
    ────────────────────────────────────────────────────────── */
    return {
        init,
        open,
        close,
        Prefs,
        get section() { return _state.activeSection; },
    };

})();

window.Settings = Settings;

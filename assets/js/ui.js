/* ============================================================
   UI.JS  —  Render, modals, toasts, context menu,
             auth screens, share target, media viewer
   ============================================================ */

import {
    App, api, emit, on, $, $$, el,
    escapeHtml, buildAvatar, formatDate, navigate,
    saveAuth, isLoggedIn, logout, storage,
} from './app.js';

/* ──────────────────────────────────────────────────────────
   1. AUTH SCREENS
────────────────────────────────────────────────────────── */
export function renderAuth() {
    document.body.innerHTML = `
        <div class="auth-screen" id="authScreen">
            <div class="auth-card" id="authCard">
                <div class="auth-logo">🧂</div>
                <h1 class="auth-title">Namak</h1>
                <p class="auth-sub">پیام‌رسان سریع و خصوصی</p>

                <div id="authStepPhone">
                    <label class="auth-label" for="phoneInput">شماره موبایل</label>
                    <div class="auth-phone-row">
                        <select id="countryCode" class="auth-country-code">
                            <option value="+98">🇮🇷 +98</option>
                            <option value="+1">🇺🇸 +1</option>
                            <option value="+44">🇬🇧 +44</option>
                            <option value="+49">🇩🇪 +49</option>
                            <option value="+971">🇦🇪 +971</option>
                            <option value="+90">🇹🇷 +90</option>
                        </select>
                        <input type="tel" id="phoneInput" class="input auth-input"
                               placeholder="9123456789" autocomplete="tel" maxlength="12" dir="ltr">
                    </div>
                    <button class="btn btn--primary btn--full" id="btnSendOtp">ادامه</button>
                    <p class="auth-note">با ادامه، شرایط استفاده را می‌پذیرید.</p>
                </div>

                <div id="authStepOtp" class="hidden">
                    <p class="auth-otp-info" id="authOtpInfo"></p>
                    <div class="auth-otp-inputs" id="otpInputs">
                        ${Array(6).fill('<input type="text" class="auth-otp-digit input" maxlength="1" inputmode="numeric" pattern="[0-9]">').join('')}
                    </div>
                    <div class="auth-timer" id="authTimer">۰۰:۵۹</div>
                    <button class="btn btn--primary btn--full" id="btnVerifyOtp">تأیید</button>
                    <button class="btn btn--text"    id="btnResendOtp">ارسال مجدد</button>
                    <button class="btn btn--ghost"   id="btnBackPhone">← تغییر شماره</button>
                </div>

                <div id="authStepName" class="hidden">
                    <p class="auth-otp-info">یک اسم برای خودت انتخاب کن 👋</p>
                    <input type="text" id="nameInput" class="input auth-input"
                           placeholder="اسم و فامیل" autocomplete="name" maxlength="64">
                    <button class="btn btn--primary btn--full" id="btnSetName">شروع</button>
                </div>

                <div id="authLoading" class="hidden flex-center" style="padding:32px">
                    <div class="spinner spinner--lg"></div>
                </div>
            </div>
        </div>
    `;

    _injectAuthStyles();
    _bindAuthEvents();
}

function _injectAuthStyles() {
    if (document.getElementById('authStyles')) return;
    const style = document.createElement('style');
    style.id    = 'authStyles';
    style.textContent = `
        .auth-screen {
            min-height: 100dvh; display: flex; align-items: center;
            justify-content: center; padding: 24px;
            background: linear-gradient(135deg, var(--bg-app) 0%, var(--brand-alpha-10) 100%);
        }
        .auth-card {
            background: var(--bg-surface); border-radius: var(--radius-2xl);
            padding: 48px 36px; width: 100%; max-width: 380px;
            box-shadow: var(--shadow-xl); display: flex; flex-direction: column; gap: 16px;
        }
        .auth-logo   { font-size: 56px; text-align: center; }
        .auth-title  { text-align: center; font-size: var(--font-size-2xl); }
        .auth-sub    { text-align: center; color: var(--text-tertiary); font-size: var(--font-size-sm); margin-top: -8px; }
        .auth-label  { font-size: var(--font-size-sm); color: var(--text-secondary); }
        .auth-phone-row { display: flex; gap: 8px; }
        .auth-country-code { padding: 10px 12px; border-radius: var(--radius-lg); background: var(--bg-input); font-size: var(--font-size-sm); cursor: pointer; border: 1px solid transparent; }
        .auth-input  { font-size: var(--font-size-lg); }
        .auth-note   { font-size: var(--font-size-xs); color: var(--text-tertiary); text-align: center; }
        .auth-otp-info { font-size: var(--font-size-sm); color: var(--text-secondary); text-align: center; }
        .auth-otp-inputs { display: flex; gap: 10px; justify-content: center; }
        .auth-otp-digit  { width: 44px; height: 52px; text-align: center; font-size: var(--font-size-xl); font-weight: 700; border-radius: var(--radius-lg); }
        .auth-timer  { text-align: center; font-size: var(--font-size-sm); color: var(--text-tertiary); }
    `;
    document.head.appendChild(style);
}

let _authPhone = '';

function _bindAuthEvents() {
    const btnSendOtp   = $('#btnSendOtp');
    const btnVerifyOtp = $('#btnVerifyOtp');
    const btnResendOtp = $('#btnResendOtp');
    const btnBackPhone = $('#btnBackPhone');
    const btnSetName   = $('#btnSetName');
    const phoneInput   = $('#phoneInput');

    btnSendOtp?.addEventListener('click', _sendOtp);
    phoneInput?.addEventListener('keydown', e => { if (e.key === 'Enter') _sendOtp(); });

    btnVerifyOtp?.addEventListener('click', _verifyOtp);
    btnResendOtp?.addEventListener('click', () => { _sendOtp(); });
    btnBackPhone?.addEventListener('click', () => {
        $('#authStepOtp')?.classList.add('hidden');
        $('#authStepPhone')?.classList.remove('hidden');
    });

    btnSetName?.addEventListener('click', _setName);

    // OTP digit jump
    $$('.auth-otp-digit').forEach((inp, i, all) => {
        inp.addEventListener('input', () => {
            if (inp.value && i < all.length - 1) all[i + 1].focus();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value && i > 0) all[i - 1].focus();
        });
    });
}

async function _sendOtp() {
    const code   = $('#countryCode')?.value || '+98';
    const number = $('#phoneInput')?.value?.trim().replace(/\D/g, '');
    if (!number || number.length < 8) { showToast('شماره موبایل نامعتبر است', 'error'); return; }

    _authPhone = `${code}${number}`;
    _showAuthStep('loading');

    try {
        await api('POST', '/auth/send-otp', { phone: _authPhone });
        _showAuthStep('otp');
        $('#authOtpInfo').textContent = `کد تأیید به ${_authPhone} ارسال شد`;
        $$('.auth-otp-digit')[0]?.focus();
        _startOtpTimer();
    } catch (err) {
        showToast(err.data?.error || 'خطا در ارسال کد', 'error');
        _showAuthStep('phone');
    }
}
async function _verifyOtp() {
    const code = $$('.auth-otp-digit').map(i => i.value).join('');
    if (code.length !== 6) { showToast('کد ۶ رقمی را وارد کنید', 'error'); return; }

    _showAuthStep('loading');

    try {
        const data = await api('POST', '/auth/verify-otp', {
            phone: _authPhone,
            code,
        });

        saveAuth(data);

        if (data.is_new_user) {
            _showAuthStep('name');
            $('#nameInput')?.focus();
        } else {
            location.reload();
        }
    } catch (err) {
        showToast(err.data?.error || 'کد نادرست است', 'error');
        _showAuthStep('otp');
        $$('.auth-otp-digit').forEach(i => { i.value = ''; });
        $$('.auth-otp-digit')[0]?.focus();
    }
}

async function _setName() {
    const name = $('#nameInput')?.value?.trim();
    if (!name || name.length < 2) { showToast('نام باید حداقل ۲ حرف باشد', 'error'); return; }

    _showAuthStep('loading');
    try {
        await api('PATCH', '/users/me', { name });
        App.user.name = name;
        location.reload();
    } catch {
        showToast('خطا در ذخیره نام', 'error');
        _showAuthStep('name');
    }
}

function _showAuthStep(step) {
    const steps = { phone: '#authStepPhone', otp: '#authStepOtp', name: '#authStepName', loading: '#authLoading' };
    Object.entries(steps).forEach(([k, sel]) => {
        $(sel)?.classList.toggle('hidden', k !== step);
    });
}

let _otpTimerInterval = null;
function _startOtpTimer() {
    clearInterval(_otpTimerInterval);
    let remaining = 59;
    const timerEl = $('#authTimer');
    const resend  = $('#btnResendOtp');
    if (resend) resend.disabled = true;

    _otpTimerInterval = setInterval(() => {
        remaining--;
        if (timerEl) timerEl.textContent = `۰${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
        if (remaining <= 0) {
            clearInterval(_otpTimerInterval);
            if (timerEl) timerEl.textContent = '';
            if (resend)  resend.disabled = false;
        }
    }, 1000);
}

/* ──────────────────────────────────────────────────────────
   2. MAIN APP RENDER
────────────────────────────────────────────────────────── */
export function renderApp() {
    document.body.innerHTML = `
        <div id="app" data-theme="${App.settings.theme}">

            <!-- SIDEBAR -->
            <aside class="sidebar" id="sidebar" role="complementary">
                <div class="sidebar__header">
                    <button class="sidebar__header-btn" id="btnMyProfile" title="پروفایل من">
                        <div id="myAvatarWrap"></div>
                    </button>
                    <span class="sidebar__logo">Namak 🧂</span>
                    <div class="sidebar__header-actions">
                        <span class="connection-dot" id="connectionDot" title="متصل"></span>
                        <button class="sidebar__header-btn" id="btnNewChat"    title="چت جدید">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                <line x1="12" y1="8" x2="12" y2="14"/><line x1="9" y1="11" x2="15" y2="11"/>
                            </svg>
                        </button>
                        <button class="sidebar__header-btn" id="btnSettings"  title="تنظیمات">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="sidebar__search">
                    <div class="sidebar__search-wrap">
                        <svg class="sidebar__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input type="search" id="searchInput" class="sidebar__search-input"
                               placeholder="جستجو..." autocomplete="off">
                        <button class="sidebar__search-clear hidden" id="searchClear">✕</button>
                    </div>
                </div>

                <div class="sidebar-filter-bar" id="filterBar">
                    <button class="filter-chip active" data-filter="all">همه</button>
                    <button class="filter-chip" data-filter="unread">خوانده‌نشده</button>
                    <button class="filter-chip" data-filter="groups">گروه‌ها</button>
                    <button class="filter-chip" data-filter="channels">کانال‌ها</button>
                    <button class="filter-chip" data-filter="personal">پیام‌های من</button>
                </div>

                <div class="sidebar__body">
                    <div class="chat-list" id="chatList" role="list"></div>
                </div>
            </aside>

            <!-- CHAT AREA -->
            <main class="chat-area" id="chatArea" role="main">
                <div class="welcome-screen" id="welcomeScreen">
                    <div class="welcome-screen__icon">🧂</div>
                    <div class="welcome-screen__title">Namak Messenger</div>
                    <div class="welcome-screen__text">یک گفتگو را انتخاب کنید یا چت جدیدی شروع کنید</div>
                </div>
            </main>

            <!-- PROFILE PANEL (right side) -->
            <aside class="profile-panel" id="profilePanel" aria-label="پروفایل"></aside>

        </div>

        <!-- TOAST CONTAINER -->
        <div id="toast-container" aria-live="assertive" aria-atomic="true"></div>
    `;

    /* My avatar in sidebar */
    const wrap = $('#myAvatarWrap');
    if (wrap && App.user) wrap.appendChild(buildAvatar(App.user, 'sm'));

    _bindAppEvents();
    _injectConnectionDotStyle();
}

function _injectConnectionDotStyle() {
    if (document.getElementById('connDotStyle')) return;
    const s = document.createElement('style');
    s.id = 'connDotStyle';
    s.textContent = `
        .connection-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--success); display: inline-block;
            transition: background .3s;
        }
        .connection-dot--connecting  { background: var(--warning); animation: blink 1s infinite; }
        .connection-dot--disconnected{ background: var(--danger);  }
        .connection-dot--offline     { background: var(--text-tertiary); }
        .connection-dot--connected   { background: var(--success); }
    `;
    document.head.appendChild(s);
}

function _bindAppEvents() {
    /* Search */
    const searchInput = $('#searchInput');
    const searchClear = $('#searchClear');
    let searchTimer;

    searchInput?.addEventListener('input', () => {
        const q = searchInput.value.trim();
        searchClear?.classList.toggle('hidden', !q);
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => _doSearch(q), 280);
    });
    searchClear?.addEventListener('click', () => {
        searchInput.value = '';
        searchClear.classList.add('hidden');
        _doSearch('');
    });

    /* Filter chips */
    $('#filterBar')?.addEventListener('click', e => {
        const chip = e.target.closest('.filter-chip');
        if (!chip) return;
        $$('.filter-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        _filterChats(chip.dataset.filter);
    });

    /* New chat */
    $('#btnNewChat')?.addEventListener('click', () => emit('newchat:open', {}));

    /* Settings */
    $('#btnSettings')?.addEventListener('click', () => {
        import('./settings.js').then(m => m.openSettings());
    });

    /* My profile */
    $('#btnMyProfile')?.addEventListener('click', () => {
        import('./profile.js').then(m => m.openMyProfile());
    });

    /* Profile panel events */
    on('profile:open', async e => {
        const { chat } = e.detail;
        import('./profile.js').then(m => m.openChatProfile(chat));
    });

    /* Media viewer */
    on('media:open', e => openMediaViewer(e.detail.msg));

    /* New chat modal */
    on('newchat:open', () => _showNewChatModal());

    /* Forward */
    on('msg:forward', e => _showForwardModal(e.detail));

    /* Socket status */
    on('socket:status', e => {
        const dot = $('#connectionDot');
        if (dot) dot.className = `connection-dot connection-dot--${e.detail.status}`;
    });
}

/* ──────────────────────────────────────────────────────────
   3. SEARCH
────────────────────────────────────────────────────────── */
async function _doSearch(q) {
    const chatList = $('#chatList');
    if (!q) {
        const { loadChats } = await import('./chat.js');
        loadChats();
        return;
    }

    const lower = q.toLowerCase();
    const items = $$('.chat-item', chatList);
    items.forEach(item => {
        const name = item.querySelector('.chat-item__name')?.textContent.toLowerCase() || '';
        item.style.display = name.includes(lower) ? '' : 'none';
    });

    /* Also search users/messages via API */
    try {
        const data = await api('GET', `/users/search?q=${encodeURIComponent(q)}&limit=10`);
        if (data?.users?.length) {
            _appendSearchResults(chatList, data.users);
        }
    } catch {}
}

function _appendSearchResults(container, users) {
    const existing = container.querySelector('.search-results-section');
    existing?.remove();

    const section = el('div', { class: 'search-results-section' });
    section.appendChild(el('div', { class: 'chat-section-header', html: 'کاربران' }));

    for (const user of users) {
        const row = el('div', {
            class:   'user-search-item',
            onclick: () => _startDirectChat(user.id),
        });
        row.appendChild(buildAvatar(user, 'md'));
        const info = el('div', { class: 'user-search-item__info' });
        info.appendChild(el('div', { class: 'user-search-item__name', html: escapeHtml(user.name) }));
        if (user.username) info.appendChild(el('div', { class: 'user-search-item__tag', html: `@${user.username}` }));
        row.appendChild(info);
        section.appendChild(row);
    }
    container.appendChild(section);
}

async function _startDirectChat(userId) {
    try {
        const chat = await api('POST', '/chats/direct', { user_id: userId });
        App.chats.set(chat.id, chat);
        const { openChat } = await import('./chat.js');
        openChat(chat.id);
    } catch (err) {
        showToast(err.data?.error || 'خطا', 'error');
    }
}

/* ──────────────────────────────────────────────────────────
   4. FILTER
────────────────────────────────────────────────────────── */
function _filterChats(filter) {
    $$('.chat-item').forEach(item => {
        const type   = item.dataset.chatType || 'direct';
        const badge  = item.querySelector('.chat-item__badge');
        const unread = badge ? parseInt(badge.textContent) > 0 : false;

        let show = true;
        switch (filter) {
            case 'unread':   show = unread;                        break;
            case 'groups':   show = type === 'group';              break;
            case 'channels': show = type === 'channel';            break;
            case 'personal': show = type === 'saved';              break;
            default:         show = true;
        }
        item.style.display = show ? '' : 'none';
    });
}

/* ──────────────────────────────────────────────────────────
   5. TOAST
────────────────────────────────────────────────────────── */
let toastCount = 0;

export function showToast(message, type = 'info', duration = 3500, action = null) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const id   = ++toastCount;
    const toast = el('div', { class: `toast toast--${type}`, id: `toast-${id}`, role: 'alert' });

    const icons = { info: 'ℹ️', success: '✅', error: '❌', warning: '⚠️', loading: '⏳' };
    toast.appendChild(el('span', { html: icons[type] || 'ℹ️' }));
    toast.appendChild(el('span', { html: escapeHtml(message) }));

    if (action) {
        const actionBtn = el('button', { class: 'toast__action', html: action.label });
        actionBtn.addEventListener('click', () => { action.fn(); _removeToast(id); });
        toast.appendChild(actionBtn);
    }

    container.appendChild(toast);

    if (type !== 'loading') {
        setTimeout(() => _removeToast(id), duration);
    }

    return id;
}

export function dismissToast(id) { _removeToast(id); }

function _removeToast(id) {
    const toast = document.getElementById(`toast-${id}`);
    if (!toast) return;
    toast.classList.add('toast--out');
    setTimeout(() => toast.remove(), 200);
}

/* ──────────────────────────────────────────────────────────
   6. CONFIRM DIALOG
────────────────────────────────────────────────────────── */
export function showConfirm(title, message, confirmLabel = 'تأیید', danger = true) {
    return new Promise(resolve => {
        const overlay = el('div', { class: 'modal-overlay' });
        const modal   = el('div', { class: 'modal' });

        const header = el('div', { class: 'modal__header' });
        header.appendChild(el('h2', { class: 'modal__title', html: escapeHtml(title) }));

        const body = el('div', { class: 'modal__body' });
        body.appendChild(el('p', { html: escapeHtml(message) }));

        const footer = el('div', { class: 'modal__footer' });
        const cancelBtn  = el('button', { class: 'btn btn--ghost' }, 'لغو');
        const confirmBtn = el('button', { class: `btn btn--${danger ? 'danger' : 'primary'}` }, confirmLabel);

        cancelBtn.addEventListener('click',  () => { _closeModal(overlay); resolve(false); });
        confirmBtn.addEventListener('click', () => { _closeModal(overlay); resolve(true);  });

        footer.appendChild(cancelBtn);
        footer.appendChild(confirmBtn);
        modal.appendChild(header);
        modal.appendChild(body);
        modal.appendChild(footer);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', e => {
            if (e.target === overlay) { _closeModal(overlay); resolve(false); }
        });

        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape') { _closeModal(overlay); resolve(false); document.removeEventListener('keydown', handler); }
            if (e.key === 'Enter')  { _closeModal(overlay); resolve(true);  document.removeEventListener('keydown', handler); }
        });

        setTimeout(() => confirmBtn.focus(), 50);
    });
}

/* ──────────────────────────────────────────────────────────
   7. MODAL HELPERS
────────────────────────────────────────────────────────── */
export function showModal({ title, content, footer, size = 'md', onClose } = {}) {
    const overlay = el('div', { class: 'modal-overlay' });
    const modal   = el('div', { class: `modal modal--${size}` });

    if (title) {
        const header   = el('div', { class: 'modal__header' });
        const titleEl  = el('h2', { class: 'modal__title', html: escapeHtml(title) });
        const closeBtn = el('button', { class: 'modal__close btn--icon', html: '✕' });
        closeBtn.addEventListener('click', () => { _closeModal(overlay); onClose?.(); });
        header.appendChild(titleEl);
        header.appendChild(closeBtn);
        modal.appendChild(header);
    }

    if (content) {
        const body = el('div', { class: 'modal__body' });
        if (typeof content === 'string') body.innerHTML = content;
        else body.appendChild(content);
        modal.appendChild(body);
    }

    if (footer) {
        const footerEl = el('div', { class: 'modal__footer' });
        if (typeof footer === 'string') footerEl.innerHTML = footer;
        else footerEl.appendChild(footer);
        modal.appendChild(footerEl);
    }

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => {
        if (e.target === overlay) { _closeModal(overlay); onClose?.(); }
    });

    on('modal:close', () => { _closeModal(overlay); onClose?.(); });

    return {
        close: () => { _closeModal(overlay); onClose?.(); },
        modal,
        overlay,
    };
}

function _closeModal(overlay) {
    overlay.classList.add('closing');
    setTimeout(() => overlay.remove(), 300);
}

/* ──────────────────────────────────────────────────────────
   8. CONTEXT MENU
────────────────────────────────────────────────────────── */
let _activeCtxMenu = null;

export function showContextMenu(x, y, items) {
    _activeCtxMenu?.remove();

    const menu = el('div', { class: 'ctx-menu' });

    for (const item of items) {
        if (item.separator) {
            menu.appendChild(el('div', { class: 'ctx-menu-separator' }));
            continue;
        }
        const row = el('div', {
            class:   `ctx-menu-item${item.danger ? ' ctx-menu-item--danger' : ''}`,
            onclick: () => { menu.remove(); item.action?.(); },
        });
        if (item.icon) row.appendChild(el('span', { html: item.icon }));
        row.appendChild(el('span', { html: escapeHtml(item.label) }));
        menu.appendChild(row);
    }

    /* Smart positioning */
    document.body.appendChild(menu);
    const rect = menu.getBoundingClientRect();
    const vw   = window.innerWidth;
    const vh   = window.innerHeight;

    menu.style.left = `${Math.min(x, vw - rect.width  - 8)}px`;
    menu.style.top  = `${Math.min(y, vh - rect.height - 8)}px`;

    _activeCtxMenu = menu;

    /* Close on outside click */
    setTimeout(() => {
        document.addEventListener('click', function handler() {
            menu.remove();
            _activeCtxMenu = null;
            document.removeEventListener('click', handler);
        });
        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape') { menu.remove(); _activeCtxMenu = null; document.removeEventListener('keydown', handler); }
        });
    }, 10);
}

/* ──────────────────────────────────────────────────────────
   9. NEW CHAT MODAL
────────────────────────────────────────────────────────── */
async function _showNewChatModal() {
    const { contacts } = await import('./contacts.js');
    const content = el('div');
    const searchInput = el('input', { type: 'search', class: 'input', placeholder: 'جستجوی مخاطب یا شماره...', style: 'margin-bottom:12px' });
    const list    = el('div', { class: 'contact-picker-list' });

    content.appendChild(searchInput);
    content.appendChild(list);

    /* Load contacts */
    const loadContacts = async (q = '') => {
        list.innerHTML = '<div class="spinner spinner--sm" style="margin:24px auto"></div>';
        try {
            const params = q ? `?q=${encodeURIComponent(q)}` : '?limit=50';
            const data   = await api('GET', `/contacts${params}`);
            list.innerHTML = '';
            if (!data.contacts?.length) {
                list.innerHTML = '<div class="empty-state"><span class="empty-state__icon">👥</span><span class="empty-state__text">مخاطبی یافت نشد</span></div>';
                return;
            }
            for (const contact of data.contacts) {
                const row = el('div', { class: 'contact-picker-item', onclick: async () => {
                        modal.close();
                        await _startDirectChat(contact.target_id);
                    }});
                row.appendChild(buildAvatar(contact.target || { id: contact.target_id, name: contact.name }, 'md'));
                const info = el('div', { class: 'contact-picker-item__info' });
                info.appendChild(el('div', { class: 'contact-picker-item__name', html: escapeHtml(contact.name || contact.target?.name || '') }));
                if (contact.target?.username) info.appendChild(el('div', { class: 'contact-picker-item__sub', html: `@${contact.target.username}` }));
                row.appendChild(info);
                list.appendChild(row);
            }
        } catch {
            list.innerHTML = '<div class="empty-state"><span class="empty-state__text">خطا در بارگذاری</span></div>';
        }
    };

    loadContacts();

    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadContacts(searchInput.value.trim()), 280);
    });

    const footerRow = el('div', { class: 'flex gap-3' });
    const btnGroup   = el('button', { class: 'btn btn--ghost flex-1', onclick: () => { modal.close(); _showCreateGroupModal(); } }, '👥 گروه جدید');
    const btnChannel = el('button', { class: 'btn btn--ghost flex-1', onclick: () => { modal.close(); _showCreateChannelModal(); } }, '📢 کانال جدید');
    footerRow.appendChild(btnGroup);
    footerRow.appendChild(btnChannel);

    const modal = showModal({ title: 'چت جدید', content, footer: footerRow });
    setTimeout(() => searchInput.focus(), 100);
}

/* ──────────────────────────────────────────────────────────
   10. CREATE GROUP MODAL
────────────────────────────────────────────────────────── */
async function _showCreateGroupModal() {
    const selectedIds = new Set();
    const content     = el('div', { style: 'display:flex;flex-direction:column;gap:12px' });

    const nameInput = el('input', { type: 'text',   class: 'input', placeholder: 'نام گروه', maxlength: '128' });
    const searchInput = el('input', { type: 'search', class: 'input', placeholder: 'جستجوی مخاطب...' });
    const chipStrip  = el('div', { id: 'groupSelectedChips' });
    const list       = el('div', { class: 'contact-picker-list', style: 'max-height:240px;overflow-y:auto' });

    content.appendChild(nameInput);
    content.appendChild(chipStrip);
    content.appendChild(searchInput);
    content.appendChild(list);

    const loadContacts = async (q = '') => {
        list.innerHTML = '';
        const params = q ? `?q=${encodeURIComponent(q)}` : '?limit=50';
        const data   = await api('GET', `/contacts${params}`).catch(() => ({ contacts: [] }));

        for (const c of (data.contacts || [])) {
            const id   = c.target_id;
            const name = c.name || c.target?.name || '';
            const row  = el('div', { class: `contact-picker-item${selectedIds.has(id) ? ' contact-picker-item--selected' : ''}` });
            row.appendChild(buildAvatar(c.target || { id, name }, 'md'));
            const info = el('div', { class: 'contact-picker-item__info' });
            info.appendChild(el('div', { class: 'contact-picker-item__name', html: escapeHtml(name) }));
            row.appendChild(info);
            const check = el('div', { class: 'contact-picker-item__check' });
            row.appendChild(check);
            row.addEventListener('click', () => {
                if (selectedIds.has(id)) {
                    selectedIds.delete(id);
                    row.classList.remove('contact-picker-item--selected');
                    chipStrip.querySelector(`[data-id="${id}"]`)?.remove();
                } else {
                    selectedIds.add(id);
                    row.classList.add('contact-picker-item--selected');
                    const chip = el('div', { class: 'chip', 'data-id': id });
                    chip.appendChild(el('span', { html: escapeHtml(name) }));
                    const rm = el('button', { class: 'chip__remove', html: '✕' });
                    rm.addEventListener('click', e => { e.stopPropagation(); selectedIds.delete(id); chip.remove(); row.classList.remove('contact-picker-item--selected'); });
                    chip.appendChild(rm);
                    chipStrip.appendChild(chip);
                }
            });
            list.appendChild(row);
        }
    };

    loadContacts();
    let st;
    searchInput.addEventListener('input', () => { clearTimeout(st); st = setTimeout(() => loadContacts(searchInput.value.trim()), 280); });

    const footer  = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const createBtn = el('button', { class: 'btn btn--primary' }, 'ساخت گروه');

    createBtn.addEventListener('click', async () => {
        const name = nameInput.value.trim();
        if (!name)              { showToast('نام گروه الزامی است', 'error'); return; }
        if (selectedIds.size < 1) { showToast('حداقل یک عضو انتخاب کنید', 'error'); return; }
        createBtn.disabled = true;
        createBtn.textContent = '...';
        try {
            const chat = await api('POST', '/chats/group', { title: name, member_ids: [...selectedIds] });
            App.chats.set(chat.id, chat);
            modal.close();
            const { openChat } = await import('./chat.js');
            openChat(chat.id);
            showToast('گروه ساخته شد ✅', 'success');
        } catch (err) {
            showToast(err.data?.error || 'خطا در ساخت گروه', 'error');
            createBtn.disabled = false;
            createBtn.textContent = 'ساخت گروه';
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(createBtn);

    const modal = showModal({ title: 'گروه جدید', content, footer });
    setTimeout(() => nameInput.focus(), 100);
}

async function _showCreateChannelModal() {
    const content    = el('div', { style: 'display:flex;flex-direction:column;gap:12px' });
    const nameInput  = el('input', { type: 'text', class: 'input', placeholder: 'نام کانال', maxlength: '128' });
    const descInput  = el('textarea', { class: 'input', placeholder: 'توضیحات (اختیاری)', rows: '3', maxlength: '500' });
    const typeToggle = el('div', { class: 'settings-segment', style: 'margin-top:4px' });

    let isPublic = false;
    const btnPrivate = el('button', { class: 'settings-segment__btn active' }, '🔒 خصوصی');
    const btnPublic  = el('button', { class: 'settings-segment__btn' }, '🌐 عمومی');
    btnPrivate.addEventListener('click', () => { isPublic = false; btnPrivate.classList.add('active'); btnPublic.classList.remove('active'); });
    btnPublic.addEventListener('click',  () => { isPublic = true;  btnPublic.classList.add('active');  btnPrivate.classList.remove('active'); });
    typeToggle.appendChild(btnPrivate);
    typeToggle.appendChild(btnPublic);

    content.appendChild(nameInput);
    content.appendChild(descInput);
    content.appendChild(typeToggle);

    const footer    = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const createBtn = el('button', { class: 'btn btn--primary' }, 'ساخت کانال');

    createBtn.addEventListener('click', async () => {
        const title = nameInput.value.trim();
        if (!title) { showToast('نام کانال الزامی است', 'error'); return; }
        createBtn.disabled = true;
        try {
            const chat = await api('POST', '/chats/channel', { title, description: descInput.value.trim(), is_public: isPublic });
            App.chats.set(chat.id, chat);
            modal.close();
            const { openChat } = await import('./chat.js');
            openChat(chat.id);
            showToast('کانال ساخته شد ✅', 'success');
        } catch (err) {
            showToast(err.data?.error || 'خطا', 'error');
            createBtn.disabled = false;
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(createBtn);
    const modal = showModal({ title: 'کانال جدید', content, footer });
    setTimeout(() => nameInput.focus(), 100);
}

/* ──────────────────────────────────────────────────────────
   11. FORWARD MODAL
────────────────────────────────────────────────────────── */
async function _showForwardModal(msg) {
    const selectedIds = new Set();
    const list        = el('div', { class: 'forward-modal-list' });
    list.innerHTML    = '<div class="spinner spinner--sm" style="margin:24px auto"></div>';

    const footer    = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const sendBtn   = el('button', { class: 'btn btn--primary', disabled: true }, 'ارسال');

    footer.appendChild(cancelBtn);
    footer.appendChild(sendBtn);
    const modal = showModal({ title: 'فوروارد پیام', content: list, footer });

    try {
        const data = await api('GET', '/chats?limit=50');
        list.innerHTML = '';

        for (const chat of (data.chats || [])) {
            const row = el('div', {
                class:   'forward-item',
                onclick: () => {
                    if (selectedIds.has(chat.id)) { selectedIds.delete(chat.id); row.classList.remove('forward-item--selected'); }
                    else                           { selectedIds.add(chat.id);    row.classList.add('forward-item--selected'); }
                    sendBtn.disabled = selectedIds.size === 0;
                },
            });
            const avatarUser = chat.type === 'direct' ? chat.other_user : { id: chat.id, name: chat.title, avatar: chat.avatar, color: chat.color };
            row.appendChild(buildAvatar(avatarUser || { id: chat.id, name: chat.title }, 'md'));
            const info = el('div', { class: 'forward-item__info' });
            info.appendChild(el('div', { class: 'forward-item__name', html: escapeHtml(chat.title || chat.other_user?.name || '') }));
            row.appendChild(info);
            row.appendChild(el('div', { class: 'forward-item__check' }));
            list.appendChild(row);
        }
    } catch {
        list.innerHTML = '<div class="empty-state"><span class="empty-state__text">خطا در بارگذاری</span></div>';
    }

    sendBtn.addEventListener('click', async () => {
        sendBtn.disabled = true;
        for (const chatId of selectedIds) {
            await api('POST', '/messages', {
                chat_id:         chatId,
                type:            msg.type,
                text:            msg.text,
                media_url:       msg.media_url,
                forward_from_id: msg.id,
                forward_chat_id: msg.chat_id,
            }).catch(() => {});
        }
        modal.close();
        showToast('پیام فوروارد شد ✅', 'success');
    });
}

/* ──────────────────────────────────────────────────────────
   12. MEDIA VIEWER
────────────────────────────────────────────────────────── */
export function openMediaViewer(msg) {
    const overlay = el('div', { class: 'media-viewer media-viewer--open' });

    const isVideo = msg.type === 'video';
    const media   = isVideo
        ? el('video', { class: 'media-viewer__video', src: msg.media_url, controls: '', autoplay: '', playsinline: '' })
        : el('img',   { class: 'media-viewer__img',   src: msg.media_url, alt: 'تصویر' });

    /* Buttons */
    const closeBtn = el('button', { class: 'media-viewer__close', title: 'بستن', html: '✕' });
    const dlBtn    = el('a', {
        class:    'media-viewer__dl',
        href:     msg.media_url,
        download: msg.file_name || '',
        title:    'دانلود',
        html:     '⬇',
    });
    const counter  = el('div', { class: 'media-viewer__counter', html: formatDate(msg.created_at) });

    overlay.appendChild(media);
    overlay.appendChild(closeBtn);
    overlay.appendChild(dlBtn);
    overlay.appendChild(counter);
    document.body.appendChild(overlay);
    document.body.classList.add('media-viewer-open');

    const close = () => {
        overlay.style.animation = 'fadeOut 180ms ease forwards';
        setTimeout(() => { overlay.remove(); document.body.classList.remove('media-viewer-open'); }, 180);
    };

    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function handler(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', handler); }
    });

    /* Pinch-to-zoom (touch) */
    let scale = 1, lastDist = 0;
    overlay.addEventListener('touchmove', e => {
        if (e.touches.length === 2) {
            const dist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            if (lastDist) scale = Math.min(4, Math.max(1, scale * (dist / lastDist)));
            media.style.transform = `scale(${scale})`;
            lastDist = dist;
        }
    }, { passive: true });
    overlay.addEventListener('touchend', () => { lastDist = 0; });
    overlay.addEventListener('dblclick', () => {
        scale = scale > 1 ? 1 : 2;
        media.style.transition = 'transform .2s ease';
        media.style.transform  = `scale(${scale})`;
        setTimeout(() => media.style.transition = '', 200);
    });
}

/* ──────────────────────────────────────────────────────────
   13. SHARE TARGET HANDLER
────────────────────────────────────────────────────────── */
export async function handleShareTarget() {
    try {
        const cache = await caches.open('namak-share-store');
        const res   = await cache.match('/share-data');
        if (!res) return;

        const data = await res.json();
        await cache.delete('/share-data');

        showModal({
            title:   'اشتراک‌گذاری',
            content: el('div', {
                html: `<p style="margin-bottom:12px">محتوای دریافتی:</p>
                       <div class="secret-code">${escapeHtml(data.text || data.url || data.title || '')}</div>`,
            }),
            footer: (() => {
                const footer = el('div', { class: 'flex gap-3' });
                footer.appendChild(el('button', { class: 'btn btn--primary', onclick: () => emit('newchat:open', {}) }, 'ارسال در چت'));
                return footer;
            })(),
        });
    } catch {}
}

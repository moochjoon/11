/* ============================================================
   PROFILE.JS  —  Profile panel, my profile, edit
   ============================================================ */

import {
    App, api, emit, on, $, el,
    escapeHtml, buildAvatar, formatDate, formatFileSize,
} from './app.js';
import { showToast, showModal, showConfirm } from './ui.js';

/* ──────────────────────────────────────────────────────────
   1. MY PROFILE
────────────────────────────────────────────────────────── */
export async function openMyProfile() {
    const panel = document.getElementById('profilePanel');
    if (!panel) return;

    _renderProfileSkeleton(panel);
    panel.classList.add('profile-panel--open');
    document.getElementById('app')?.classList.add('profile-open');

    try {
        const me = await api('GET', '/users/me');
        App.user = me;
        _renderMyProfile(panel, me);
    } catch {
        showToast('خطا در بارگذاری پروفایل', 'error');
    }
}

function _renderMyProfile(panel, user) {
    panel.innerHTML = '';

    const header = el('div', { class: 'profile-panel__header' });
    header.appendChild(el('h2', { class: 'profile-panel__title', html: 'پروفایل من' }));
    const closeBtn = el('button', { class: 'btn--icon', html: '✕', onclick: () => closeProfilePanel() });
    header.appendChild(closeBtn);
    panel.appendChild(header);

    const body = el('div', { class: 'profile-panel__body' });

    /* Avatar */
    const ph = el('div', { class: 'profile-header' });
    const avatarWrap = el('div', { class: 'profile-avatar-wrap' });
    avatarWrap.appendChild(buildAvatar(user, 'xl'));
    const editOverlay = el('div', {
        class: 'media-thumb__overlay',
        style: 'position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;cursor:pointer',
        html:  '📷',
    });
    avatarWrap.style.position = 'relative';
    avatarWrap.appendChild(editOverlay);
    avatarWrap.addEventListener('mouseenter', () => editOverlay.style.opacity = '1');
    avatarWrap.addEventListener('mouseleave', () => editOverlay.style.opacity = '0');
    avatarWrap.addEventListener('click', () => _changeAvatar(user));

    const nameEl = el('div', { class: 'profile-name', html: escapeHtml(user.name) });
    if (user.is_verified) nameEl.appendChild(el('span', { class: 'verified-badge', html: '✓' }));

    const editNameBtn = el('button', {
        class: 'btn btn--text btn--sm',
        html:  '✏️ ویرایش',
        onclick: () => _editProfile(user),
    });

    ph.appendChild(avatarWrap);
    ph.appendChild(nameEl);
    if (user.username) ph.appendChild(el('div', { class: 'profile-username', html: `@${user.username}` }));
    ph.appendChild(el('div', { class: 'profile-phone', html: user.phone }));
    if (user.bio)      ph.appendChild(el('div', { class: 'profile-bio', html: escapeHtml(user.bio) }));
    ph.appendChild(editNameBtn);

    body.appendChild(ph);

    /* Stats row */
    const stats = el('div', { style: 'display:flex;gap:24px;padding:16px 20px;border-bottom:1px solid var(--border)' });
    [
        ['💬', 'چت'],
        ['👥', 'مخاطب'],
        ['📁', 'مدیا'],
    ].forEach(([icon, label]) => {
        const stat = el('div', { style: 'flex:1;text-align:center;cursor:pointer' });
        stat.appendChild(el('div', { style: 'font-size:22px', html: icon }));
        stat.appendChild(el('div', { style: 'font-size:11px;color:var(--text-tertiary);margin-top:4px', html: label }));
        stats.appendChild(stat);
    });
    body.appendChild(stats);

    /* Settings shortcut */
    const settingsRow = el('div', {
        class:   'settings-row settings-row--nav',
        onclick: () => import('./settings.js').then(m => m.openSettings()),
        style:   'padding:14px 20px',
    });
    settingsRow.innerHTML = '<span style="font-size:18px">⚙️</span><span class="settings-row__label">تنظیمات</span><span class="settings-row__arrow">›</span>';
    body.appendChild(settingsRow);

    /* Logout */
    const logoutBtn = el('div', { class: 'profile-danger-section' });
    const lb = el('button', {
        class: 'profile-danger-btn',
        onclick: async () => {
            const ok = await showConfirm('خروج', 'آیا مطمئن هستید؟', 'خروج', true);
            if (ok) { const { logout } = await import('./app.js'); logout(); }
        },
    });
    lb.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg> خروج از حساب';
    logoutBtn.appendChild(lb);
    body.appendChild(logoutBtn);

    panel.appendChild(body);
}

/* ──────────────────────────────────────────────────────────
   2. CHAT PROFILE
────────────────────────────────────────────────────────── */
export async function openChatProfile(chat) {
    const panel = document.getElementById('profilePanel');
    if (!panel) return;

    _renderProfileSkeleton(panel);
    panel.classList.add('profile-panel--open');
    document.getElementById('app')?.classList.add('profile-open');

    try {
        const chatId = chat?.id;
        const full   = await api('GET', `/chats/${chatId}`);
        _renderChatProfile(panel, full);
    } catch {
        showToast('خطا در بارگذاری پروفایل', 'error');
    }
}

function _renderChatProfile(panel, chat) {
    panel.innerHTML = '';

    const header = el('div', { class: 'profile-panel__header' });
    header.appendChild(el('h2', { class: 'profile-panel__title', html: 'اطلاعات' }));
    header.appendChild(el('button', { class: 'btn--icon', html: '✕', onclick: closeProfilePanel }));
    panel.appendChild(header);

    const body = el('div', { class: 'profile-panel__body' });
    const other = chat.type === 'direct' ? chat.other_user : null;
    const isOnline = other && App.onlineUsers.has(other.id);
    const avatarUser = other || { id: chat.id, name: chat.title, avatar: chat.avatar, color: chat.color };

    /* Header info */
    const ph = el('div', { class: 'profile-header' });
    ph.appendChild(buildAvatar(avatarUser, 'xl'));
    const nameEl = el('div', { class: 'profile-name', html: escapeHtml(chat.title || other?.name || '') });
    if (chat.is_verified || other?.is_verified) nameEl.appendChild(el('span', { class: 'verified-badge', html: '✓' }));
    ph.appendChild(nameEl);

    if (other) {
        const status = el('div', { class: 'profile-status', ...(isOnline ? { 'data-status': 'online' } : {}) });
        status.textContent = isOnline ? 'آنلاین' : 'آخرین بازدید: ' + formatDate(other.last_seen);
        ph.appendChild(status);
        if (other.username) ph.appendChild(el('div', { class: 'profile-username', html: `@${other.username}` }));
        if (other.phone)    ph.appendChild(el('div', { class: 'profile-phone',    html: other.phone }));
        if (other.bio)      ph.appendChild(el('div', { class: 'profile-bio',      html: escapeHtml(other.bio) }));
    } else {
        ph.appendChild(el('div', { class: 'profile-status', html: `${chat.member_count || 0} عضو` }));
        if (chat.username)    ph.appendChild(el('div', { class: 'profile-username', html: `@${chat.username}` }));
        if (chat.description) ph.appendChild(el('div', { class: 'profile-bio',      html: escapeHtml(chat.description) }));
    }

    /* Action buttons */
    const actions = el('div', { class: 'profile-actions' });
    const btns = other
        ? [
            { icon: '💬', label: 'پیام',  action: () => closeProfilePanel() },
            { icon: '📞', label: 'تماس',  action: () => emit('call:start', { user: other, type: 'audio' }) },
            { icon: '🎥', label: 'ویدئو', action: () => emit('call:start', { user: other, type: 'video' }) },
            { icon: '🔇', label: 'سکوت',  action: () => _toggleMute(chat.id) },
        ]
        : [
            { icon: '🔇', label: 'سکوت',  action: () => _toggleMute(chat.id) },
            { icon: '🔍', label: 'جستجو', action: () => emit('search:open', {}) },
        ];

    btns.forEach(b => {
        const btn = el('div', { class: 'action-btn', onclick: b.action });
        btn.appendChild(el('div', { class: 'action-btn__icon', html: b.icon }));
        btn.appendChild(el('div', { class: 'action-btn__label', html: b.label }));
        actions.appendChild(btn);
    });
    ph.appendChild(actions);
    body.appendChild(ph);

    /* Tabs */
    _renderProfileTabs(body, chat);

    /* Danger zone */
    const danger = el('div', { class: 'profile-danger-section' });
    const blockBtn = el('button', { class: 'profile-danger-btn', onclick: () => _blockUser(chat) });
    blockBtn.innerHTML = '🚫 بلاک کردن';
    const clearBtn = el('button', { class: 'profile-danger-btn', onclick: async () => {
            const ok = await showConfirm('پاک کردن چت', 'همه پیام‌ها حذف خواهند شد.', 'پاک کردن');
            if (ok) { await api('DELETE', `/chats/${chat.id}/messages`).catch(() => {}); closeProfilePanel(); }
        }});
    clearBtn.innerHTML = '🗑 پاک کردن تاریخچه';
    danger.appendChild(blockBtn);
    danger.appendChild(clearBtn);
    body.appendChild(danger);

    panel.appendChild(body);
}

/* ──────────────────────────────────────────────────────────
   3. PROFILE TABS (Media / Docs / Links)
────────────────────────────────────────────────────────── */
function _renderProfileTabs(container, chat) {
    const tabs     = el('div', { class: 'profile-tabs' });
    const tabBody  = el('div', { class: 'profile-tab-body' });

    const tabDefs = [
        { key: 'media', label: 'مدیا' },
        { key: 'docs',  label: 'سند'  },
        { key: 'links', label: 'لینک' },
    ];

    let activeKey = 'media';

    tabDefs.forEach(({ key, label }) => {
        const tab = el('div', { class: `profile-tab${key === activeKey ? ' active' : ''}` });
        tab.appendChild(el('div', { class: 'tab-count', html: '—' }));
        tab.appendChild(document.createTextNode(label));
        tab.addEventListener('click', () => {
            $$('.profile-tab', tabs).forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            activeKey = key;
            _loadTabContent(tabBody, chat.id, key);
        });
        tabs.appendChild(tab);
    });

    container.appendChild(tabs);
    container.appendChild(tabBody);
    _loadTabContent(tabBody, chat.id, 'media');
}

async function _loadTabContent(container, chatId, type) {
    container.innerHTML = '<div class="spinner spinner--sm" style="margin:32px auto"></div>';
    try {
        const data = await api('GET', `/chats/${chatId}/media?type=${type}&limit=24`);
        container.innerHTML = '';

        if (!data.items?.length) {
            container.innerHTML = `<div class="empty-state"><span class="empty-state__icon">${type === 'media' ? '🖼' : type === 'docs' ? '📄' : '🔗'}</span><span class="empty-state__text">چیزی یافت نشد</span></div>`;
            return;
        }

        if (type === 'media') {
            const grid = el('div', { class: 'profile-media-grid' });
            data.items.forEach(item => {
                const thumb = el('div', { class: 'media-thumb', onclick: () => emit('media:open', { msg: item }) });
                thumb.appendChild(el('img', { src: item.media_thumb || item.media_url, loading: 'lazy', alt: '' }));
                if (item.type === 'video') thumb.classList.add('media-thumb--video');
                grid.appendChild(thumb);
            });
            container.appendChild(grid);
        } else if (type === 'docs') {
            data.items.forEach(item => {
                const row = el('a', { class: 'shared-doc', href: item.media_url, download: item.file_name || '' });
                row.appendChild(el('div', { class: 'shared-doc__icon', html: '📄' }));
                const info = el('div', { class: 'shared-doc__info' });
                info.appendChild(el('div', { class: 'shared-doc__name', html: escapeHtml(item.file_name || 'سند') }));
                info.appendChild(el('div', { class: 'shared-doc__meta', html: `${formatFileSize(item.media_size || 0)} · ${formatDate(item.created_at)}` }));
                row.appendChild(info);
                container.appendChild(row);
            });
        } else {
            data.items.forEach(item => {
                const row = el('a', { class: 'shared-link', href: item.text, target: '_blank', rel: 'noopener' });
                const imgWrap = el('div', { class: 'shared-link__img-placeholder', html: '🔗' });
                row.appendChild(imgWrap);
                const body = el('div', { class: 'shared-link__body' });
                body.appendChild(el('div', { class: 'shared-link__title', html: escapeHtml(item.text?.slice(0, 50) || '') }));
                body.appendChild(el('div', { class: 'shared-link__date',  html: formatDate(item.created_at) }));
                row.appendChild(body);
                container.appendChild(row);
            });
        }
    } catch {
        container.innerHTML = '<div class="empty-state"><span class="empty-state__text">خطا در بارگذاری</span></div>';
    }
}

/* ──────────────────────────────────────────────────────────
   4. EDIT PROFILE
────────────────────────────────────────────────────────── */
function _editProfile(user) {
    const content = el('div', { style: 'display:flex;flex-direction:column;gap:14px' });

    const nameInput = el('input', { type: 'text',     class: 'input', value: user.name || '',     placeholder: 'نام', maxlength: '64' });
    const userInput = el('input', { type: 'text',     class: 'input', value: user.username || '', placeholder: '@نام‌کاربری', maxlength: '32', dir: 'ltr' });
    const bioInput  = el('textarea', { class: 'input', placeholder: 'بیو...', rows: '3', maxlength: '500' });
    bioInput.value  = user.bio || '';
    const charCount = el('div', { class: 'bio-char-count', html: `${bioInput.value.length}/500` });
    bioInput.addEventListener('input', () => {
        charCount.textContent = `${bioInput.value.length}/500`;
        charCount.classList.toggle('over', bioInput.value.length > 500);
    });

    const hint = el('div', { class: 'username-hint' });

    let checkTimer;
    userInput.addEventListener('input', () => {
        clearTimeout(checkTimer);
        const val = userInput.value.trim();
        if (!val) { hint.textContent = ''; return; }
        if (!/^[a-zA-Z0-9_]{3,32}$/.test(val)) {
            hint.dataset.status = 'error';
            hint.textContent = 'فقط حروف انگلیسی، عدد و _ مجاز است (۳ تا ۳۲ کاراکتر)';
            return;
        }
        checkTimer = setTimeout(async () => {
            try {
                const res = await api('GET', `/users/check-username?username=${val}`);
                hint.dataset.status  = res.available ? 'ok' : 'error';
                hint.textContent     = res.available ? '✓ در دسترس است' : '✗ قبلاً گرفته شده';
            } catch {}
        }, 500);
    });

    content.appendChild(el('label', { class: 'auth-label', html: 'نام' }));
    content.appendChild(nameInput);
    content.appendChild(el('label', { class: 'auth-label', html: 'نام کاربری' }));
    content.appendChild(userInput);
    content.appendChild(hint);
    content.appendChild(el('label', { class: 'auth-label', html: 'بیو' }));
    content.appendChild(bioInput);
    content.appendChild(charCount);

    const footer    = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const saveBtn   = el('button', { class: 'btn btn--primary' }, 'ذخیره');

    saveBtn.addEventListener('click', async () => {
        const name     = nameInput.value.trim();
        const username = userInput.value.trim() || null;
        const bio      = bioInput.value.trim();

        if (!name) { showToast('نام الزامی است', 'error'); return; }
        saveBtn.disabled = true;
        try {
            const updated = await api('PATCH', '/users/me', { name, username, bio });
            Object.assign(App.user, updated);
            modal.close();
            openMyProfile();
            showToast('پروفایل ذخیره شد ✅', 'success');
        } catch (err) {
            showToast(err.data?.error || 'خطا در ذخیره', 'error');
            saveBtn.disabled = false;
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);

    const modal = showModal({ title: 'ویرایش پروفایل', content, footer });
    setTimeout(() => nameInput.focus(), 100);
}

/* ──────────────────────────────────────────────────────────
   5. CHANGE AVATAR
────────────────────────────────────────────────────────── */
function _changeAvatar(user) {
    const input = document.createElement('input');
    input.type  = 'file';
    input.accept= 'image/jpeg,image/png,image/webp';
    input.click();
    input.onchange = async () => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { showToast('حداکثر ۵ مگابایت', 'error'); return; }

        const toastId = showToast('در حال آپلود...', 'loading');
        try {
            const form = new FormData();
            form.append('file', file);
            form.append('type', 'avatar');
            const auth    = App.user;
            const token   = (await import('./app.js')).getToken();
            const res     = await fetch('/api/v1/files/upload', {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${token}` },
                body: form,
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error);

            await api('PATCH', '/users/me', { avatar: data.url });
            App.user.avatar = data.url;

            const { dismissToast } = await import('./ui.js');
            dismissToast(toastId);
            showToast('عکس پروفایل به‌روز شد ✅', 'success');
            openMyProfile();
        } catch (err) {
            const { dismissToast } = await import('./ui.js');
            dismissToast(toastId);
            showToast(err.message || 'خطا در آپلود', 'error');
        }
    };
}

/* ──────────────────────────────────────────────────────────
   6. HELPERS
────────────────────────────────────────────────────────── */
function _renderProfileSkeleton(panel) {
    panel.innerHTML = `
        <div class="profile-panel__header">
            <span class="profile-panel__title">در حال بارگذاری...</span>
            <button class="btn--icon" onclick="closeProfilePanel()">✕</button>
        </div>
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

export function closeProfilePanel() {
    const panel = document.getElementById('profilePanel');
    panel?.classList.remove('profile-panel--open');
    document.getElementById('app')?.classList.remove('profile-open');
}

async function _toggleMute(chatId) {
    await api('PATCH', `/chats/${chatId}/mute`).catch(() => {});
    showToast('وضعیت سکوت تغییر کرد', 'success');
}

async function _blockUser(chat) {
    const other = chat.other_user;
    if (!other) return;
    const ok = await showConfirm('بلاک کردن', `آیا ${other.name} بلاک شود؟`, 'بلاک');
    if (!ok) return;
    await api('POST', `/contacts/${other.id}/block`).catch(() => {});
    showToast(`${other.name} بلاک شد`, 'success');
    closeProfilePanel();
}

window.closeProfilePanel = closeProfilePanel;

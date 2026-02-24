/* ============================================================
   CONTACTS.JS  —  Contacts list, add, sync, search
   ============================================================ */

import {
    App, api, emit, on, $, $$, el,
    escapeHtml, buildAvatar, formatDate,
} from './app.js';
import { showToast, showModal, showConfirm } from './ui.js';

/* ──────────────────────────────────────────────────────────
   1. INIT
────────────────────────────────────────────────────────── */
export async function initContacts() {
    await loadContacts();

    on('contacts:refresh', loadContacts);
    on('contact:add',      e => showAddContactModal(e.detail));
}

/* ──────────────────────────────────────────────────────────
   2. LOAD
────────────────────────────────────────────────────────── */
export async function loadContacts(query = '') {
    try {
        const params = query ? `?q=${encodeURIComponent(query)}&limit=100` : '?limit=100';
        const data   = await api('GET', `/contacts${params}`);
        App.contacts.clear();
        for (const c of (data.contacts || [])) App.contacts.set(c.target_id, c);
        return data.contacts || [];
    } catch {
        return [];
    }
}

/* ──────────────────────────────────────────────────────────
   3. CONTACTS PANEL
────────────────────────────────────────────────────────── */
export function openContactsPanel() {
    const panel = document.getElementById('profilePanel');
    if (!panel) return;

    panel.classList.add('profile-panel--open');
    document.getElementById('app')?.classList.add('profile-open');

    _renderContactsPanel(panel);
}

function _renderContactsPanel(panel) {
    panel.innerHTML = '';

    /* Header */
    const header = el('div', { class: 'profile-panel__header' });
    header.appendChild(el('h2', { class: 'profile-panel__title', html: '👥 مخاطبین' }));
    const actions = el('div', { class: 'flex gap-2' });

    const addBtn = el('button', { class: 'btn--icon', title: 'افزودن مخاطب', onclick: () => showAddContactModal() });
    addBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>';

    const syncBtn = el('button', { class: 'btn--icon', title: 'همگام‌سازی', onclick: () => _syncContacts(syncBtn) });
    syncBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';

    const closeBtn = el('button', { class: 'btn--icon', html: '✕', onclick: () => {
            panel.classList.remove('profile-panel--open');
            document.getElementById('app')?.classList.remove('profile-open');
        }});

    actions.appendChild(addBtn);
    actions.appendChild(syncBtn);
    actions.appendChild(closeBtn);
    header.appendChild(actions);
    panel.appendChild(header);

    /* Search */
    const searchWrap = el('div', { class: 'sidebar__search', style: 'padding:8px 12px' });
    const searchInput= el('input', { type: 'search', class: 'sidebar__search-input input', placeholder: 'جستجو...', autocomplete: 'off' });
    let   searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => _filterRenderedContacts(list, searchInput.value.trim()), 200);
    });
    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);

    /* Filter bar */
    const filterBar = el('div', { class: 'contacts-filter-bar' });
    [
        { key: 'all',       label: 'همه'        },
        { key: 'favorites', label: '⭐ موردعلاقه' },
        { key: 'online',    label: '🟢 آنلاین'   },
    ].forEach(({ key, label }) => {
        const chip = el('button', { class: `filter-chip${key === 'all' ? ' active' : ''}`, 'data-key': key });
        chip.textContent = label;
        chip.addEventListener('click', () => {
            $$('.filter-chip', filterBar).forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            _filterRenderedContacts(list, searchInput.value.trim(), key);
        });
        filterBar.appendChild(chip);
    });
    panel.appendChild(filterBar);

    /* List */
    const body = el('div', { class: 'profile-panel__body' });
    const list = el('div', { class: 'contacts-list', id: 'contactsList' });
    list.innerHTML = '<div class="spinner spinner--sm" style="margin:40px auto"></div>';
    body.appendChild(list);
    panel.appendChild(body);

    /* Load */
    loadContacts().then(contacts => _renderContactList(list, contacts));
}

function _renderContactList(container, contacts) {
    container.innerHTML = '';

    if (!contacts.length) {
        container.innerHTML = `
            <div class="empty-state">
                <span class="empty-state__icon">👥</span>
                <span class="empty-state__title">مخاطبی ندارید</span>
                <span class="empty-state__text">شماره موبایل دوستانتان را اضافه کنید</span>
            </div>`;
        return;
    }

    /* Group by first letter */
    const groups = new Map();
    const sorted = [...contacts].sort((a, b) => {
        const na = a.name || a.target?.name || '';
        const nb = b.name || b.target?.name || '';
        return na.localeCompare(nb, 'fa');
    });

    for (const contact of sorted) {
        const name  = contact.name || contact.target?.name || '';
        const key   = name[0]?.toUpperCase() || '#';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(contact);
    }

    for (const [letter, items] of groups) {
        const divider = el('div', { class: 'contact-group-divider', html: letter });
        container.appendChild(divider);

        for (const contact of items) {
            container.appendChild(_buildContactRow(contact));
        }
    }
}

function _buildContactRow(contact) {
    const target   = contact.target || { id: contact.target_id };
    const name     = contact.name || target.name || '';
    const isOnline = App.onlineUsers.has(target.id);

    const row = el('div', {
        class:        'contact-row ripple-container',
        'data-id':    target.id,
        'data-name':  name.toLowerCase(),
        tabindex:     '0',
    });

    /* Avatar */
    const avatarWrap = el('div', { class: 'contact-row__avatar-wrap' });
    avatarWrap.appendChild(buildAvatar(target.id ? { ...target, name } : { id: contact.target_id, name }, 'md'));
    if (isOnline) avatarWrap.appendChild(el('span', { class: 'chat-item__online' }));
    row.appendChild(avatarWrap);

    /* Info */
    const info = el('div', { class: 'contact-row__info' });
    const nameEl = el('div', { class: 'contact-row__name', html: escapeHtml(name) });
    if (target.is_verified) nameEl.appendChild(el('span', { class: 'verified-badge', html: '✓' }));
    info.appendChild(nameEl);

    const sub = el('div', { class: 'contact-row__sub' });
    if (target.username) sub.textContent = `@${target.username}`;
    else if (target.phone) sub.textContent = target.phone;
    else if (isOnline)  sub.textContent = 'آنلاین';
    else if (target.last_seen) sub.textContent = formatDate(target.last_seen);
    info.appendChild(sub);
    row.appendChild(info);

    /* Actions */
    const acts = el('div', { class: 'contact-row__actions' });

    if (contact.is_favorite) {
        acts.appendChild(el('span', { class: 'contact-row__fav', html: '⭐' }));
    }

    const chatBtn = el('button', {
        class: 'btn--icon contact-row__chat-btn',
        title: 'ارسال پیام',
        onclick: async (e) => {
            e.stopPropagation();
            try {
                const chat = await api('POST', '/chats/direct', { user_id: target.id });
                App.chats.set(chat.id, chat);
                const { openChat } = await import('./chat.js');
                openChat(chat.id);
                document.getElementById('profilePanel')?.classList.remove('profile-panel--open');
                document.getElementById('app')?.classList.remove('profile-open');
            } catch (err) {
                showToast(err.data?.error || 'خطا', 'error');
            }
        },
    });
    chatBtn.innerHTML = `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
    acts.appendChild(chatBtn);

    row.appendChild(acts);

    /* Click → open chat */
    row.addEventListener('click', async () => {
        try {
            const chat = await api('POST', '/chats/direct', { user_id: target.id });
            App.chats.set(chat.id, chat);
            const { openChat } = await import('./chat.js');
            openChat(chat.id);
        } catch {}
    });

    /* Context menu */
    row.addEventListener('contextmenu', e => {
        e.preventDefault();
        _showContactContextMenu(e, contact);
    });

    return row;
}

/* ──────────────────────────────────────────────────────────
   4. ADD CONTACT MODAL
────────────────────────────────────────────────────────── */
export function showAddContactModal(prefill = {}) {
    const content  = el('div', { style: 'display:flex;flex-direction:column;gap:14px' });
    const phoneInput = el('input', {
        type:         'tel',
        class:        'input',
        placeholder:  '+989123456789',
        autocomplete: 'tel',
        dir:          'ltr',
        value:        prefill.phone || '',
    });
    const nameInput = el('input', {
        type:        'text',
        class:       'input',
        placeholder: 'نام مخاطب',
        maxlength:   '64',
        value:       prefill.name || '',
    });
    const userPreview = el('div', { class: 'contact-user-preview hidden' });

    /* Phone lookup */
    let lookupTimer;
    phoneInput.addEventListener('input', () => {
        clearTimeout(lookupTimer);
        const phone = phoneInput.value.trim();
        if (phone.length < 10) { userPreview.classList.add('hidden'); return; }
        lookupTimer = setTimeout(async () => {
            try {
                const data = await api('GET', `/users/by-phone?phone=${encodeURIComponent(phone)}`);
                if (data?.user) {
                    _renderUserPreview(userPreview, data.user);
                    userPreview.classList.remove('hidden');
                    if (!nameInput.value) nameInput.value = data.user.name;
                }
            } catch { userPreview.classList.add('hidden'); }
        }, 600);
    });

    content.appendChild(el('label', { class: 'auth-label', html: 'شماره موبایل' }));
    content.appendChild(phoneInput);
    content.appendChild(userPreview);
    content.appendChild(el('label', { class: 'auth-label', html: 'نام (اختیاری)' }));
    content.appendChild(nameInput);

    const footer    = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const saveBtn   = el('button', { class: 'btn btn--primary' }, 'افزودن');

    saveBtn.addEventListener('click', async () => {
        const phone = phoneInput.value.trim();
        const name  = nameInput.value.trim();
        if (!phone) { showToast('شماره موبایل الزامی است', 'error'); return; }

        saveBtn.disabled    = true;
        saveBtn.textContent = '...';
        try {
            await api('POST', '/contacts', { phone, name: name || undefined });
            await loadContacts();
            modal.close();
            showToast('مخاطب اضافه شد ✅', 'success');

            /* Refresh contacts list if open */
            const list = document.getElementById('contactsList');
            if (list) {
                const contacts = await loadContacts();
                _renderContactList(list, contacts);
            }
        } catch (err) {
            showToast(err.data?.error || 'خطا در افزودن', 'error');
            saveBtn.disabled    = false;
            saveBtn.textContent = 'افزودن';
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);

    const modal = showModal({ title: '➕ مخاطب جدید', content, footer });
    setTimeout(() => phoneInput.focus(), 100);
}

function _renderUserPreview(container, user) {
    container.innerHTML = '';
    container.classList.add('contact-user-preview--visible');
    container.appendChild(buildAvatar(user, 'md'));
    const info = el('div', { class: 'contact-user-preview__info' });
    info.appendChild(el('div', { class: 'contact-user-preview__name',  html: escapeHtml(user.name) }));
    info.appendChild(el('div', { class: 'contact-user-preview__phone', html: user.phone }));
    container.appendChild(info);
    container.appendChild(el('div', { class: 'contact-user-preview__check', html: '✓ در Namak' }));
}

/* ──────────────────────────────────────────────────────────
   5. CONTACT CONTEXT MENU
────────────────────────────────────────────────────────── */
function _showContactContextMenu(e, contact) {
    import('./ui.js').then(m => {
        const isFav = contact.is_favorite;
        m.showContextMenu(e.clientX, e.clientY, [
            {
                icon: '💬',
                label: 'ارسال پیام',
                action: async () => {
                    const chat = await api('POST', '/chats/direct', { user_id: contact.target_id }).catch(() => null);
                    if (chat) {
                        App.chats.set(chat.id, chat);
                        const { openChat } = await import('./chat.js');
                        openChat(chat.id);
                    }
                },
            },
            {
                icon: isFav ? '⭐' : '☆',
                label: isFav ? 'حذف از موردعلاقه' : 'افزودن به موردعلاقه',
                action: async () => {
                    await api('PATCH', `/contacts/${contact.target_id}`, { is_favorite: !isFav }).catch(() => {});
                    await loadContacts();
                    const list = document.getElementById('contactsList');
                    if (list) _renderContactList(list, [...App.contacts.values()]);
                },
            },
            {
                icon: '✏️',
                label: 'ویرایش نام',
                action: () => _editContactName(contact),
            },
            { separator: true },
            {
                icon: '🚫',
                label: 'بلاک کردن',
                action: async () => {
                    const ok = await showConfirm('بلاک', `${contact.name || 'این کاربر'} بلاک شود؟`, 'بلاک');
                    if (!ok) return;
                    await api('POST', `/contacts/${contact.target_id}/block`).catch(() => {});
                    App.contacts.delete(contact.target_id);
                    const list = document.getElementById('contactsList');
                    if (list) _renderContactList(list, [...App.contacts.values()]);
                    showToast('کاربر بلاک شد', 'success');
                },
                danger: true,
            },
            {
                icon: '🗑',
                label: 'حذف مخاطب',
                action: async () => {
                    const ok = await showConfirm('حذف مخاطب', 'این مخاطب حذف می‌شود.', 'حذف');
                    if (!ok) return;
                    await api('DELETE', `/contacts/${contact.target_id}`).catch(() => {});
                    App.contacts.delete(contact.target_id);
                    const list = document.getElementById('contactsList');
                    if (list) _renderContactList(list, [...App.contacts.values()]);
                    showToast('مخاطب حذف شد', 'success');
                },
                danger: true,
            },
        ]);
    });
}

function _editContactName(contact) {
    const input = el('input', {
        type:      'text',
        class:     'input',
        value:     contact.name || '',
        maxlength: '64',
        placeholder: 'نام مخاطب',
    });

    const footer    = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const saveBtn   = el('button', { class: 'btn btn--primary' }, 'ذخیره');

    saveBtn.addEventListener('click', async () => {
        const name = input.value.trim();
        if (!name) { showToast('نام نمی‌تواند خالی باشد', 'error'); return; }
        saveBtn.disabled = true;
        try {
            await api('PATCH', `/contacts/${contact.target_id}`, { name });
            contact.name = name;
            App.contacts.set(contact.target_id, { ...contact, name });
            modal.close();
            showToast('نام ویرایش شد ✅', 'success');
            const list = document.getElementById('contactsList');
            if (list) _renderContactList(list, [...App.contacts.values()]);
        } catch {
            showToast('خطا در ذخیره', 'error');
            saveBtn.disabled = false;
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);

    const modal = showModal({
        title:   'ویرایش نام مخاطب',
        content: input,
        footer,
    });
    setTimeout(() => { input.focus(); input.select(); }, 100);
}

/* ──────────────────────────────────────────────────────────
   6. SYNC CONTACTS
────────────────────────────────────────────────────────── */
async function _syncContacts(btn) {
    if (!navigator.contacts) {
        showToast('مرورگر از دسترسی به مخاطبین پشتیبانی نمی‌کند', 'warning');
        return;
    }
    try {
        const contacts = await navigator.contacts.select(['name', 'tel'], { multiple: true });
        if (!contacts.length) return;

        btn.classList.add('spin');
        const toastId = showToast(`همگام‌سازی ${contacts.length} مخاطب...`, 'loading');

        const payload = contacts.flatMap(c =>
            (c.tel || []).map(phone => ({
                phone: phone.replace(/\s+/g, ''),
                name:  c.name?.[0] || '',
            }))
        ).filter(c => c.phone);

        const { added } = await api('POST', '/contacts/sync', { contacts: payload });

        const { dismissToast } = await import('./ui.js');
        dismissToast(toastId);
        showToast(`${added} مخاطب جدید اضافه شد`, 'success');

        const all = await loadContacts();
        const list = document.getElementById('contactsList');
        if (list) _renderContactList(list, all);

    } catch (err) {
        showToast(err.message || 'خطا در همگام‌سازی', 'error');
    } finally {
        btn.classList.remove('spin');
    }
}

/* ──────────────────────────────────────────────────────────
   7. FILTER
────────────────────────────────────────────────────────── */
function _filterRenderedContacts(list, query, category = 'all') {
    const rows = $$('.contact-row, .contact-group-divider', list);
    let   lastDivider = null;

    for (const row of rows) {
        if (row.classList.contains('contact-group-divider')) {
            lastDivider = row;
            continue;
        }

        const name     = row.dataset.name || '';
        const id       = row.dataset.id   || '';
        const isOnline = App.onlineUsers.has(id);
        const contact  = App.contacts.get(id);
        const isFav    = contact?.is_favorite;

        const matchQuery    = !query    || name.includes(query.toLowerCase());
        const matchCategory = category === 'all'
            ? true
            : category === 'online'
                ? isOnline
                : category === 'favorites'
                    ? isFav
                    : true;

        row.style.display = matchQuery && matchCategory ? '' : 'none';
    }

    /* Hide empty group dividers */
    let currentDivider = null;
    let hasVisible     = false;
    for (const row of $$('.contact-row, .contact-group-divider', list)) {
        if (row.classList.contains('contact-group-divider')) {
            if (currentDivider) currentDivider.style.display = hasVisible ? '' : 'none';
            currentDivider = row;
            hasVisible     = false;
        } else if (row.style.display !== 'none') {
            hasVisible = true;
        }
    }
    if (currentDivider) currentDivider.style.display = hasVisible ? '' : 'none';
}

/* ──────────────────────────────────────────────────────────
   8. EXPORT
────────────────────────────────────────────────────────── */
export { loadContacts as contacts };

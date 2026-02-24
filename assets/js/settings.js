/* ============================================================
   SETTINGS.JS  —  Settings panel
   Theme, appearance, notifications, privacy, security,
   data & storage, about
   ============================================================ */

import {
    App, api, emit, on, $, $$, el,
    escapeHtml, saveSetting, loadSettings, storage, logout,
} from './app.js';
import { showToast, showModal, showConfirm } from './ui.js';

/* ──────────────────────────────────────────────────────────
   1. OPEN SETTINGS
────────────────────────────────────────────────────────── */
export function openSettings() {
    const panel = document.getElementById('profilePanel');
    if (!panel) return;

    panel.classList.add('profile-panel--open');
    document.getElementById('app')?.classList.add('profile-open');

    _renderSettings(panel);
}

function _renderSettings(panel) {
    panel.innerHTML = '';

    /* Header */
    const header = el('div', { class: 'profile-panel__header' });
    header.appendChild(el('h2', { class: 'profile-panel__title', html: '⚙️ تنظیمات' }));
    header.appendChild(el('button', {
        class: 'btn--icon',
        html:  '✕',
        onclick: () => {
            panel.classList.remove('profile-panel--open');
            document.getElementById('app')?.classList.remove('profile-open');
        },
    }));
    panel.appendChild(header);

    const body = el('div', { class: 'settings-body' });
    panel.appendChild(body);

    /* Render all sections */
    _renderAppearanceSection(body);
    _renderNotificationSection(body);
    _renderChatSection(body);
    _renderPrivacySection(body);
    _renderStorageSection(body);
    _renderSessionsSection(body);
    _renderAboutSection(body);
}

/* ──────────────────────────────────────────────────────────
   2. SECTION: APPEARANCE
────────────────────────────────────────────────────────── */
function _renderAppearanceSection(container) {
    const section = _makeSection('🎨 ظاهر');

    /* Theme */
    section.appendChild(_makeLabel('تم'));
    const themeWrap = el('div', { class: 'settings-segment' });
    [
        { value: 'light',  label: '☀️ روشن' },
        { value: 'dark',   label: '🌙 تاریک' },
        { value: 'system', label: '💻 سیستم' },
    ].forEach(({ value, label }) => {
        const btn = el('button', {
            class:      `settings-segment__btn${App.settings.theme === value ? ' active' : ''}`,
            'data-val': value,
        }, label);
        btn.addEventListener('click', () => {
            $$('.settings-segment__btn', themeWrap).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            saveSetting('theme', value);
        });
        themeWrap.appendChild(btn);
    });
    section.appendChild(themeWrap);

    /* Accent color */
    section.appendChild(_makeLabel('رنگ اصلی'));
    const accentWrap = el('div', { class: 'accent-palette' });
    const accents = [
        { value: 'blue',   color: '#3b82f6' },
        { value: 'violet', color: '#8b5cf6' },
        { value: 'rose',   color: '#f43f5e' },
        { value: 'amber',  color: '#f59e0b' },
        { value: 'teal',   color: '#14b8a6' },
        { value: 'green',  color: '#22c55e' },
        { value: 'orange', color: '#f97316' },
        { value: 'pink',   color: '#ec4899' },
    ];
    accents.forEach(({ value, color }) => {
        const dot = el('div', {
            class:   `accent-dot${App.settings.accent === value ? ' accent-dot--active' : ''}`,
            style:   `background:${color}`,
            title:   value,
            onclick: () => {
                $$('.accent-dot', accentWrap).forEach(d => d.classList.remove('accent-dot--active'));
                dot.classList.add('accent-dot--active');
                saveSetting('accent', value);
            },
        });
        accentWrap.appendChild(dot);
    });
    section.appendChild(accentWrap);

    /* Font size */
    section.appendChild(_makeLabel(`اندازه فونت — ${App.settings.fontSize}px`));
    const sizeRow = el('div', { class: 'settings-row settings-row--slider' });
    const slider  = el('input', {
        type:  'range',
        min:   '12',
        max:   '18',
        step:  '1',
        value: String(App.settings.fontSize),
        class: 'settings-slider',
    });
    slider.addEventListener('input', () => {
        const v = parseInt(slider.value);
        saveSetting('fontSize', v);
        sizeRow.previousElementSibling.textContent = `اندازه فونت — ${v}px`;
    });
    sizeRow.appendChild(el('span', { html: 'کوچک' }));
    sizeRow.appendChild(slider);
    sizeRow.appendChild(el('span', { html: 'بزرگ' }));
    section.appendChild(sizeRow);

    /* Bubble style */
    section.appendChild(_makeLabel('سبک حباب پیام'));
    const bubbleWrap = el('div', { class: 'settings-segment' });
    [
        { value: 'default', label: '⬛ پیش‌فرض' },
        { value: 'square',  label: '▬ مربع'     },
        { value: 'minimal', label: '▱ مینیمال'  },
    ].forEach(({ value, label }) => {
        const btn = el('button', {
            class:      `settings-segment__btn${App.settings.bubbleStyle === value ? ' active' : ''}`,
        }, label);
        btn.addEventListener('click', () => {
            $$('.settings-segment__btn', bubbleWrap).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            saveSetting('bubbleStyle', value);
        });
        bubbleWrap.appendChild(btn);
    });
    section.appendChild(bubbleWrap);

    /* Chat background */
    section.appendChild(_makeLabel('پس‌زمینه چت'));
    const bgGrid = el('div', { class: 'chat-bg-grid' });
    const bgs = [
        { value: 'default', label: 'پیش‌فرض', preview: 'var(--bg-chat)' },
        { value: 'dots',    label: 'نقطه‌ها',  preview: '' },
        { value: 'wave',    label: 'موج',       preview: '' },
        { value: 'dark',    label: 'تاریک',    preview: '#111' },
        { value: 'gradient',label: 'گرادیان',  preview: 'linear-gradient(135deg,#667eea,#764ba2)' },
        { value: 'none',    label: 'ساده',     preview: 'transparent' },
    ];
    bgs.forEach(({ value, label, preview }) => {
        const item = el('div', {
            class:   `chat-bg-item${App.settings.chatBg === value ? ' chat-bg-item--active' : ''}`,
            title:   label,
            onclick: () => {
                $$('.chat-bg-item', bgGrid).forEach(i => i.classList.remove('chat-bg-item--active'));
                item.classList.add('chat-bg-item--active');
                saveSetting('chatBg', value);
            },
        });
        item.style.background = preview || '';
        item.appendChild(el('span', { class: 'chat-bg-item__label', html: label }));
        bgGrid.appendChild(item);
    });
    section.appendChild(bgGrid);

    /* Compact mode + Animations */
    section.appendChild(_makeToggle('حالت فشرده', 'compactMode'));
    section.appendChild(_makeToggle('انیمیشن‌ها', 'animationsEnabled'));

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   3. SECTION: NOTIFICATIONS
────────────────────────────────────────────────────────── */
function _renderNotificationSection(container) {
    const section = _makeSection('🔔 اعلان‌ها');

    section.appendChild(_makeToggle('فعال‌سازی اعلان', 'notifyEnabled', async val => {
        if (val) {
            const granted = await Notification.requestPermission();
            if (granted !== 'granted') {
                saveSetting('notifyEnabled', false);
                showToast('دسترسی اعلان رد شد', 'warning');
                return false;
            }
            const { subscribePush } = await import('./app.js');
            subscribePush();
        }
    }));

    section.appendChild(_makeToggle('پیش‌نمایش پیام', 'notifyPreview'));
    section.appendChild(_makeToggle('صدای پیام',       'soundEnabled'));

    /* Sound volume */
    const volRow = el('div', { class: 'settings-row settings-row--slider' });
    const vol    = el('input', { type: 'range', min: '0', max: '100', step: '5', value: String((App.settings.soundVolume ?? 80)), class: 'settings-slider' });
    vol.addEventListener('change', () => saveSetting('soundVolume', parseInt(vol.value)));
    volRow.appendChild(el('span', { html: '🔈' }));
    volRow.appendChild(vol);
    volRow.appendChild(el('span', { html: '🔊' }));
    section.appendChild(_makeLabel('بلندی صدا'));
    section.appendChild(volRow);

    section.appendChild(_makeDivider());

    /* In-app notification badge style */
    section.appendChild(_makeLabel('نمایش نشان‌واره روی آیکون'));
    const badgeWrap = el('div', { class: 'settings-segment' });
    [
        { value: 'count', label: '🔢 عدد'   },
        { value: 'dot',   label: '🔴 نقطه'  },
        { value: 'none',  label: '✖️ خاموش' },
    ].forEach(({ value, label }) => {
        const btn = el('button', {
            class: `settings-segment__btn${(App.settings.badgeStyle || 'count') === value ? ' active' : ''}`,
        }, label);
        btn.addEventListener('click', () => {
            $$('.settings-segment__btn', badgeWrap).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            saveSetting('badgeStyle', value);
        });
        badgeWrap.appendChild(btn);
    });
    section.appendChild(badgeWrap);

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   4. SECTION: CHAT
────────────────────────────────────────────────────────── */
function _renderChatSection(container) {
    const section = _makeSection('💬 چت');

    /* Enter to send */
    section.appendChild(_makeToggle('Enter برای ارسال', 'enterToSend'));
    section.appendChild(_makeToggle('دانلود خودکار رسانه', 'autoDownload'));
    section.appendChild(_makeToggle('تأییدیه خواندن', 'sendReadReceipts'));

    section.appendChild(_makeDivider());

    /* Link preview */
    section.appendChild(_makeToggle('پیش‌نمایش لینک', 'linkPreview', null, true));

    /* Auto-delete timer */
    section.appendChild(_makeLabel('حذف خودکار پیام‌ها'));
    const sel = el('select', { class: 'input', style: 'width:100%' });
    [
        { value: '0',     label: 'غیرفعال'    },
        { value: '86400', label: '۲۴ ساعت'    },
        { value: '604800',label: 'یک هفته'    },
        { value: '2592000',label: 'یک ماه'   },
    ].forEach(({ value, label }) => {
        const opt = el('option', { value });
        opt.textContent = label;
        if (String(App.settings.autoDeleteTTL || 0) === value) opt.selected = true;
        sel.appendChild(opt);
    });
    sel.addEventListener('change', () => saveSetting('autoDeleteTTL', parseInt(sel.value)));
    section.appendChild(sel);

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   5. SECTION: PRIVACY
────────────────────────────────────────────────────────── */
function _renderPrivacySection(container) {
    const section = _makeSection('🔒 حریم خصوصی');

    section.appendChild(_makeToggle('نمایش وضعیت آنلاین', 'showOnlineStatus', async val => {
        await api('PATCH', '/users/me', { online_privacy: val ? 'everyone' : 'nobody' }).catch(() => {});
    }));

    /* Privacy pickers */
    [
        { key: 'phone_privacy',    label: 'نمایش شماره موبایل' },
        { key: 'lastseen_privacy', label: 'نمایش آخرین بازدید'  },
    ].forEach(({ key, label }) => {
        section.appendChild(_makeLabel(label));
        const sel = el('select', { class: 'input', style: 'width:100%' });
        [
            { value: 'everyone', label: 'همه'          },
            { value: 'contacts', label: 'مخاطبین'      },
            { value: 'nobody',   label: 'هیچ‌کس'       },
        ].forEach(({ value, label: lbl }) => {
            const opt = el('option', { value });
            opt.textContent = lbl;
            if ((App.user?.[key] || 'contacts') === value) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', async () => {
            await api('PATCH', '/users/me', { [key]: sel.value }).catch(() => {});
        });
        section.appendChild(sel);
    });

    section.appendChild(_makeDivider());

    /* Blocked users */
    const blockedBtn = el('div', { class: 'settings-row settings-row--nav' });
    blockedBtn.innerHTML = '<span>🚫 کاربران بلاک‌شده</span><span class="settings-row__arrow">›</span>';
    blockedBtn.addEventListener('click', _showBlockedUsers);
    section.appendChild(blockedBtn);

    /* Two-step verification */
    const twoStepBtn = el('div', { class: 'settings-row settings-row--nav' });
    twoStepBtn.innerHTML = '<span>🔑 تأیید دو مرحله‌ای</span><span class="settings-row__arrow">›</span>';
    twoStepBtn.addEventListener('click', _showTwoStepVerification);
    section.appendChild(twoStepBtn);

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   6. SECTION: STORAGE
────────────────────────────────────────────────────────── */
function _renderStorageSection(container) {
    const section = _makeSection('💾 داده و ذخیره‌سازی');

    /* Cache usage */
    const usageRow = el('div', { class: 'storage-usage-row' });
    usageRow.innerHTML = '<span class="spinner spinner--sm"></span>';
    section.appendChild(usageRow);

    _calcStorageUsage().then(({ cacheSize, idbSize, total }) => {
        usageRow.innerHTML = '';
        const bar  = el('div', { class: 'storage-bar' });
        const fill = el('div', { class: 'storage-bar__fill', style: `width:${Math.min(100, (total / (200 * 1024 * 1024)) * 100)}%` });
        bar.appendChild(fill);

        usageRow.appendChild(bar);
        usageRow.appendChild(el('div', {
            class: 'storage-usage-labels',
            html:  `<span>کش: ${_fmtBytes(cacheSize)}</span><span>محلی: ${_fmtBytes(idbSize)}</span><span>مجموع: ${_fmtBytes(total)}</span>`,
        }));
    });

    /* Auto-download media quality */
    section.appendChild(_makeLabel('کیفیت دانلود خودکار'));
    const qualSeg = el('div', { class: 'settings-segment' });
    [
        { value: 'low',    label: 'کم'    },
        { value: 'medium', label: 'متوسط' },
        { value: 'high',   label: 'بالا'  },
    ].forEach(({ value, label }) => {
        const btn = el('button', {
            class: `settings-segment__btn${(App.settings.downloadQuality || 'medium') === value ? ' active' : ''}`,
        }, label);
        btn.addEventListener('click', () => {
            $$('.settings-segment__btn', qualSeg).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            saveSetting('downloadQuality', value);
        });
        qualSeg.appendChild(btn);
    });
    section.appendChild(qualSeg);

    section.appendChild(_makeDivider());

    /* Clear cache button */
    const clearBtn = el('button', { class: 'btn btn--danger', style: 'width:100%' });
    clearBtn.textContent = '🗑 پاک کردن حافظه پنهان';
    clearBtn.addEventListener('click', async () => {
        const ok = await showConfirm('پاک کردن کش', 'کش برنامه پاک می‌شود. پیام‌ها حذف نمی‌شوند.', 'پاک کن');
        if (!ok) return;

        clearBtn.disabled    = true;
        clearBtn.textContent = '...';

        try {
            const keys = await caches.keys();
            await Promise.all(keys.map(k => caches.delete(k)));
            localStorage.removeItem('namak_cache_ts');
            showToast('کش پاک شد ✅', 'success');
        } catch {
            showToast('خطا در پاک کردن کش', 'error');
        } finally {
            clearBtn.disabled    = false;
            clearBtn.textContent = '🗑 پاک کردن حافظه پنهان';
        }
    });
    section.appendChild(clearBtn);

    /* Export data */
    const exportBtn = el('button', { class: 'btn btn--ghost', style: 'width:100%' });
    exportBtn.textContent = '📤 خروجی گرفتن از داده‌ها';
    exportBtn.addEventListener('click', _exportData);
    section.appendChild(exportBtn);

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   7. SECTION: SESSIONS
────────────────────────────────────────────────────────── */
function _renderSessionsSection(container) {
    const section = _makeSection('📱 دستگاه‌های فعال');

    const listWrap = el('div', { class: 'sessions-list' });
    listWrap.innerHTML = '<div class="spinner spinner--sm" style="margin:24px auto"></div>';
    section.appendChild(listWrap);

    api('GET', '/users/sessions').then(data => {
        listWrap.innerHTML = '';
        if (!data?.sessions?.length) {
            listWrap.innerHTML = '<div class="empty-state"><span class="empty-state__text">اطلاعات نشست یافت نشد</span></div>';
            return;
        }

        for (const session of data.sessions) {
            const row  = el('div', { class: `session-row${session.is_current ? ' session-row--current' : ''}` });
            const icon = _getDeviceIcon(session.device_name || '');
            const info = el('div', { class: 'session-row__info' });
            info.appendChild(el('div', { class: 'session-row__device',   html: `${icon} ${escapeHtml(session.device_name || 'دستگاه ناشناس')}` }));
            info.appendChild(el('div', { class: 'session-row__meta',     html: `${session.device_ip || ''} · ${_tsAgo(session.created_at)}` }));
            if (session.is_current) info.appendChild(el('div', { class: 'session-row__badge', html: 'این دستگاه' }));
            row.appendChild(info);

            if (!session.is_current) {
                const revokeBtn = el('button', { class: 'btn btn--ghost btn--sm session-row__revoke' });
                revokeBtn.textContent = 'خروج';
                revokeBtn.addEventListener('click', async () => {
                    const ok = await showConfirm('خروج از دستگاه', 'این نشست پایان می‌یابد.', 'خروج');
                    if (!ok) return;
                    await api('DELETE', `/users/sessions/${session.id}`).catch(() => {});
                    row.remove();
                    showToast('دستگاه حذف شد', 'success');
                });
                row.appendChild(revokeBtn);
            }
            listWrap.appendChild(row);
        }

        /* Terminate all */
        if (data.sessions.length > 1) {
            const allBtn = el('button', { class: 'btn btn--danger', style: 'width:100%;margin-top:12px' });
            allBtn.textContent = '⛔ خروج از همه دستگاه‌ها';
            allBtn.addEventListener('click', async () => {
                const ok = await showConfirm('خروج همه', 'از تمام دستگاه‌های دیگر خارج می‌شوید.', 'خروج همه');
                if (!ok) return;
                await api('DELETE', '/users/sessions/all').catch(() => {});
                showToast('خروج از همه دستگاه‌ها انجام شد', 'success');
                _renderSessionsSection(container.parentElement);
            });
            listWrap.appendChild(allBtn);
        }
    }).catch(() => {
        listWrap.innerHTML = '<div class="empty-state"><span class="empty-state__text">خطا در بارگذاری</span></div>';
    });

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   8. SECTION: ABOUT
────────────────────────────────────────────────────────── */
function _renderAboutSection(container) {
    const section = _makeSection('ℹ️ درباره');

    const info = el('div', { class: 'about-info' });
    info.innerHTML = `
        <div class="about-logo">🧂</div>
        <div class="about-name">Namak Messenger</div>
        <div class="about-version">نسخه ${App.version || '1.0.0'}</div>
        <div class="about-desc">پیام‌رسانی سریع، خصوصی و ایرانی</div>
    `;
    section.appendChild(info);

    const links = [
        { icon: '🌐', label: 'وب‌سایت',             url: 'https://namak.ir' },
        { icon: '🐞', label: 'گزارش باگ',            url: 'https://github.com/namak/issues' },
        { icon: '📜', label: 'شرایط استفاده',        url: 'https://namak.ir/terms' },
        { icon: '🔏', label: 'سیاست حریم خصوصی',   url: 'https://namak.ir/privacy' },
    ];

    links.forEach(({ icon, label, url }) => {
        const row = el('a', { class: 'settings-row settings-row--nav', href: url, target: '_blank', rel: 'noopener' });
        row.innerHTML = `<span>${icon} ${label}</span><span class="settings-row__arrow">›</span>`;
        section.appendChild(row);
    });

    /* PWA install */
    if (App.installPrompt) {
        const installBtn = el('button', { class: 'btn btn--primary', style: 'width:100%;margin-top:12px' });
        installBtn.textContent = '📲 نصب برنامه';
        installBtn.addEventListener('click', async () => {
            App.installPrompt.prompt();
            const { outcome } = await App.installPrompt.userChoice;
            if (outcome === 'accepted') showToast('برنامه نصب شد ✅', 'success');
            App.installPrompt = null;
            installBtn.remove();
        });
        section.appendChild(installBtn);
    }

    container.appendChild(section);
}

/* ──────────────────────────────────────────────────────────
   9. BLOCKED USERS
────────────────────────────────────────────────────────── */
async function _showBlockedUsers() {
    const listEl = el('div', { class: 'blocked-list' });
    listEl.innerHTML = '<div class="spinner spinner--sm" style="margin:32px auto"></div>';

    const modal = showModal({ title: '🚫 کاربران بلاک‌شده', content: listEl, size: 'md' });

    try {
        const data = await api('GET', '/contacts/blocked');
        listEl.innerHTML = '';

        if (!data.contacts?.length) {
            listEl.innerHTML = '<div class="empty-state"><span class="empty-state__icon">✅</span><span class="empty-state__text">هیچ کاربری بلاک نشده</span></div>';
            return;
        }

        for (const contact of data.contacts) {
            const row = el('div', { class: 'blocked-row' });
            const { buildAvatar } = await import('./app.js');
            row.appendChild(buildAvatar(contact.target || { id: contact.target_id, name: contact.name }, 'md'));

            const info = el('div', { class: 'blocked-row__info' });
            info.appendChild(el('div', { class: 'blocked-row__name', html: escapeHtml(contact.name || contact.target?.name || '') }));
            info.appendChild(el('div', { class: 'blocked-row__phone', html: contact.target?.phone || '' }));

            const unblockBtn = el('button', { class: 'btn btn--ghost btn--sm' });
            unblockBtn.textContent = 'رفع بلاک';
            unblockBtn.addEventListener('click', async () => {
                await api('DELETE', `/contacts/${contact.target_id}/block`).catch(() => {});
                row.remove();
                showToast('کاربر از بلاک خارج شد', 'success');
            });

            row.appendChild(info);
            row.appendChild(unblockBtn);
            listEl.appendChild(row);
        }
    } catch {
        listEl.innerHTML = '<div class="empty-state"><span class="empty-state__text">خطا در بارگذاری</span></div>';
    }
}

/* ──────────────────────────────────────────────────────────
   10. TWO-STEP VERIFICATION
────────────────────────────────────────────────────────── */
function _showTwoStepVerification() {
    const content  = el('div', { style: 'display:flex;flex-direction:column;gap:14px' });
    const passInput= el('input', { type: 'password', class: 'input', placeholder: 'رمز عبور دو مرحله‌ای', autocomplete: 'new-password' });
    const confInput= el('input', { type: 'password', class: 'input', placeholder: 'تکرار رمز عبور', autocomplete: 'new-password' });
    const hintInput= el('input', { type: 'text',     class: 'input', placeholder: 'راهنما (اختیاری)', maxlength: '128' });

    const strengthBar = el('div', { class: 'password-strength' });
    const strengthFill= el('div', { class: 'password-strength__fill' });
    strengthBar.appendChild(strengthFill);

    passInput.addEventListener('input', () => {
        const s = _passwordStrength(passInput.value);
        strengthFill.style.width = `${s.score * 25}%`;
        strengthFill.dataset.level = s.level;
    });

    content.appendChild(el('p', { class: 'settings-hint', html: 'این رمز هنگام ورود از دستگاه جدید پرسیده می‌شود.' }));
    content.appendChild(passInput);
    content.appendChild(strengthBar);
    content.appendChild(confInput);
    content.appendChild(hintInput);

    const footer = el('div', { class: 'flex gap-3' });
    const cancelBtn = el('button', { class: 'btn btn--ghost', onclick: () => modal.close() }, 'لغو');
    const saveBtn   = el('button', { class: 'btn btn--primary' }, 'ذخیره');

    saveBtn.addEventListener('click', async () => {
        const pass = passInput.value;
        const conf = confInput.value;
        if (pass.length < 6) { showToast('رمز باید حداقل ۶ کاراکتر باشد', 'error'); return; }
        if (pass !== conf)   { showToast('رمزهای وارد شده یکسان نیستند', 'error'); return; }
        saveBtn.disabled = true;
        try {
            await api('POST', '/users/two-step', { password: pass, hint: hintInput.value.trim() });
            modal.close();
            showToast('تأیید دو مرحله‌ای فعال شد ✅', 'success');
        } catch (err) {
            showToast(err.data?.error || 'خطا', 'error');
            saveBtn.disabled = false;
        }
    });

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);
    const modal = showModal({ title: '🔑 تأیید دو مرحله‌ای', content, footer });
    setTimeout(() => passInput.focus(), 100);
}

/* ──────────────────────────────────────────────────────────
   11. EXPORT DATA
────────────────────────────────────────────────────────── */
async function _exportData() {
    const toastId = showToast('در حال آماده‌سازی...', 'loading');
    try {
        const data = await api('GET', '/users/export');
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url  = URL.createObjectURL(blob);
        const a    = el('a', { href: url, download: `namak-export-${Date.now()}.json` });
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        const { dismissToast } = await import('./ui.js');
        dismissToast(toastId);
        showToast('داده‌ها دانلود شدند ✅', 'success');
    } catch {
        const { dismissToast } = await import('./ui.js');
        dismissToast(toastId);
        showToast('خطا در خروجی گرفتن', 'error');
    }
}

/* ──────────────────────────────────────────────────────────
   12. HELPERS
────────────────────────────────────────────────────────── */
function _makeSection(title) {
    const sec = el('div', { class: 'settings-section' });
    sec.appendChild(el('div', { class: 'settings-section__title', html: title }));
    return sec;
}

function _makeLabel(text) {
    return el('div', { class: 'settings-label', html: text });
}

function _makeDivider() {
    return el('div', { class: 'settings-divider' });
}

function _makeToggle(label, settingKey, onChange = null, defaultVal = null) {
    const row = el('div', { class: 'settings-toggle-row' });

    const current = App.settings[settingKey] !== undefined
        ? App.settings[settingKey]
        : defaultVal ?? false;

    const toggle = el('label', { class: 'toggle' });
    const input  = el('input', { type: 'checkbox' });
    const slider = el('span',  { class: 'toggle__slider' });

    if (current) input.checked = true;

    input.addEventListener('change', async () => {
        const val = input.checked;
        if (onChange) {
            const result = await onChange(val);
            if (result === false) { input.checked = !val; return; }
        }
        saveSetting(settingKey, val);
    });

    toggle.appendChild(input);
    toggle.appendChild(slider);

    row.appendChild(el('span', { class: 'settings-toggle-row__label', html: label }));
    row.appendChild(toggle);
    return row;
}

async function _calcStorageUsage() {
    let cacheSize = 0, idbSize = 0;
    try {
        if ('storage' in navigator && 'estimate' in navigator.storage) {
            const est = await navigator.storage.estimate();
            idbSize   = est.usage || 0;
        }
        const cacheKeys = await caches.keys();
        for (const key of cacheKeys) {
            const c = await caches.open(key);
            const r = await c.keys();
            for (const req of r) {
                const res = await c.match(req);
                if (res) {
                    const buf  = await res.clone().arrayBuffer();
                    cacheSize += buf.byteLength;
                }
            }
        }
    } catch {}
    return { cacheSize, idbSize, total: cacheSize + idbSize };
}

function _fmtBytes(bytes) {
    if (bytes < 1024)       return `${bytes} B`;
    if (bytes < 1048576)    return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1073741824) return `${(bytes / 1048576).toFixed(1)} MB`;
    return `${(bytes / 1073741824).toFixed(1)} GB`;
}

function _tsAgo(dateStr) {
    const diff = (Date.now() - new Date(dateStr)) / 1000;
    if (diff < 60)    return 'همین الان';
    if (diff < 3600)  return `${Math.floor(diff / 60)} دقیقه پیش`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} ساعت پیش`;
    return `${Math.floor(diff / 86400)} روز پیش`;
}

function _getDeviceIcon(name = '') {
    const n = name.toLowerCase();
    if (n.includes('android') || n.includes('mobile')) return '📱';
    if (n.includes('iphone')  || n.includes('ios'))    return '📱';
    if (n.includes('ipad'))                             return '📲';
    if (n.includes('mac'))                              return '💻';
    if (n.includes('windows'))                          return '🖥';
    if (n.includes('linux'))                            return '🐧';
    return '💻';
}

function _passwordStrength(pass) {
    let score = 0;
    if (pass.length >= 8)             score++;
    if (/[A-Z]/.test(pass))           score++;
    if (/[0-9]/.test(pass))           score++;
    if (/[^A-Za-z0-9]/.test(pass))   score++;
    const levels = ['', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
    return { score, level: levels[score] || '' };
}

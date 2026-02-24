/* ============================================================
   CHAT.JS  —  Chat list, messages, send, media
   ============================================================ */

import {
    App, api, emit, on, $, $$, el,
    formatTime, formatDate, formatFullDate, formatFileSize, formatDuration,
    buildAvatar, escapeHtml, parseTextWithLinks, playSound, isVisible,
} from './app.js';
import { sendTypingStart, sendTypingStop, send } from './socket.js';

/* ──────────────────────────────────────────────────────────
   1. CHAT LIST
────────────────────────────────────────────────────────── */
export async function initChatList() {
    await loadChats();
    _bindChatListEvents();

    on('msg:new',     _handleNewMessage);
    on('msg:edited',  _handleEditedMessage);
    on('msg:deleted', _handleDeletedMessage);
    on('msg:seen',    _handleSeen);
    on('typing:start',_handleTypingStart);
    on('typing:stop', _handleTypingStop);
    on('presence:online',  _handlePresence);
    on('presence:offline', _handlePresence);
}

export async function loadChats() {
    const list = $('#chatList');
    if (!list) return;

    _renderChatSkeletons(list);

    try {
        const data = await api('GET', '/chats?limit=50');
        App.chats.clear();
        for (const chat of data.chats) App.chats.set(chat.id, chat);
        _renderChatList(data.chats);
    } catch {
        list.innerHTML = '<div class="empty-state"><span class="empty-state__icon">⚠️</span><span class="empty-state__text">خطا در بارگذاری</span></div>';
    }
}

function _renderChatSkeletons(container) {
    container.innerHTML = Array(8).fill(0).map(() => `
        <div class="chat-skeleton">
            <div class="chat-skeleton__avatar"></div>
            <div class="chat-skeleton__body">
                <div class="chat-skeleton__name"></div>
                <div class="chat-skeleton__msg"></div>
            </div>
        </div>
    `).join('');
}

function _renderChatList(chats) {
    const list = $('#chatList');
    if (!list) return;

    if (!chats.length) {
        list.innerHTML = `
            <div class="empty-state">
                <span class="empty-state__icon">💬</span>
                <span class="empty-state__title">هنوز چتی ندارید</span>
                <span class="empty-state__text">از طریق مخاطبین شروع به گفتگو کنید</span>
            </div>`;
        return;
    }

    // Sort: pinned first, then by last message date
    const sorted = [...chats].sort((a, b) => {
        const aPinned = App.chats.get(a.id)?.member?.is_pinned ? 1 : 0;
        const bPinned = App.chats.get(b.id)?.member?.is_pinned ? 1 : 0;
        if (aPinned !== bPinned) return bPinned - aPinned;
        return new Date(b.last_msg_at) - new Date(a.last_msg_at);
    });

    list.innerHTML = '';
    for (const chat of sorted) {
        list.appendChild(_buildChatItem(chat));
    }
}

function _buildChatItem(chat) {
    const member   = chat.member || {};
    const isPinned = member.is_pinned;
    const isMuted  = member.is_muted;
    const unread   = member.unread_count || 0;
    const other    = chat.type === 'direct' ? chat.other_user : null;
    const isOnline = other && App.onlineUsers.has(other.id);
    const lastMsg  = chat.last_message;

    const div = el('div', {
        class:        `chat-item ripple-container${isPinned ? ' chat-item--pinned' : ''}${chat.id === App.activeChat?.id ? ' chat-item--active' : ''}`,
        'data-id':    chat.id,
        'data-chat-type': chat.type,
        tabindex:     '0',
        role:         'button',
        'aria-label': `چت با ${chat.title || other?.name || ''}`,
    });

    // Avatar
    const avatarWrap = el('div', { class: 'chat-item__avatar-wrap' });
    const avatarUser = other || { id: chat.id, name: chat.title, avatar: chat.avatar, avatar_thumb: chat.avatar_thumb, color: chat.color };
    avatarWrap.appendChild(buildAvatar(avatarUser, 'md'));
    if (isOnline) {
        avatarWrap.appendChild(el('span', { class: 'chat-item__online' }));
    }

    // Body
    const body = el('div', { class: 'chat-item__body' });

    // Row 1: name + time
    const row1 = el('div', { class: 'chat-item__row1' });
    const name = el('div', {
        class: 'chat-item__name',
        html: `${escapeHtml(chat.title || other?.name || '')}${chat.is_verified ? ' <span class="verified-badge">✓</span>' : ''}`,
    });
    const timeWrap = el('div', { class: 'chat-item__time-wrap' });

    if (lastMsg?.sender_id === App.user?.id) {
        const tick = el('span', { class: 'chat-item__tick', 'data-status': lastMsg.status });
        tick.innerHTML = _tickSVG();
        timeWrap.appendChild(tick);
    }
    timeWrap.appendChild(el('span', { class: 'chat-item__time', html: lastMsg ? formatDate(lastMsg.created_at) : '' }));
    row1.appendChild(name);
    row1.appendChild(timeWrap);

    // Row 2: last msg + badges
    const row2  = el('div', { class: 'chat-item__row2' });
    const msgEl = el('div', {
        class: 'chat-item__last-msg',
        id:    `chat-last-${chat.id}`,
        html:  lastMsg ? _formatLastMsg(lastMsg, chat.type) : '',
    });
    const badges = el('div', { class: 'chat-item__badges' });
    if (isMuted)  badges.appendChild(el('span', { class: 'chat-item__mute', html: '🔇' }));
    if (isPinned) badges.appendChild(el('span', { class: 'chat-item__pin', html: '📌' }));
    if (unread)   {
        const b = el('span', { class: `chat-item__badge${isMuted ? ' chat-item__badge--muted' : ''}`, html: unread > 99 ? '99+' : String(unread) });
        badges.appendChild(b);
    }

    row2.appendChild(msgEl);
    row2.appendChild(badges);
    body.appendChild(row1);
    body.appendChild(row2);

    div.appendChild(avatarWrap);
    div.appendChild(body);

    return div;
}

function _formatLastMsg(msg, chatType) {
    if (!msg) return '';
    if (msg.is_deleted) return '<em>پیام حذف شده</em>';

    const prefix = chatType !== 'direct' && msg.sender_id !== App.user?.id
        ? `<b>${escapeHtml(msg.sender?.name?.split(' ')[0] || '')}:</b> `
        : msg.sender_id === App.user?.id ? 'شما: ' : '';

    switch (msg.type) {
        case 'text':     return prefix + escapeHtml(msg.text?.slice(0, 60) || '');
        case 'image':    return prefix + '📷 عکس';
        case 'video':    return prefix + '🎥 ویدئو';
        case 'voice':    return prefix + '🎤 پیام صوتی';
        case 'audio':    return prefix + '🎵 موسیقی';
        case 'document': return prefix + `📄 ${escapeHtml(msg.file_name || 'سند')}`;
        case 'location': return prefix + '📍 موقعیت مکانی';
        case 'contact':  return prefix + '👤 مخاطب';
        case 'sticker':  return '🎨 استیکر';
        case 'gif':      return '🎞 GIF';
        case 'poll':     return '📊 نظرسنجی';
        case 'call':     return '📞 تماس';
        case 'system':   return `<em>${escapeHtml(msg.text || '')}</em>`;
        default:         return prefix + escapeHtml(msg.text || '');
    }
}

function _tickSVG() {
    return `<svg viewBox="0 0 14 9"><polyline points="1,4 4,7 9,2"/></svg>`;
}

function _bindChatListEvents() {
    const list = $('#chatList');
    if (!list) return;

    list.addEventListener('click', e => {
        const item = e.target.closest('.chat-item');
        if (!item) return;
        openChat(item.dataset.id);
    });
    list.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            const item = e.target.closest('.chat-item');
            if (item) openChat(item.dataset.id);
        }
    });
    list.addEventListener('contextmenu', e => {
        const item = e.target.closest('.chat-item');
        if (!item) return;
        e.preventDefault();
        _showChatContextMenu(e, item.dataset.id);
    });
}

/* ──────────────────────────────────────────────────────────
   2. OPEN CHAT
────────────────────────────────────────────────────────── */
export async function openChat(chatId) {
    if (App.activeChat?.id === chatId) return;

    // Update active state
    $$('.chat-item--active').forEach(el => el.classList.remove('chat-item--active'));
    $(`.chat-item[data-id="${chatId}"]`)?.classList.add('chat-item--active');

    const chat = App.chats.get(chatId);
    App.activeChat = chat || { id: chatId };

    // Mobile: show chat area
    document.getElementById('app')?.classList.add('chat-open');

    // Render chat header + messages area
    _renderChatArea(chat);

    // Load messages
    await loadMessages(chatId, true);

    // Mark as seen
    await markSeen(chatId);
}

function _renderChatArea(chat) {
    const area = $('#chatArea');
    if (!area) return;

    const other = chat?.type === 'direct' ? chat.other_user : null;
    const isOnline = other && App.onlineUsers.has(other.id);
    const avatarUser = other || { id: chat?.id, name: chat?.title, avatar: chat?.avatar, avatar_thumb: chat?.avatar_thumb, color: chat?.color };

    area.innerHTML = `
        <div class="chat-header" id="chatHeader">
            <button class="chat-header__back" id="chatBack" aria-label="بازگشت">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5m7-7-7 7 7 7"/>
                </svg>
            </button>
            <div id="chatHeaderAvatar"></div>
            <div class="chat-header__info" id="chatInfo">
                <div class="chat-header__name" id="chatName">
                    ${escapeHtml(chat?.title || other?.name || 'چت')}
                    ${chat?.is_verified ? '<span class="verified-badge">✓</span>' : ''}
                </div>
                <div class="chat-header__status" id="chatStatus"
                     data-status="${isOnline ? 'online' : ''}">
                    ${isOnline ? 'آنلاین' : other ? 'آخرین بازدید: ' + formatDate(other.last_seen) : `${chat?.member_count || 0} عضو`}
                </div>
            </div>
            <div class="chat-header__actions">
                <button class="chat-header__btn" id="btnSearch"   title="جستجو">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                </button>
                <button class="chat-header__btn" id="btnCall"     title="تماس صوتی">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </button>
                <button class="chat-header__btn" id="btnVideoCall" title="تماس تصویری">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                </button>
                <button class="chat-header__btn" id="btnChatMenu"  title="بیشتر">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                </button>
            </div>
        </div>

        <div class="chat-messages" id="chatMessages" role="log" aria-live="polite"></div>

        <button class="scroll-to-bottom hidden" id="scrollToBottom" title="رفتن به پایین">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div class="chat-input-area" id="chatInputArea">
            <div class="chat-input-row">
                <button class="chat-input-attach btn--icon" id="btnAttach" title="پیوست">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </button>
                <div class="chat-input-wrap">
                    <button class="chat-input-emoji" id="btnEmoji" title="ایموجی">😊</button>
                    <div class="input" id="chatInput" contenteditable="true"
                         data-placeholder="پیام..." role="textbox" aria-label="متن پیام"
                         aria-multiline="true"></div>
                </div>
                <button class="chat-input-voice" id="btnVoice" title="پیام صوتی">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/></svg>
                </button>
                <button class="chat-input-send hidden" id="btnSend" title="ارسال">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </div>
    `;

    // Insert avatar
    const avatarWrap = $('#chatHeaderAvatar');
    if (avatarWrap && avatarUser) {
        avatarWrap.appendChild(buildAvatar(avatarUser, 'sm'));
    }

    _bindChatAreaEvents(chat);
}

/* ──────────────────────────────────────────────────────────
   3. MESSAGES
────────────────────────────────────────────────────────── */
let loadingMore   = false;
let hasMoreMsgs   = true;
let oldestCursor  = null;
const msgMap      = new Map();   // msgId → DOM element

export async function loadMessages(chatId, fresh = false) {
    const container = $('#chatMessages');
    if (!container) return;

    if (fresh) {
        container.innerHTML = '';
        msgMap.clear();
        hasMoreMsgs  = true;
        oldestCursor = null;
    }

    if (!hasMoreMsgs || loadingMore) return;
    loadingMore = true;

    const prevScrollHeight = container.scrollHeight;

    try {
        const params = `?limit=40${oldestCursor ? `&before=${encodeURIComponent(oldestCursor)}` : ''}`;
        const data   = await api('GET', `/messages/${chatId}${params}`);

        hasMoreMsgs  = data.has_more;
        oldestCursor = data.cursor;

        if (!data.messages.length && fresh) {
            container.innerHTML = `
                <div class="empty-state">
                    <span class="empty-state__icon">👋</span>
                    <span class="empty-state__title">شروع گفتگو</span>
                    <span class="empty-state__text">اولین پیام را ارسال کنید</span>
                </div>`;
            return;
        }

        _insertMessages(container, data.messages, fresh);

        if (fresh) {
            container.scrollTop = container.scrollHeight;
        } else {
            container.scrollTop = container.scrollHeight - prevScrollHeight;
        }

    } catch (err) {
        console.error('[Chat] Load messages error:', err);
    } finally {
        loadingMore = false;
    }
}

function _insertMessages(container, messages, append = false) {
    let lastDate = null;
    let lastSender = null;
    let lastTime   = null;

    const fragment = document.createDocumentFragment();

    if (!append && hasMoreMsgs) {
        const loader = el('div', { class: 'msgs-load-more', id: 'msgsLoadMore', style: 'height:1px' });
        fragment.appendChild(loader);
    }

    for (const msg of messages) {
        const msgDate = new Date(msg.created_at).toDateString();
        if (msgDate !== lastDate) {
            fragment.appendChild(_buildDateDivider(msg.created_at));
            lastDate   = msgDate;
            lastSender = null;
        }

        const sameSender = lastSender === msg.sender_id &&
            (new Date(msg.created_at) - new Date(lastTime)) < 5 * 60 * 1000;

        const row = _buildMessageRow(msg, sameSender);
        msgMap.set(msg.id, row);
        fragment.appendChild(row);

        lastSender = msg.sender_id;
        lastTime   = msg.created_at;
    }

    if (append) {
        container.appendChild(fragment);
    } else {
        const loader = container.querySelector('#msgsLoadMore');
        if (loader) loader.after(fragment);
        else        container.prepend(fragment);
    }

    _observeLoadMore();
}

function _buildDateDivider(dateStr) {
    return el('div', { class: 'msg-date-divider' },
        el('span', { html: formatFullDate(dateStr) })
    );
}

function _buildMessageRow(msg, sameSender = false) {
    const isOut = msg.sender_id === App.user?.id;
    const row   = el('div', {
        class:        `msg-row msg-row--${isOut ? 'out' : (msg.type === 'system' ? 'system' : 'in')}${sameSender ? ' same-sender' : ''}`,
        'data-msg-id': msg.id,
        'data-chat-id':msg.chat_id,
    });

    if (msg.type === 'system') {
        row.appendChild(el('div', { class: 'msg-system', html: escapeHtml(msg.text || '') }));
        return row;
    }

    // Avatar (for group chats, incoming, not same sender)
    if (!isOut && !sameSender && App.activeChat?.type !== 'direct') {
        if (msg.sender) row.appendChild(buildAvatar(msg.sender, 'sm'));
    } else if (!isOut && App.activeChat?.type !== 'direct') {
        row.appendChild(el('div', { class: 'msg-avatar--placeholder' }));
    }

    const bubble = el('div', { class: 'msg-bubble' });

    // Sender name (group)
    if (!isOut && !sameSender && App.activeChat?.type !== 'direct' && msg.sender) {
        bubble.appendChild(el('div', {
            class: 'msg-sender-name',
            html:  escapeHtml(msg.sender.name),
        }));
    }

    // Forwarded
    if (msg.forward_from_id) {
        bubble.appendChild(el('div', { class: 'msg-forwarded', html: '↪ ارسال‌شده' }));
    }

    // Reply
    if (msg.reply_to_id && msg.reply_msg_text) {
        const replyEl = el('div', {
            class:           'msg-reply',
            'data-reply-id': msg.reply_to_id,
        });
        if (msg.reply_sender_name) {
            replyEl.appendChild(el('div', { class: 'msg-reply__name', html: escapeHtml(msg.reply_sender_name) }));
        }
        replyEl.appendChild(el('div', { class: 'msg-reply__text', html: escapeHtml(msg.reply_msg_text?.slice(0, 80) || '') }));
        bubble.appendChild(replyEl);
    }

    // Content
    bubble.appendChild(_buildMsgContent(msg));

    // Meta
    const meta = el('div', { class: 'msg-meta' });
    if (msg.is_edited) meta.appendChild(el('span', { class: 'msg-meta__edited', html: 'ویرایش‌شده' }));
    meta.appendChild(el('span', { class: 'msg-meta__time', html: formatTime(msg.created_at) }));
    if (isOut) {
        const tick = el('span', { class: 'msg-meta__tick', 'data-status': msg.status });
        tick.innerHTML = _tickSVG();
        meta.appendChild(tick);
    }
    bubble.appendChild(meta);

    // Reactions
    if (msg.reactions?.length) {
        bubble.appendChild(_buildReactions(msg.reactions, msg.id));
    }

    row.appendChild(bubble);

    // Context menu
    row.addEventListener('contextmenu', e => {
        e.preventDefault();
        _showMsgContextMenu(e, msg);
    });

    // Long press (mobile)
    let pressTimer;
    row.addEventListener('pointerdown', () => {
        pressTimer = setTimeout(() => _showMsgContextMenu({ clientX: 0, clientY: 0 }, msg), 600);
    });
    row.addEventListener('pointerup',   () => clearTimeout(pressTimer));
    row.addEventListener('pointermove', () => clearTimeout(pressTimer));

    return row;
}

function _buildMsgContent(msg) {
    switch (msg.type) {
        case 'text':
            return el('div', { class: `msg-text msg-text--${_detectDir(msg.text)}`, html: parseTextWithLinks(msg.text || '') });

        case 'image':
            return _buildMediaBubble(msg, 'image');
        case 'video':
            return _buildMediaBubble(msg, 'video');

        case 'voice':
        case 'audio':
            return _buildAudioBubble(msg);

        case 'document':
            return _buildDocBubble(msg);

        case 'location':
            return _buildLocationBubble(msg);

        case 'sticker':
            return el('div', { class: 'msg-sticker' },
                el('img', { src: msg.media_url, alt: 'sticker', loading: 'lazy' })
            );

        default:
            return el('div', { class: 'msg-text', html: escapeHtml(msg.text || '') });
    }
}

function _buildMediaBubble(msg, type) {
    const wrap = el('div', {
        class:   'msg-media',
        onclick: () => _openMediaViewer(msg),
    });

    if (type === 'image') {
        const img = el('img', {
            src:     msg.media_thumb || msg.media_url,
            alt:     'تصویر',
            loading: 'lazy',
        });
        img.onload = () => { if (msg.media_url !== msg.media_thumb) img.src = msg.media_url; };
        wrap.appendChild(img);
    } else {
        const video = el('video', {
            src:      msg.media_url,
            poster:   msg.media_thumb,
            preload:  'none',
        });
        wrap.appendChild(video);
        const overlay = el('div', { class: 'msg-media__overlay' });
        overlay.appendChild(el('div', { class: 'msg-media__play', html: '▶' }));
        if (msg.media_duration) {
            overlay.appendChild(el('div', { class: 'msg-media__duration', html: formatDuration(msg.media_duration) }));
        }
        wrap.appendChild(overlay);
    }
    return wrap;
}

function _buildAudioBubble(msg) {
    const wrap = el('div', { class: 'msg-audio' });
    const btn  = el('button', { class: 'msg-audio__btn', title: 'پخش' });
    btn.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>`;

    const waveform = el('div', { class: 'msg-audio__waveform' });
    const bars     = Array(28).fill(0).map((_, i) => {
        const bar = el('div', { class: 'msg-audio__bar' });
        bar.style.height = `${20 + Math.sin(i * 0.7) * 12 + Math.random() * 8}%`;
        return bar;
    });
    bars.forEach(b => waveform.appendChild(b));

    const time = el('div', { class: 'msg-audio__time', html: formatDuration(msg.media_duration || 0) });

    // Audio player
    let audio = null;
    let playing = false;
    let currentBar = 0;
    let playInterval;

    btn.addEventListener('click', () => {
        if (!audio) {
            audio = new Audio(msg.media_url);
            audio.addEventListener('timeupdate', () => {
                const pct = audio.currentTime / audio.duration;
                const idx = Math.floor(pct * bars.length);
                if (idx !== currentBar) {
                    bars.slice(0, idx).forEach(b => b.classList.add('msg-audio__bar--played'));
                    currentBar = idx;
                }
                const rem = Math.floor(audio.duration - audio.currentTime);
                time.textContent = formatDuration(isNaN(rem) ? 0 : rem);
            });
            audio.addEventListener('ended', () => {
                playing = false;
                btn.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>`;
                bars.forEach(b => b.classList.remove('msg-audio__bar--played'));
                time.textContent = formatDuration(msg.media_duration || 0);
            });
        }
        if (playing) {
            audio.pause();
            playing = false;
            btn.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>`;
        } else {
            audio.play();
            playing = true;
            btn.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>`;
        }
    });

    waveform.addEventListener('click', e => {
        if (!audio) return;
        const rect = waveform.getBoundingClientRect();
        const pct  = (e.clientX - rect.left) / rect.width;
        audio.currentTime = pct * audio.duration;
    });

    wrap.appendChild(btn);
    wrap.appendChild(waveform);
    wrap.appendChild(time);
    return wrap;
}

function _buildDocBubble(msg) {
    const wrap = el('div', { class: 'msg-doc' });
    const icons = { pdf: '📄', doc: '📝', zip: '🗜', mp3: '🎵', default: '📎' };
    const ext   = (msg.file_name || '').split('.').pop().toLowerCase();
    const icon  = icons[ext] || icons.default;

    const iconEl = el('div', { class: 'msg-doc__icon', html: icon });
    const info   = el('div', { class: 'msg-doc__info' });
    info.appendChild(el('div', { class: 'msg-doc__name', html: escapeHtml(msg.file_name || 'سند') }));
    info.appendChild(el('div', { class: 'msg-doc__meta', html: `${formatFileSize(msg.media_size || 0)} · ${ext.toUpperCase()}` }));

    const dlBtn = el('a', {
        class: 'msg-doc__dl',
        href:  msg.media_url,
        download: msg.file_name || '',
    });
    dlBtn.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;

    wrap.appendChild(iconEl);
    wrap.appendChild(info);
    wrap.appendChild(dlBtn);
    return wrap;
}

function _buildLocationBubble(msg) {
    const wrap   = el('div', { class: 'msg-location' });
    const mapUrl = `https://static-maps.yandex.ru/1.x/?ll=${msg.longitude},${msg.latitude}&z=14&size=280,140&l=map&pt=${msg.longitude},${msg.latitude},pm2rdl`;
    wrap.appendChild(el('img', { src: mapUrl, alt: 'موقعیت', loading: 'lazy' }));
    const label = el('div', { class: 'msg-location__label' });
    label.innerHTML = `📍 ${escapeHtml(msg.location_title || `${msg.latitude}, ${msg.longitude}`)}`;
    wrap.appendChild(label);
    wrap.addEventListener('click', () =>
        window.open(`https://maps.google.com/?q=${msg.latitude},${msg.longitude}`, '_blank')
    );
    return wrap;
}

function _buildReactions(reactions, msgId) {
    const wrap = el('div', { class: 'msg-reactions' });
    for (const r of reactions) {
        const chip = el('div', {
            class: `msg-reaction${r.mine ? ' msg-reaction--mine' : ''}`,
            onclick: () => _toggleReaction(msgId, r.emoji),
        });
        chip.appendChild(document.createTextNode(r.emoji));
        chip.appendChild(el('span', { class: 'msg-reaction__count', html: r.count }));
        wrap.appendChild(chip);
    }
    return wrap;
}

/* ──────────────────────────────────────────────────────────
   4. SEND
────────────────────────────────────────────────────────── */
let replyToMsg = null;

export async function sendMessage(chatId, payload) {
    if (!chatId) return;

    const tempId  = `temp_${Date.now()}`;
    const tempMsg = {
        id:         tempId,
        chat_id:    chatId,
        sender_id:  App.user.id,
        sender:     App.user,
        type:       payload.type || 'text',
        text:       payload.text,
        status:     'sending',
        created_at: new Date().toISOString(),
        reply_to_id: payload.reply_to_id,
        ...payload,
    };

    // Optimistic render
    _appendMessage(tempMsg);
    playSound('sent');

    try {
        const sent = await api('POST', '/messages', { chat_id: chatId, ...payload });

        // Replace temp with real
        const tempEl = msgMap.get(tempId);
        if (tempEl) {
            const realEl = _buildMessageRow(sent, false);
            tempEl.replaceWith(realEl);
            msgMap.delete(tempId);
            msgMap.set(sent.id, realEl);
        }

        // Update chat list last message
        _updateChatListItem(chatId, sent);

    } catch (err) {
        // Mark as failed
        const tempEl = msgMap.get(tempId);
        tempEl?.querySelector('.msg-meta__tick')?.setAttribute('data-status', 'failed');
    }
}

function _appendMessage(msg) {
    const container = $('#chatMessages');
    if (!container) return;

    const row = _buildMessageRow(msg, false);
    row.classList.add('msg-row--new-out');
    msgMap.set(msg.id, row);
    container.appendChild(row);
    _scrollToBottom();
}

function _bindChatAreaEvents(chat) {
    const input   = $('#chatInput');
    const btnSend = $('#btnSend');
    const btnVoice= $('#btnVoice');
    const btnAttach=$('#btnAttach');
    const btnEmoji =$('#btnEmoji');
    const backBtn  =$('#chatBack');
    const headerInfo=$('#chatInfo');
    const msgs    = $('#chatMessages');

    if (!input) return;

    // Input → show/hide send button
    input.addEventListener('input', () => {
        const hasText = input.textContent.trim().length > 0;
        btnSend?.classList.toggle('hidden',  !hasText);
        btnVoice?.classList.toggle('hidden',  hasText);
        if (hasText && App.activeChat?.id) sendTypingStart(App.activeChat.id);
    });

    input.addEventListener('keydown', e => {
        const enterToSend = App.settings.enterToSend;
        if (e.key === 'Enter' && !e.shiftKey && enterToSend) {
            e.preventDefault();
            _doSend();
        }
    });

    input.addEventListener('blur', () => {
        if (App.activeChat?.id) sendTypingStop(App.activeChat.id);
    });

    btnSend?.addEventListener('click', _doSend);

    function _doSend() {
        const text = input.textContent.trim();
        if (!text) return;

        const payload = {
            type:        'text',
            text,
            reply_to_id: replyToMsg?.id || null,
        };
        input.textContent = '';
        btnSend?.classList.add('hidden');
        btnVoice?.classList.remove('hidden');
        _clearReply();
        sendTypingStop(App.activeChat?.id);
        sendMessage(App.activeChat?.id, payload);
    }

    // Scroll load more
    msgs?.addEventListener('scroll', () => {
        if (msgs.scrollTop < 80 && hasMoreMsgs && !loadingMore) {
            loadMessages(App.activeChat?.id, false);
        }
        const atBottom = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 100;
        $('#scrollToBottom')?.classList.toggle('hidden', atBottom);
    });

    // Scroll to bottom btn
    $('#scrollToBottom')?.addEventListener('click', _scrollToBottom);

    // Back button (mobile)
    backBtn?.addEventListener('click', () => {
        document.getElementById('app')?.classList.remove('chat-open');
        App.activeChat = null;
    });

    // Open profile
    headerInfo?.addEventListener('click', () => {
        emit('profile:open', { chat });
    });

    // Attach menu
    btnAttach?.addEventListener('click', () => emit('attach:open', {}));

    // Voice recorder
    btnVoice?.addEventListener('click', () => emit('voice:start', {}));
    btnVoice?.addEventListener('mousedown', () => emit('voice:start', {}));
    btnVoice?.addEventListener('touchstart', (e) => { e.preventDefault(); emit('voice:start', {}); });

    // Emoji panel
    btnEmoji?.addEventListener('click', () => emit('emoji:open', {}));
}

function _scrollToBottom() {
    const msgs = $('#chatMessages');
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
}

/* ──────────────────────────────────────────────────────────
   5. REPLY
────────────────────────────────────────────────────────── */
export function setReply(msg) {
    replyToMsg = msg;
    const inputArea = $('#chatInputArea');
    if (!inputArea) return;

    let bar = inputArea.querySelector('.chat-reply-bar');
    if (!bar) {
        bar = el('div', { class: 'chat-reply-bar' });
        inputArea.prepend(bar);
    }

    const info  = el('div', { class: 'chat-reply-bar__info' });
    info.appendChild(el('div', { class: 'chat-reply-bar__name', html: escapeHtml(msg.sender?.name || '') }));
    info.appendChild(el('div', { class: 'chat-reply-bar__text', html: escapeHtml(msg.text?.slice(0, 80) || '') }));

    const close = el('button', { class: 'chat-reply-bar__close', html: '✕', onclick: _clearReply });

    bar.innerHTML = '';
    bar.appendChild(el('div', { html: '↩', style: 'font-size:18px;color:var(--brand)' }));
    bar.appendChild(info);
    bar.appendChild(close);
}

function _clearReply() {
    replyToMsg = null;
    $('#chatInputArea')?.querySelector('.chat-reply-bar')?.remove();
}

/* ──────────────────────────────────────────────────────────
   6. REAL-TIME HANDLERS
────────────────────────────────────────────────────────── */
function _handleNewMessage(e) {
    const msg = e.detail;

    if (msg.chat_id === App.activeChat?.id) {
        _appendMessage(msg);
        if (isVisible) markSeen(msg.chat_id);
        else playSound('message');
    } else {
        playSound('message');
        _updateChatListItem(msg.chat_id, msg);
        _bumpUnreadBadge(msg.chat_id);
    }
}

function _handleEditedMessage(e) {
    const msg   = e.detail;
    const msgEl = msgMap.get(msg.id);
    if (!msgEl) return;
    const newEl = _buildMessageRow(msg, msgEl.classList.contains('same-sender'));
    msgEl.replaceWith(newEl);
    msgMap.set(msg.id, newEl);
}

function _handleDeletedMessage(e) {
    const { msg_id } = e.detail;
    const msgEl      = msgMap.get(msg_id);
    if (!msgEl) return;
    const bubble = msgEl.querySelector('.msg-bubble');
    if (bubble) bubble.innerHTML = '<div class="msg-text"><em>پیام حذف شده</em></div>';
}

function _handleSeen(e) {
    const { chat_id, user_id } = e.detail;
    if (user_id === App.user?.id) return;
    if (chat_id !== App.activeChat?.id) return;
    // Update all out ticks to "read"
    $$('.msg-row--out .msg-meta__tick').forEach(tick => {
        tick.setAttribute('data-status', 'read');
    });
}

function _handleTypingStart(e) {
    const { chatId, userId } = e.detail;
    if (chatId !== App.activeChat?.id) return;
    if (userId === App.user?.id) return;

    const statusEl = $('#chatStatus');
    if (statusEl) {
        statusEl.dataset._prev = statusEl.innerHTML;
        statusEl.innerHTML = `<span style="color:var(--brand)">در حال تایپ...</span>`;
    }
}

function _handleTypingStop(e) {
    const { chatId } = e.detail;
    if (chatId !== App.activeChat?.id) return;
    const statusEl = $('#chatStatus');
    if (statusEl && statusEl.dataset._prev) {
        statusEl.innerHTML = statusEl.dataset._prev;
        delete statusEl.dataset._prev;
    }
}

function _handlePresence(e) {
    const { user_id } = e.detail;
    const type        = e.type;

    // Update chat header if active chat is with this user
    if (App.activeChat?.other_user?.id === user_id) {
        const statusEl = $('#chatStatus');
        if (statusEl) {
            statusEl.setAttribute('data-status', type === 'presence:online' ? 'online' : '');
            statusEl.textContent = type === 'presence:online' ? 'آنلاین' : 'اخیرا دیده شده';
        }
    }

    // Update chat item online indicator
    const chatItem = $(`.chat-item[data-id]`);
    // We update all chat items that have this user as other_user
    for (const [id, chat] of App.chats) {
        if (chat.other_user?.id === user_id) {
            const indicator = $(`.chat-item[data-id="${id}"] .chat-item__online`);
            const avatarWrap= $(`.chat-item[data-id="${id}"] .chat-item__avatar-wrap`);
            if (type === 'presence:online') {
                if (!indicator && avatarWrap) {
                    avatarWrap.appendChild(el('span', { class: 'chat-item__online' }));
                }
            } else {
                indicator?.remove();
            }
        }
    }
}

/* ──────────────────────────────────────────────────────────
   7. CONTEXT MENUS
────────────────────────────────────────────────────────── */
function _showMsgContextMenu(e, msg) {
    const { showContextMenu } = import('./ui.js').then(m => {
        const isOut = msg.sender_id === App.user?.id;
        const items = [
            { label: '↩ پاسخ',     icon: '↩', action: () => setReply(msg) },
            { label: '📋 کپی',      icon: '📋', action: () => navigator.clipboard?.writeText(msg.text || '') },
            { label: '↪ فوروارد',   icon: '↪', action: () => emit('msg:forward', msg) },
            { label: '📌 پین',      icon: '📌', action: () => _pinMessage(msg.id) },
            ...(isOut ? [
                { label: '✏️ ویرایش', icon: '✏️', action: () => _editMessage(msg) },
                { separator: true },
                { label: '🗑 حذف',  icon: '🗑', action: () => _deleteMessage(msg.id), danger: true },
            ] : [
                { separator: true },
                { label: '🚫 گزارش', icon: '🚫', action: () => emit('msg:report', msg), danger: true },
            ]),
        ];
        m.showContextMenu(e.clientX, e.clientY, items);
    });
}

function _showChatContextMenu(e, chatId) {
    import('./ui.js').then(m => {
        const chat   = App.chats.get(chatId);
        const member = chat?.member || {};
        m.showContextMenu(e.clientX, e.clientY, [
            { label: member.is_pinned ? '📌 برداشتن پین' : '📌 پین کردن', action: () => _togglePinChat(chatId) },
            { label: member.is_muted  ? '🔔 رفع سکوت'   : '🔇 سکوت',     action: () => _toggleMuteChat(chatId) },
            { label: '📁 آرشیو',     action: () => _archiveChat(chatId) },
            { separator: true },
            { label: '🗑 حذف چت',   action: () => emit('chat:delete', { chatId }), danger: true },
        ]);
    });
}

/* ──────────────────────────────────────────────────────────
   8. ACTIONS
────────────────────────────────────────────────────────── */
export async function markSeen(chatId) {
    try {
        await api('POST', '/messages/seen', { chat_id: chatId });
        // Reset badge in chat list
        const badgeEl = $(`.chat-item[data-id="${chatId}"] .chat-item__badge`);
        badgeEl?.remove();
        // Update in App.chats
        const chat = App.chats.get(chatId);
        if (chat?.member) chat.member.unread_count = 0;
    } catch {}
}

async function _toggleReaction(msgId, emoji) {
    try {
        const { reactions } = await api('POST', `/messages/${msgId}/react`, { emoji });
        const msgEl = msgMap.get(msgId);
        if (!msgEl) return;
        const existing = msgEl.querySelector('.msg-reactions');
        const newReactions = _buildReactions(reactions, msgId);
        if (existing) existing.replaceWith(newReactions);
        else msgEl.querySelector('.msg-bubble')?.appendChild(newReactions);
    } catch {}
}

async function _deleteMessage(msgId) {
    const { showConfirm } = await import('./ui.js');
    const confirmed = await showConfirm('حذف پیام', 'آیا این پیام حذف شود؟', 'حذف');
    if (!confirmed) return;
    await api('DELETE', `/messages/${msgId}?for_everyone=true`);
}

function _editMessage(msg) {
    const input = $('#chatInput');
    if (!input) return;
    input.textContent = msg.text;
    input.focus();
    // Place cursor at end
    const range = document.createRange();
    range.selectNodeContents(input);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    emit('msg:editing', msg);
}

async function _pinMessage(msgId) {
    await api('POST', `/messages/${msgId}/pin`).catch(() => {});
}

async function _togglePinChat(chatId) {
    await api('PATCH', `/chats/${chatId}/pin`).catch(() => {});
    await loadChats();
}

async function _toggleMuteChat(chatId) {
    await api('PATCH', `/chats/${chatId}/mute`).catch(() => {});
    await loadChats();
}

async function _archiveChat(chatId) {
    await api('PATCH', `/chats/${chatId}/archive`).catch(() => {});
    await loadChats();
}

/* ──────────────────────────────────────────────────────────
   9. HELPERS
────────────────────────────────────────────────────────── */
function _updateChatListItem(chatId, msg) {
    const item  = $(`.chat-item[data-id="${chatId}"]`);
    if (!item) return;
    const lastEl = $(`#chat-last-${chatId}`);
    const timeEl = item.querySelector('.chat-item__time');
    if (lastEl) lastEl.innerHTML = _formatLastMsg(msg, App.chats.get(chatId)?.type || 'direct');
    if (timeEl) timeEl.textContent = formatDate(msg.created_at);
    // Move to top
    const list = item.parentElement;
    list?.prepend(item);
}

function _bumpUnreadBadge(chatId) {
    const item  = $(`.chat-item[data-id="${chatId}"]`);
    if (!item) return;
    let badge   = item.querySelector('.chat-item__badge');
    if (!badge) {
        badge   = el('div', { class: 'chat-item__badge' });
        item.querySelector('.chat-item__badges')?.prepend(badge);
    }
    const cur   = parseInt(badge.textContent) || 0;
    badge.textContent = cur < 99 ? cur + 1 : '99+';
    badge.classList.add('badge--tick-up');
    setTimeout(() => badge.classList.remove('badge--tick-up'), 300);
}

function _observeLoadMore() {
    const target = document.querySelector('#msgsLoadMore');
    if (!target) return;
    const io = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting && hasMoreMsgs && !loadingMore) {
            loadMessages(App.activeChat?.id, false);
        }
    }, { threshold: 0.1 });
    io.observe(target);
}

function _openMediaViewer(msg) {
    emit('media:open', { msg });
}

function _detectDir(text = '') {
    const rtl = /[\u0600-\u06FF\u0750-\u077F]/.test(text);
    return rtl ? 'rtl' : 'ltr';
}

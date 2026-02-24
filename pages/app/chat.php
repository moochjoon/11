<?php
declare(strict_types=1);

/**
 * Main Chat Page
 * Namak Messenger — pages/app/chat.php
 *
 * Full Telegram-style layout:
 *  ┌─────────────┬────────────────────────────┐
 *  │  Sidebar    │      Chat Area             │
 *  │  - Search   │  - Header (peer info)      │
 *  │  - Chat list│  - Messages (virtualized)  │
 *  │  - Nav      │  - Input bar               │
 *  └─────────────┴────────────────────────────┘
 *
 * Real-time: AJAX polling (no SSE/WebSocket)
 * E2E: Secret chats encrypted client-side via WebCrypto
 * PWA: service worker + manifest
 */

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use Namak\Services\Auth;
use Namak\Core\Request;
use Namak\Repositories\UserRepository;

$request = new Request();
$auth    = new Auth();

// ── Auth guard ────────────────────────────────────────────────────────────────
$token  = $_COOKIE['access_token'] ?? $request->getBearerToken();
$userId = null;
if ($token) {
    $payload = $auth->validateToken($token);
    $userId  = $payload['sub'] ?? null;
}
if (!$userId) {
    header('Location: /auth/login');
    exit;
}

// ── Load current user (lightweight) ──────────────────────────────────────────
$userRepo = new UserRepository();
$me       = $userRepo->getPublicProfile((int) $userId);
if (!$me || !$me['is_active'] ?? true) {
    header('Location: /auth/login');
    exit;
}

$lang    = $_COOKIE['lang'] ?? 'en';
$dir     = $lang === 'fa' ? 'rtl' : 'ltr';
$appName = $_ENV['APP_NAME'] ?? 'Namak';
$appLogo = $_ENV['APP_LOGO'] ?? null;

// Pre-selected chat from URL query (?chat=123)
$openChatId = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2196f3">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName) ?>">
    <title><?= htmlspecialchars($appName) ?></title>

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">

    <!-- Local CSS — zero CDN -->
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">

    <!-- Theme loaded from localStorage before paint (prevents FOUC) -->
    <script>
        (function(){
            var t = localStorage.getItem('namak_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="app-body" data-user-id="<?= (int) $userId ?>" data-lang="<?= htmlspecialchars($lang) ?>" data-dir="<?= $dir ?>">

<!-- ══════════════════════════════════════════════════════════════════════════
     APP SHELL
══════════════════════════════════════════════════════════════════════════ -->
<div class="app-shell" id="app-shell">

    <!-- ════════════════════════════════════════
         SIDEBAR
    ════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar" role="complementary" aria-label="Conversations">

        <!-- Sidebar header -->
        <header class="sidebar__header">
            <!-- Hamburger / back (mobile) -->
            <button class="icon-btn sidebar__menu-btn" id="btn-menu" aria-label="Menu" aria-expanded="false" aria-controls="sidebar-drawer">
                <svg class="icon icon--menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>

            <!-- Search bar -->
            <div class="sidebar__search" role="search">
                <svg class="sidebar__search-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                </svg>
                <input
                        type="search"
                        class="sidebar__search-input"
                        id="search-input"
                        placeholder="Search"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
                        maxlength="64"
                        aria-label="Search chats and users"
                >
                <button class="icon-btn sidebar__search-clear" id="btn-search-clear" aria-label="Clear search" hidden>✕</button>
            </div>

            <!-- Compose new chat -->
            <button class="icon-btn sidebar__compose" id="btn-compose" aria-label="New message" title="New message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
            </button>
        </header>

        <!-- Chat filter tabs -->
        <nav class="chat-tabs" role="tablist" aria-label="Chat filters">
            <button class="chat-tab active" role="tab" aria-selected="true" data-filter="all" id="tab-all">All</button>
            <button class="chat-tab" role="tab" aria-selected="false" data-filter="private" id="tab-private">Private</button>
            <button class="chat-tab" role="tab" aria-selected="false" data-filter="group" id="tab-groups">Groups</button>
            <button class="chat-tab" role="tab" aria-selected="false" data-filter="channel" id="tab-channels">Channels</button>
        </nav>

        <!-- Chat list -->
        <div class="chat-list" id="chat-list" role="list" aria-live="polite" aria-label="Conversations">
            <!-- Skeleton loaders shown while loading -->
            <?php for ($i = 0; $i < 8; $i++): ?>
                <div class="chat-item-skeleton" aria-hidden="true">
                    <div class="skeleton skeleton--avatar"></div>
                    <div class="skeleton-body">
                        <div class="skeleton skeleton--line skeleton--short"></div>
                        <div class="skeleton skeleton--line skeleton--long"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Sidebar footer nav -->
        <nav class="sidebar__footer" role="navigation" aria-label="App navigation">
            <a href="/app/chat"     class="sidebar__nav-item active" aria-label="Chats" title="Chats">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                </svg>
                <span>Chats</span>
            </a>
            <a href="/app/contacts" class="sidebar__nav-item" aria-label="Contacts" title="Contacts">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
                </svg>
                <span>Contacts</span>
            </a>
            <a href="/app/settings" class="sidebar__nav-item" aria-label="Settings" title="Settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                </svg>
                <span>Settings</span>
            </a>
            <!-- Avatar + name -->
            <a href="/app/profile" class="sidebar__nav-item sidebar__nav-item--profile" aria-label="My profile">
                <div class="sidebar__my-avatar">
                    <?php if (!empty($me['avatar'])): ?>
                        <img src="/api/v1/media/download/<?= htmlspecialchars($me['avatar']) ?>"
                             alt="<?= htmlspecialchars($me['first_name']) ?>"
                             class="avatar avatar--sm">
                    <?php else: ?>
                        <div class="avatar avatar--sm avatar--fallback">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($me['first_name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <span><?= htmlspecialchars($me['first_name']) ?></span>
            </a>
        </nav>

    </aside><!-- /sidebar -->

    <!-- ════════════════════════════════════════
         CHAT AREA (empty state by default)
    ════════════════════════════════════════ -->
    <main class="chat-area" id="chat-area" role="main">

        <!-- ── Empty / welcome state ─────────────────────────────────────── -->
        <div class="chat-welcome" id="chat-welcome">
            <div class="chat-welcome__inner">
                <?php if ($appLogo): ?>
                    <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>" class="chat-welcome__logo">
                <?php else: ?>
                    <div class="chat-welcome__icon" aria-hidden="true">
                        <svg viewBox="0 0 80 80" fill="none">
                            <circle cx="40" cy="40" r="40" fill="var(--primary-light)"/>
                            <path d="M20 40l12 12 28-28" stroke="var(--primary)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <h2 class="chat-welcome__title"><?= htmlspecialchars($appName) ?></h2>
                <p class="chat-welcome__subtitle">
                    Select a conversation or start a new one.<br>
                    Your messages are end-to-end encrypted in secret chats.
                </p>
                <button class="btn btn--primary" id="btn-new-chat-welcome">
                    New Message
                </button>
            </div>
        </div>

        <!-- ── Active chat (hidden until a chat is selected) ─────────────── -->
        <div class="chat-view" id="chat-view" hidden>

            <!-- Chat header -->
            <header class="chat-header" id="chat-header">
                <!-- Mobile back button -->
                <button class="icon-btn chat-header__back" id="btn-chat-back" aria-label="Back to chat list">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 5l-7 7 7 7"/>
                    </svg>
                </button>

                <!-- Peer avatar -->
                <div class="chat-header__avatar" id="chat-header-avatar" aria-hidden="true">
                    <div class="avatar avatar--md avatar--fallback" id="chat-header-avatar-el">?</div>
                </div>

                <!-- Peer info -->
                <div class="chat-header__info" id="chat-header-info" role="button" tabindex="0"
                     aria-label="View chat info" id="btn-chat-info">
                    <h2 class="chat-header__name" id="chat-header-name">—</h2>
                    <p class="chat-header__status" id="chat-header-status" aria-live="polite"></p>
                </div>

                <!-- Header actions -->
                <div class="chat-header__actions">
                    <button class="icon-btn" id="btn-search-msgs" aria-label="Search messages" title="Search in chat">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <button class="icon-btn" id="btn-call-voice" aria-label="Voice call" title="Voice call">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
                        </svg>
                    </button>
                    <button class="icon-btn" id="btn-call-video" aria-label="Video call" title="Video call">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                        </svg>
                    </button>
                    <button class="icon-btn" id="btn-chat-more" aria-label="More options" title="More" aria-haspopup="true">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                        </svg>
                    </button>
                </div>

                <!-- Chat more dropdown -->
                <div class="dropdown" id="chat-more-dropdown" role="menu" hidden>
                    <button class="dropdown__item" role="menuitem" data-action="mute">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM12.293 7.293a1 1 0 011.414 0L15 8.586l1.293-1.293a1 1 0 111.414 1.414L16.414 10l1.293 1.293a1 1 0 01-1.414 1.414L15 11.414l-1.293 1.293a1 1 0 01-1.414-1.414L13.586 10l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        Mute
                    </button>
                    <button class="dropdown__item" role="menuitem" data-action="pin">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599.8a1 1 0 01-.666 1.843L14 8.219V15a1 1 0 01-.293.707l-2 2A1 1 0 0110 17v-1.78l-1.293 1.293a1 1 0 01-1.414-1.414l2-2A1 1 0 019 13V8.22l-1.887.24a1 1 0 01-.666-1.844l1.6-.8L12 4.323V3a1 1 0 011-1h-3z"/></svg>
                        Pin Chat
                    </button>
                    <button class="dropdown__item" role="menuitem" data-action="archive">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4zM3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/></svg>
                        Archive
                    </button>
                    <div class="dropdown__divider"></div>
                    <button class="dropdown__item dropdown__item--danger" role="menuitem" data-action="clear-history">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        Clear History
                    </button>
                    <button class="dropdown__item dropdown__item--danger" role="menuitem" data-action="delete-chat">
                        Delete Chat
                    </button>
                </div>

            </header><!-- /chat-header -->

            <!-- ── Pinned message bar ── -->
            <div class="pinned-bar" id="pinned-bar" hidden role="note" aria-label="Pinned message">
                <div class="pinned-bar__accent" aria-hidden="true"></div>
                <div class="pinned-bar__content">
                    <span class="pinned-bar__label">Pinned Message</span>
                    <p class="pinned-bar__text" id="pinned-bar-text"></p>
                </div>
                <button class="icon-btn pinned-bar__close" id="btn-unpin" aria-label="Unpin message">✕</button>
            </div>

            <!-- ── Message search bar (shown when search icon clicked) ── -->
            <div class="msg-search-bar" id="msg-search-bar" hidden role="search" aria-label="Search messages">
                <button class="icon-btn" id="btn-msg-search-prev" aria-label="Previous result">↑</button>
                <input type="search" class="msg-search-bar__input" id="msg-search-input"
                       placeholder="Search in chat…" maxlength="128">
                <span class="msg-search-bar__count" id="msg-search-count" aria-live="polite"></span>
                <button class="icon-btn" id="btn-msg-search-next" aria-label="Next result">↓</button>
                <button class="icon-btn" id="btn-msg-search-close" aria-label="Close search">✕</button>
            </div>

            <!-- ── Messages container ── -->
            <div class="messages-area" id="messages-area"
                 role="log" aria-live="polite" aria-label="Messages"
                 aria-atomic="false">

                <!-- Load more trigger (intersection observer) -->
                <div class="load-more-trigger" id="load-more-trigger" aria-hidden="true"></div>

                <!-- Messages injected by JS -->
                <div class="messages-list" id="messages-list"></div>

                <!-- Typing indicator -->
                <div class="typing-indicator" id="typing-indicator" aria-live="polite" hidden>
                    <span class="typing-indicator__name" id="typing-name"></span>
                    <span class="typing-indicator__dots" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </span>
                </div>

            </div><!-- /messages-area -->

            <!-- ── Scroll to bottom FAB ── -->
            <button class="scroll-fab" id="scroll-fab" aria-label="Scroll to bottom" hidden>
                <svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.707 10.293a1 1 0 010 1.414l-6 6a1 1 0 01-1.414 0l-6-6a1 1 0 111.414-1.414L9 14.586V3a1 1 0 012 0v11.586l4.293-4.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
                <span class="scroll-fab__badge" id="scroll-fab-badge" hidden></span>
            </button>

            <!-- ── Reply / Edit bar (shown above input when replying/editing) ── -->
            <div class="reply-bar" id="reply-bar" hidden>
                <div class="reply-bar__accent" aria-hidden="true"></div>
                <div class="reply-bar__content">
                    <span class="reply-bar__label" id="reply-bar-label">Reply to</span>
                    <p class="reply-bar__text" id="reply-bar-text"></p>
                </div>
                <button class="icon-btn reply-bar__close" id="btn-reply-cancel" aria-label="Cancel">✕</button>
            </div>

            <!-- ── Message input bar ── -->
            <footer class="input-bar" id="input-bar" role="group" aria-label="Message input">

                <!-- Attach button -->
                <div class="input-bar__attach-wrap">
                    <button class="icon-btn input-bar__attach" id="btn-attach" aria-label="Attach file" aria-haspopup="true" title="Attach">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>
                        </svg>
                    </button>
                    <!-- Attach menu popup -->
                    <div class="attach-menu" id="attach-menu" role="menu" hidden>
                        <button class="attach-menu__item" role="menuitem" data-attach="image" aria-label="Photo or video">
                            <span class="attach-menu__icon attach-menu__icon--photo">🖼</span>
                            <span>Photo / Video</span>
                        </button>
                        <button class="attach-menu__item" role="menuitem" data-attach="audio" aria-label="Audio file">
                            <span class="attach-menu__icon attach-menu__icon--audio">🎵</span>
                            <span>Audio</span>
                        </button>
                        <button class="attach-menu__item" role="menuitem" data-attach="file" aria-label="Document">
                            <span class="attach-menu__icon attach-menu__icon--file">📄</span>
                            <span>Document</span>
                        </button>
                        <button class="attach-menu__item" role="menuitem" data-attach="location" aria-label="Location">
                            <span class="attach-menu__icon attach-menu__icon--loc">📍</span>
                            <span>Location</span>
                        </button>
                    </div>
                </div>

                <!-- Hidden file inputs -->
                <input type="file" id="file-image"    accept="image/*,video/*" multiple style="display:none" aria-hidden="true">
                <input type="file" id="file-audio"    accept="audio/*"         style="display:none" aria-hidden="true">
                <input type="file" id="file-document" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z,.txt,.csv,.json" style="display:none" aria-hidden="true">

                <!-- Emoji button -->
                <button class="icon-btn input-bar__emoji" id="btn-emoji" aria-label="Emoji" title="Emoji">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>
                    </svg>
                </button>

                <!-- Text input (auto-grow) -->
                <div class="input-bar__composer-wrap">
                    <div
                            class="input-bar__composer"
                            id="msg-composer"
                            contenteditable="true"
                            role="textbox"
                            aria-multiline="true"
                            aria-label="Message"
                            data-placeholder="Message…"
                            spellcheck="true"
                            autocorrect="on"
                    ></div>
                </div>

                <!-- Voice / Send toggle -->
                <div class="input-bar__send-wrap">
                    <!-- Voice record button (shown when composer is empty) -->
                    <button class="icon-btn input-bar__voice" id="btn-voice" aria-label="Record voice message" title="Voice message">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/>
                            <path d="M19 10v2a7 7 0 01-14 0v-2M12 19v4M8 23h8"/>
                        </svg>
                    </button>
                    <!-- Send button (shown when composer has text) -->
                    <button class="icon-btn input-bar__send" id="btn-send" aria-label="Send message" title="Send" hidden>
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>

            </footer><!-- /input-bar -->

            <!-- ── Voice recording UI (shown during recording) ── -->
            <div class="voice-recording" id="voice-recording" hidden role="status" aria-live="polite">
                <button class="icon-btn voice-recording__cancel" id="btn-voice-cancel" aria-label="Cancel recording">✕</button>
                <div class="voice-recording__viz" id="voice-viz" aria-hidden="true">
                    <?php for ($i = 0; $i < 20; $i++): ?>
                        <span class="voice-viz-bar"></span>
                    <?php endfor; ?>
                </div>
                <span class="voice-recording__timer" id="voice-timer" aria-label="Recording duration">0:00</span>
                <button class="icon-btn voice-recording__send" id="btn-voice-send" aria-label="Send voice message">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>

        </div><!-- /chat-view -->

    </main><!-- /chat-area -->

</div><!-- /app-shell -->

<!-- ════════════════════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════════════════════ -->

<!-- ── New Chat / Compose modal ── -->
<div class="modal-overlay" id="compose-modal" role="dialog" aria-modal="true" aria-label="New conversation" hidden>
    <div class="modal">
        <div class="modal__header">
            <h2 class="modal__title">New Message</h2>
            <button class="icon-btn modal__close" data-close="compose-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <!-- Chat type selector -->
            <div class="compose-type-selector">
                <button class="compose-type active" data-type="private" aria-pressed="true">
                    <span>💬</span> Private
                </button>
                <button class="compose-type" data-type="secret" aria-pressed="false">
                    <span>🔐</span> Secret
                </button>
                <button class="compose-type" data-type="group" aria-pressed="false">
                    <span>👥</span> Group
                </button>
                <button class="compose-type" data-type="channel" aria-pressed="false">
                    <span>📢</span> Channel
                </button>
            </div>

            <!-- User search -->
            <div class="form-group" id="compose-search-wrap">
                <div class="form-input-wrap">
                    <span class="form-input-icon">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    </span>
                    <input type="search" id="compose-search" class="form-input"
                           placeholder="Username, phone or email…"
                           autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="64">
                </div>
                <div class="compose-results" id="compose-results" role="listbox" aria-label="Search results"></div>
            </div>

            <!-- Group/channel extra fields (hidden for private/secret) -->
            <div id="compose-group-fields" hidden>
                <div class="form-group">
                    <input type="text" id="compose-title" class="form-input" placeholder="Group / Channel name" maxlength="128">
                </div>
                <div class="selected-members" id="selected-members" aria-label="Selected members"></div>
            </div>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="compose-modal">Cancel</button>
            <button class="btn btn--primary" id="btn-compose-create" disabled>Create</button>
        </div>
    </div>
</div>

<!-- ── Message context menu ── -->
<div class="context-menu" id="msg-context-menu" role="menu" hidden>
    <button class="context-menu__item" role="menuitem" data-action="reply">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
        Reply
    </button>
    <button class="context-menu__item" role="menuitem" data-action="forward">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.293 3.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L14.586 9H9a3 3 0 00-3 3v1a1 1 0 11-2 0v-1a5 5 0 015-5h5.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        Forward
    </button>
    <button class="context-menu__item" role="menuitem" data-action="copy">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/></svg>
        Copy Text
    </button>
    <button class="context-menu__item" role="menuitem" data-action="edit" id="ctx-edit">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
        Edit
    </button>
    <button class="context-menu__item" role="menuitem" data-action="pin">
        📌 Pin Message
    </button>
    <button class="context-menu__item" role="menuitem" data-action="select">
        ☑ Select
    </button>
    <div class="context-menu__divider"></div>
    <button class="context-menu__item context-menu__item--danger" role="menuitem" data-action="delete">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        Delete
    </button>
</div>

<!-- ── Delete confirmation modal ── -->
<div class="modal-overlay" id="delete-modal" role="dialog" aria-modal="true" aria-label="Delete message" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">Delete Message</h2>
            <button class="icon-btn modal__close" data-close="delete-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="color:var(--text-muted);font-size:14px">Are you sure you want to delete this message?</p>
            <label class="form-check" id="delete-for-all-wrap" style="margin-top:12px">
                <input type="checkbox" id="delete-for-all" class="form-check__input">
                <span class="form-check__box"></span>
                <span class="form-check__label">Delete for everyone</span>
            </label>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="delete-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-delete-confirm">Delete</button>
        </div>
    </div>
</div>

<!-- ── Forward message modal ── -->
<div class="modal-overlay" id="forward-modal" role="dialog" aria-modal="true" aria-label="Forward message" hidden>
    <div class="modal">
        <div class="modal__header">
            <h2 class="modal__title">Forward to…</h2>
            <button class="icon-btn modal__close" data-close="forward-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <div class="form-input-wrap" style="margin-bottom:12px">
                <span class="form-input-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                </span>
                <input type="search" id="forward-search" class="form-input" placeholder="Search chats…"
                       autocomplete="off" maxlength="64">
            </div>
            <div class="forward-list" id="forward-list" role="listbox" aria-multiselectable="true"></div>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="forward-modal">Cancel</button>
            <button class="btn btn--primary" id="btn-forward-send" disabled>Forward</button>
        </div>
    </div>
</div>

<!-- ── Emoji picker (lightweight, built-in) ── -->
<div class="emoji-picker" id="emoji-picker" hidden role="dialog" aria-label="Choose emoji">
    <div class="emoji-picker__tabs" role="tablist">
        <button class="emoji-tab active" data-cat="recent"  title="Recent">🕒</button>
        <button class="emoji-tab" data-cat="smileys" title="Smileys">😀</button>
        <button class="emoji-tab" data-cat="people"  title="People">👋</button>
        <button class="emoji-tab" data-cat="nature"  title="Nature">🌿</button>
        <button class="emoji-tab" data-cat="food"    title="Food">🍕</button>
        <button class="emoji-tab" data-cat="travel"  title="Travel">✈️</button>
        <button class="emoji-tab" data-cat="objects" title="Objects">💡</button>
        <button class="emoji-tab" data-cat="symbols" title="Symbols">❤️</button>
    </div>
    <input type="search" class="emoji-picker__search" id="emoji-search" placeholder="Search emoji…" maxlength="32">
    <div class="emoji-picker__grid" id="emoji-grid" role="listbox" aria-label="Emoji list"></div>
</div>

<!-- ── Media lightbox ── -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Media viewer" hidden>
    <button class="lightbox__close" id="lightbox-close" aria-label="Close">✕</button>
    <button class="lightbox__prev" id="lightbox-prev" aria-label="Previous">‹</button>
    <div class="lightbox__content" id="lightbox-content"></div>
    <button class="lightbox__next" id="lightbox-next" aria-label="Next">›</button>
    <div class="lightbox__caption" id="lightbox-caption"></div>
    <button class="lightbox__download" id="lightbox-download" aria-label="Download">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TOAST CONTAINER
══════════════════════════════════════════════════════════════════════════ -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ══════════════════════════════════════════════════════════════════════════
     INLINE APP CONFIG (passed to JS, no inline secrets)
══════════════════════════════════════════════════════════════════════════ -->
<script>
    window.NAMAK_CONFIG = {
        userId:    <?= (int) $userId ?>,
        username:  <?= json_encode($me['username']) ?>,
        firstName: <?= json_encode($me['first_name']) ?>,
        lastName:  <?= json_encode($me['last_name'] ?? '') ?>,
        avatar:    <?= json_encode($me['avatar'] ?? null) ?>,
        publicKey: <?= json_encode($me['public_key'] ?? '') ?>,
        lang:      <?= json_encode($lang) ?>,
        dir:       <?= json_encode($dir) ?>,
        appName:   <?= json_encode($appName) ?>,
        openChatId:<?= (int) $openChatId ?>,
        pollInterval: 2000,   // ms between polling requests
        api:       '/api/v1',
    };
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     SCRIPTS (all local — zero CDN)
══════════════════════════════════════════════════════════════════════════ -->
<script src="/assets/js/modules/storage.js"></script>
<script src="/assets/js/modules/api.js"></script>
<script src="/assets/js/modules/lang.js"></script>
<script src="/assets/js/modules/ui.js"></script>
<script src="/assets/js/modules/media.js"></script>
<script src="/assets/js/modules/auth.js"></script>
<script src="/assets/js/modules/chat.js"></script>
<script src="/assets/js/vendor/app.js"></script>

<script>
    // ── Boot ──────────────────────────────────────────────────────────────────────
    (function () {
        'use strict';

        // ── Theme ─────────────────────────────────────────────────────────────────
        const savedTheme = localStorage.getItem('namak_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // ── Service Worker ────────────────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker
                .register('/assets/js/service-worker.js', { scope: '/' })
                .then(reg => {
                    reg.addEventListener('updatefound', () => {
                        const nw = reg.installing;
                        nw.addEventListener('statechange', () => {
                            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                                // New version available — show toast
                                window.NamakUI?.toast('App updated. Reload to get the latest version.', 'info', 0);
                            }
                        });
                    });
                })
                .catch(e => console.warn('[SW]', e));
        }

        // ── Close dropdowns / context menus on outside click ─────────────────────
        document.addEventListener('click', e => {
            if (!e.target.closest('#chat-more-dropdown') && !e.target.closest('#btn-chat-more')) {
                document.getElementById('chat-more-dropdown').hidden = true;
            }
            if (!e.target.closest('#attach-menu') && !e.target.closest('#btn-attach')) {
                document.getElementById('attach-menu').hidden = true;
            }
            if (!e.target.closest('#msg-context-menu')) {
                document.getElementById('msg-context-menu').hidden = true;
            }
            if (!e.target.closest('#emoji-picker') && !e.target.closest('#btn-emoji')) {
                document.getElementById('emoji-picker').hidden = true;
            }
        });

        // ── Close modals on overlay click or ESC ─────────────────────────────────
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) {
                    const id = overlay.id;
                    // Key modal cannot be closed without confirming
                    if (id !== 'key-modal') overlay.hidden = true;
                }
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => {
                    if (m.id !== 'key-modal') m.hidden = true;
                });
                document.getElementById('msg-context-menu').hidden = true;
                document.getElementById('emoji-picker').hidden = true;
            }
        });

        // ── Modal close buttons ───────────────────────────────────────────────────
        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.getElementById(btn.dataset.close);
                if (target) target.hidden = true;
            });
        });

        // ── Chat more dropdown ────────────────────────────────────────────────────
        document.getElementById('btn-chat-more').addEventListener('click', e => {
            e.stopPropagation();
            const dd = document.getElementById('chat-more-dropdown');
            dd.hidden = !dd.hidden;
        });

        // ── Attach menu ───────────────────────────────────────────────────────────
        document.getElementById('btn-attach').addEventListener('click', e => {
            e.stopPropagation();
            const menu = document.getElementById('attach-menu');
            menu.hidden = !menu.hidden;
        });

        document.querySelectorAll('.attach-menu__item').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('attach-menu').hidden = true;
                const type = btn.dataset.attach;
                if (type === 'image')    document.getElementById('file-image').click();
                if (type === 'audio')    document.getElementById('file-audio').click();
                if (type === 'file')     document.getElementById('file-document').click();
                if (type === 'location') window.NamakChat?.sendLocation();
            });
        });

        // ── Composer: show/hide send vs voice button ──────────────────────────────
        const composer = document.getElementById('msg-composer');
        const btnSend  = document.getElementById('btn-send');
        const btnVoice = document.getElementById('btn-voice');

        composer.addEventListener('input', () => {
            const hasText = composer.textContent.trim().length > 0;
            btnSend.hidden  = !hasText;
            btnVoice.hidden = hasText;
            // Notify chat module for typing indicator
            window.NamakChat?.onComposerInput();
        });

        // Enter = send, Shift+Enter = newline
        composer.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                window.NamakChat?.sendTextMessage();
            }
        });

        // ── Send button click ─────────────────────────────────────────────────────
        btnSend.addEventListener('click', () => window.NamakChat?.sendTextMessage());

        // ── Mobile back button (sidebar toggle) ──────────────────────────────────
        document.getElementById('btn-chat-back').addEventListener('click', () => {
            document.getElementById('app-shell').classList.remove('app-shell--chat-open');
            document.getElementById('chat-area').classList.remove('chat-area--active');
        });

        // ── Compose modal ─────────────────────────────────────────────────────────
        const openCompose = () => {
            document.getElementById('compose-modal').hidden = false;
            document.getElementById('compose-search').focus();
        };
        document.getElementById('btn-compose').addEventListener('click', openCompose);
        document.getElementById('btn-new-chat-welcome').addEventListener('click', openCompose);

        // ── Chat type selector (in compose modal) ─────────────────────────────────
        document.querySelectorAll('.compose-type').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.compose-type').forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-pressed', 'false');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-pressed', 'true');
                const type = btn.dataset.type;
                const groupFields = document.getElementById('compose-group-fields');
                groupFields.hidden = !['group', 'channel'].includes(type);
            });
        });

        // ── Chat tab filter ───────────────────────────────────────────────────────
        document.querySelectorAll('.chat-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.chat-tab').forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                window.NamakChat?.filterChats(tab.dataset.filter);
            });
        });

        // ── Message search ────────────────────────────────────────────────────────
        document.getElementById('btn-search-msgs').addEventListener('click', () => {
            const bar = document.getElementById('msg-search-bar');
            bar.hidden = !bar.hidden;
            if (!bar.hidden) document.getElementById('msg-search-input').focus();
        });
        document.getElementById('btn-msg-search-close').addEventListener('click', () => {
            document.getElementById('msg-search-bar').hidden = true;
            document.getElementById('msg-search-input').value = '';
            window.NamakChat?.clearMessageSearch();
        });

        // ── Scroll to bottom FAB ──────────────────────────────────────────────────
        document.getElementById('scroll-fab').addEventListener('click', () => {
            window.NamakChat?.scrollToBottom(true);
        });
        document.getElementById('messages-area').addEventListener('scroll', function () {
            const fab     = document.getElementById('scroll-fab');
            const fromBottom = this.scrollHeight - this.scrollTop - this.clientHeight;
            fab.hidden = fromBottom < 200;
        });

        // ── Chat info click (header) ──────────────────────────────────────────────
        document.getElementById('chat-header-info').addEventListener('click', () => {
            window.NamakChat?.openChatInfo();
        });
        document.getElementById('chat-header-info').addEventListener('keydown', e => {
            if (e.key === 'Enter') window.NamakChat?.openChatInfo();
        });

        // ── Emoji picker toggle ───────────────────────────────────────────────────
        document.getElementById('btn-emoji').addEventListener('click', e => {
            e.stopPropagation();
            const picker = document.getElementById('emoji-picker');
            picker.hidden = !picker.hidden;
            if (!picker.hidden) window.NamakUI?.initEmojiPicker();
        });

        // ── Lightbox close ────────────────────────────────────────────────────────
        document.getElementById('lightbox-close').addEventListener('click', () => {
            document.getElementById('lightbox').hidden = true;
        });
        document.getElementById('lightbox').addEventListener('click', e => {
            if (e.target === document.getElementById('lightbox')) {
                document.getElementById('lightbox').hidden = true;
            }
        });

        // ── Reply bar cancel ──────────────────────────────────────────────────────
        document.getElementById('btn-reply-cancel').addEventListener('click', () => {
            document.getElementById('reply-bar').hidden = true;
            window.NamakChat?.cancelReply();
        });

        // ── Voice recording ───────────────────────────────────────────────────────
        document.getElementById('btn-voice').addEventListener('click', () => window.NamakChat?.startVoiceRecording());
        document.getElementById('btn-voice-cancel').addEventListener('click', () => window.NamakChat?.cancelVoiceRecording());
        document.getElementById('btn-voice-send').addEventListener('click', () => window.NamakChat?.sendVoiceMessage());

        // ── Init main app module ──────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            if (window.NamakApp) {
                window.NamakApp.init(window.NAMAK_CONFIG);
            }
        });

        // If DOM already ready
        if (document.readyState !== 'loading') {
            if (window.NamakApp) window.NamakApp.init(window.NAMAK_CONFIG);
        }

    })();
</script>

</body>
</html>

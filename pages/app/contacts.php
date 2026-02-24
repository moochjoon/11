<?php
declare(strict_types=1);

/**
 * Contacts Page
 * Namak Messenger — pages/app/contacts.php
 *
 * Features:
 *  - Contact list (alphabetically grouped)
 *  - Search contacts (by username / phone / email — NOT by name)
 *  - Add contact (via username / phone / email)
 *  - Remove contact
 *  - Block / Unblock
 *  - Blocked users list
 *  - Quick actions: Message, Secret Chat, View Profile
 *  - Import contacts from device (Web Contacts API — optional)
 *  - Export contacts as vCard / JSON
 */

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use Namak\Services\Auth;
use Namak\Core\Request;
use Namak\Repositories\UserRepository;

$request = new Request();
$auth    = new Auth();

// ── Auth guard ────────────────────────────────────────────────────────────────
$token  = $_COOKIE['access_token'] ?? $request->getBearerToken();
$myId   = null;
if ($token) {
    $payload = $auth->validateToken($token);
    $myId    = $payload['sub'] ?? null;
}
if (!$myId) {
    header('Location: /auth/login');
    exit;
}

$userRepo = new UserRepository();
$me       = $userRepo->findById((int) $myId);
if (!$me) {
    header('Location: /auth/login');
    exit;
}

$lang    = $_COOKIE['lang'] ?? 'en';
$dir     = $lang === 'fa' ? 'rtl' : 'ltr';
$appName = $_ENV['APP_NAME'] ?? 'Namak';

// Active sub-tab from query (?tab=blocked)
$activeTab = in_array($_GET['tab'] ?? '', ['contacts','blocked'], true)
    ? $_GET['tab']
    : 'contacts';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#2196f3">
    <meta name="robots" content="noindex, nofollow">
    <title>Contacts — <?= htmlspecialchars($appName) ?></title>

    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/contacts.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">

    <script>
        (function(){
            var t = localStorage.getItem('namak_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="app-body contacts-page"
      data-user-id="<?= (int) $myId ?>"
      data-active-tab="<?= htmlspecialchars($activeTab) ?>">

<div class="app-shell" id="app-shell">

    <!-- ════════════════════════════════════════
         SIDEBAR
    ════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar" role="complementary" aria-label="Navigation">
        <header class="sidebar__header">
            <a href="/app/chat" class="icon-btn" aria-label="Back to chats">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 5l-7 7 7 7"/>
                </svg>
            </a>
            <span class="sidebar__header-title">Contacts</span>
            <button class="icon-btn" id="btn-add-contact" aria-label="Add contact" title="Add contact">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <line x1="20" y1="8" x2="20" y2="14"/>
                    <line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
            </button>
        </header>

        <!-- Contacts search -->
        <div class="sidebar__search" role="search">
            <svg class="sidebar__search-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
            </svg>
            <input
                type="search"
                class="sidebar__search-input"
                id="contacts-search"
                placeholder="Search contacts…"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="none"
                spellcheck="false"
                maxlength="64"
                aria-label="Search contacts"
            >
            <button class="icon-btn sidebar__search-clear" id="btn-contacts-search-clear" hidden aria-label="Clear">✕</button>
        </div>

        <!-- Sub-tabs -->
        <nav class="chat-tabs" role="tablist" aria-label="Contact views">
            <button class="chat-tab <?= $activeTab === 'contacts' ? 'active' : '' ?>"
                    role="tab"
                    aria-selected="<?= $activeTab === 'contacts' ? 'true' : 'false' ?>"
                    data-tab="contacts" id="tab-contacts">
                Contacts
                <span class="chat-tab__badge" id="badge-contacts" hidden></span>
            </button>
            <button class="chat-tab <?= $activeTab === 'blocked' ? 'active' : '' ?>"
                    role="tab"
                    aria-selected="<?= $activeTab === 'blocked' ? 'true' : 'false' ?>"
                    data-tab="blocked" id="tab-blocked">
                Blocked
                <span class="chat-tab__badge" id="badge-blocked" hidden></span>
            </button>
        </nav>

        <nav class="sidebar__footer" role="navigation" aria-label="App navigation">
            <a href="/app/chat"     class="sidebar__nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><span>Chats</span></a>
            <a href="/app/contacts" class="sidebar__nav-item active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg><span>Contacts</span></a>
            <a href="/app/settings" class="sidebar__nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg><span>Settings</span></a>
            <a href="/app/profile"  class="sidebar__nav-item sidebar__nav-item--profile">
                <div class="sidebar__my-avatar">
                    <?php if (!empty($me['avatar'])): ?>
                        <img src="/api/v1/media/download/<?= htmlspecialchars($me['avatar']) ?>" alt="" class="avatar avatar--sm">
                    <?php else: ?>
                        <div class="avatar avatar--sm avatar--fallback"><?= mb_strtoupper(mb_substr($me['first_name'], 0, 1)) ?></div>
                    <?php endif; ?>
                </div>
                <span><?= htmlspecialchars($me['first_name']) ?></span>
            </a>
        </nav>
    </aside>

    <!-- ════════════════════════════════════════
         MAIN CONTENT
    ════════════════════════════════════════ -->
    <main class="contacts-main" id="contacts-main" role="main">

        <!-- ── TOOLBAR ──────────────────────────────────────────────────── -->
        <div class="contacts-toolbar">
            <div class="contacts-toolbar__left">
                <h1 class="contacts-toolbar__title" id="contacts-title">
                    Contacts
                    <span class="contacts-toolbar__count" id="contacts-count" aria-live="polite"></span>
                </h1>
            </div>
            <div class="contacts-toolbar__right">
                <!-- Sort -->
                <select class="form-select form-select--sm" id="contacts-sort" aria-label="Sort contacts">
                    <option value="name-asc">Name A–Z</option>
                    <option value="name-desc">Name Z–A</option>
                    <option value="online">Online first</option>
                    <option value="recent">Recently added</option>
                </select>

                <!-- Import -->
                <button class="btn btn--ghost btn--sm" id="btn-import-contacts"
                        title="Import contacts from device"
                        aria-label="Import contacts">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Import
                </button>

                <!-- Export -->
                <div class="dropdown-wrap">
                    <button class="btn btn--ghost btn--sm" id="btn-export-contacts"
                            aria-label="Export contacts" aria-haspopup="true">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                            <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                        Export
                    </button>
                    <div class="dropdown" id="export-dropdown" role="menu" hidden>
                        <button class="dropdown__item" role="menuitem" data-export="json">
                            📄 Export as JSON
                        </button>
                        <button class="dropdown__item" role="menuitem" data-export="vcard">
                            📇 Export as vCard
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CONTACTS TAB ──────────────────────────────────────────────── -->
        <div class="contacts-panel" id="panel-contacts"
             role="tabpanel" aria-labelledby="tab-contacts"
            <?= $activeTab !== 'contacts' ? 'hidden' : '' ?>>

            <!-- Empty state -->
            <div class="contacts-empty" id="empty-contacts" hidden>
                <div class="contacts-empty__icon" aria-hidden="true">👥</div>
                <h2 class="contacts-empty__title">No Contacts Yet</h2>
                <p class="contacts-empty__sub">
                    Add contacts by their username, phone, or email.<br>
                    They will appear here once added.
                </p>
                <button class="btn btn--primary" id="btn-add-first-contact">
                    Add Your First Contact
                </button>
            </div>

            <!-- Search results (global user search — shown only when searching) -->
            <div class="search-results-section" id="search-results-section" hidden>
                <h3 class="contacts-group-letter">Search Results</h3>
                <div class="contact-list" id="search-results-list"
                     role="list" aria-label="Search results" aria-live="polite"></div>
            </div>

            <!-- Alphabetical contact list -->
            <div id="contacts-alpha-list" role="list" aria-label="Contacts"
                 aria-live="polite" aria-atomic="false">

                <!-- Skeleton while loading -->
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <div class="contact-item-skeleton" aria-hidden="true">
                        <div class="skeleton skeleton--avatar"></div>
                        <div class="skeleton-body">
                            <div class="skeleton skeleton--line skeleton--short"></div>
                            <div class="skeleton skeleton--line skeleton--long"></div>
                        </div>
                    </div>
                <?php endfor; ?>

            </div>

            <!-- Alphabet quick-jump -->
            <nav class="alpha-jump" id="alpha-jump" role="navigation" aria-label="Alphabetical index">
                <?php foreach (range('A','Z') as $letter): ?>
                    <button class="alpha-jump__btn" data-letter="<?= $letter ?>"
                            aria-label="Jump to <?= $letter ?>"><?= $letter ?></button>
                <?php endforeach; ?>
                <button class="alpha-jump__btn" data-letter="#" aria-label="Jump to other">#</button>
            </nav>

        </div><!-- /panel-contacts -->

        <!-- ── BLOCKED USERS TAB ─────────────────────────────────────────── -->
        <div class="contacts-panel" id="panel-blocked"
             role="tabpanel" aria-labelledby="tab-blocked"
            <?= $activeTab !== 'blocked' ? 'hidden' : '' ?>>

            <div class="contacts-empty" id="empty-blocked" hidden>
                <div class="contacts-empty__icon" aria-hidden="true">🚫</div>
                <h2 class="contacts-empty__title">No Blocked Users</h2>
                <p class="contacts-empty__sub">
                    Users you block will appear here.<br>
                    They cannot message you or see your profile.
                </p>
            </div>

            <div class="contact-list" id="blocked-list"
                 role="list" aria-label="Blocked users" aria-live="polite">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="contact-item-skeleton" aria-hidden="true">
                        <div class="skeleton skeleton--avatar"></div>
                        <div class="skeleton-body">
                            <div class="skeleton skeleton--line skeleton--short"></div>
                            <div class="skeleton skeleton--line skeleton--long"></div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

        </div><!-- /panel-blocked -->

    </main>

</div><!-- /app-shell -->

<!-- ════════════════════════════════════════════════════════
     CONTACT DETAIL PANEL (slide-in from right on click)
════════════════════════════════════════════════════════ -->
<aside class="contact-detail" id="contact-detail" role="complementary"
       aria-label="Contact details" hidden>
    <div class="contact-detail__inner">

        <button class="icon-btn contact-detail__close" id="btn-detail-close" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>

        <!-- Avatar + name -->
        <div class="contact-detail__hero">
            <div class="contact-detail__avatar" id="detail-avatar">
                <div class="avatar avatar--xl avatar--fallback" id="detail-avatar-el">?</div>
            </div>
            <h2 class="contact-detail__name" id="detail-name"></h2>
            <p class="contact-detail__username" id="detail-username"></p>
            <p class="contact-detail__status" id="detail-status" aria-live="polite"></p>
        </div>

        <!-- Quick actions -->
        <div class="contact-detail__actions" role="group" aria-label="Actions">
            <button class="contact-detail__action" id="detail-btn-message">
                <span class="contact-detail__action-icon">💬</span>
                <span>Message</span>
            </button>
            <button class="contact-detail__action" id="detail-btn-secret">
                <span class="contact-detail__action-icon">🔐</span>
                <span>Secret</span>
            </button>
            <button class="contact-detail__action" id="detail-btn-call">
                <span class="contact-detail__action-icon">📞</span>
                <span>Call</span>
            </button>
            <button class="contact-detail__action" id="detail-btn-profile">
                <span class="contact-detail__action-icon">👤</span>
                <span>Profile</span>
            </button>
        </div>

        <!-- Info rows -->
        <div class="contact-detail__info" id="detail-info">
            <div class="contact-detail__row" id="detail-row-phone" hidden>
                <svg viewBox="0 0 20 20" fill="currentColor" class="contact-detail__row-icon"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/></svg>
                <div>
                    <p class="contact-detail__row-label">Phone</p>
                    <p class="contact-detail__row-value" id="detail-phone"></p>
                </div>
            </div>
            <div class="contact-detail__row" id="detail-row-bio" hidden>
                <svg viewBox="0 0 20 20" fill="currentColor" class="contact-detail__row-icon"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="contact-detail__row-label">Bio</p>
                    <p class="contact-detail__row-value" id="detail-bio"></p>
                </div>
            </div>
            <div class="contact-detail__row">
                <svg viewBox="0 0 20 20" fill="currentColor" class="contact-detail__row-icon"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                <div>
                    <p class="contact-detail__row-label">Member since</p>
                    <p class="contact-detail__row-value" id="detail-since"></p>
                </div>
            </div>
        </div>

        <!-- Contact-specific notes (local, not synced) -->
        <div class="contact-detail__notes">
            <label class="form-label" for="contact-note">
                Personal note
                <span class="text-muted" style="font-size:11px">(stored locally)</span>
            </label>
            <textarea id="contact-note" class="form-input form-input--textarea"
                      rows="3" maxlength="500"
                      placeholder="Add a personal note about this contact…"></textarea>
            <button class="btn btn--ghost btn--sm" id="btn-save-note" style="margin-top:6px">
                Save Note
            </button>
        </div>

        <!-- Danger actions -->
        <div class="contact-detail__danger">
            <button class="btn btn--ghost btn--sm btn--full" id="detail-btn-remove">
                🗑 Remove Contact
            </button>
            <button class="btn btn--danger btn--sm btn--full" id="detail-btn-block" style="margin-top:8px">
                🚫 Block User
            </button>
        </div>

    </div>
</aside>

<!-- Overlay for contact detail on mobile -->
<div class="contact-detail-overlay" id="contact-detail-overlay" hidden aria-hidden="true"></div>

<!-- ════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════ -->

<!-- ── Add Contact modal ── -->
<div class="modal-overlay" id="add-contact-modal" role="dialog" aria-modal="true"
     aria-label="Add contact" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">Add Contact</h2>
            <button class="icon-btn modal__close" data-close="add-contact-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;line-height:1.6">
                Search by <strong>username</strong>, <strong>phone</strong>, or <strong>email</strong>.
                Users cannot be found by their name for privacy reasons.
            </p>

            <!-- Search input with type toggle -->
            <div class="add-contact-search">
                <div class="form-group" id="add-group-query">
                    <div class="form-input-wrap">
                        <span class="form-input-icon" id="add-search-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                        </span>
                        <input type="text" id="add-contact-query"
                               class="form-input"
                               placeholder="@username, +phone, or email…"
                               autocomplete="off" autocorrect="off"
                               autocapitalize="none" spellcheck="false"
                               maxlength="255"
                               aria-describedby="add-query-err"
                               aria-label="Search for user">
                        <button class="icon-btn" id="btn-add-search" aria-label="Search">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                    <span class="form-error" id="add-query-err" role="alert" aria-live="polite"></span>
                    <span class="form-hint" id="add-search-hint"></span>
                </div>

                <!-- Search results -->
                <div class="add-contact-results" id="add-contact-results"
                     role="listbox" aria-label="Found users" aria-live="polite">
                </div>
            </div>

            <!-- Selected user preview -->
            <div class="add-contact-selected" id="add-contact-selected" hidden>
                <div class="add-contact-card" id="add-selected-card">
                    <!-- filled by JS -->
                </div>
            </div>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="add-contact-modal">Cancel</button>
            <button class="btn btn--primary" id="btn-add-confirm" disabled
                    aria-label="Add selected user as contact">
                Add Contact
            </button>
        </div>
    </div>
</div>

<!-- ── Remove Contact confirm ── -->
<div class="modal-overlay" id="remove-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">Remove Contact</h2>
            <button class="icon-btn modal__close" data-close="remove-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:14px;color:var(--text-muted)" id="remove-modal-text"></p>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="remove-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-remove-confirm">Remove</button>
        </div>
    </div>
</div>

<!-- ── Block confirm ── -->
<div class="modal-overlay" id="block-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title" id="block-modal-title">Block User</h2>
            <button class="icon-btn modal__close" data-close="block-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:14px;color:var(--text-muted)" id="block-modal-text"></p>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="block-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-block-confirm">Confirm</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ════════════════════════════════════════════════════════
     CONFIG + SCRIPTS
════════════════════════════════════════════════════════ -->
<script>
    window.NAMAK_CONFIG = {
        userId:   <?= (int) $myId ?>,
        username: <?= json_encode($me['username'] ?? '') ?>,
        api:      '/api/v1',
    };
</script>

<script src="/assets/js/modules/storage.js"></script>
<script src="/assets/js/modules/api.js"></script>
<script src="/assets/js/modules/ui.js"></script>

<script>
    (function () {
        'use strict';

        const API  = '/api/v1';
        const cfg  = window.NAMAK_CONFIG;
        const STORE = window.localStorage;

        // ── State ────────────────────────────────────────────────────────────────
        let allContacts    = [];   // full contact list
        let filteredList   = [];   // after search/sort
        let activeTab      = document.body.dataset.activeTab || 'contacts';
        let selectedUserId = null; // for add-contact confirm
        let detailUserId   = null; // currently open detail panel
        let searchDebounce = null;

        // ── Toast ────────────────────────────────────────────────────────────────
        function toast(msg, type = 'info', duration = 3000) {
            const c = document.getElementById('toast-container');
            const t = document.createElement('div');
            t.className = `toast toast--${type}`;
            t.textContent = msg;
            t.setAttribute('role', 'status');
            c.appendChild(t);
            requestAnimationFrame(() => t.classList.add('toast--in'));
            if (duration > 0) setTimeout(() => {
                t.classList.remove('toast--in');
                setTimeout(() => t.remove(), 300);
            }, duration);
        }

        // ── Modal helpers ────────────────────────────────────────────────────────
        function openModal(id)  { document.getElementById(id).hidden = false; }
        function closeModal(id) { document.getElementById(id).hidden = true;  }

        document.querySelectorAll('[data-close]').forEach(btn =>
            btn.addEventListener('click', () => closeModal(btn.dataset.close))
        );
        document.querySelectorAll('.modal-overlay').forEach(o =>
            o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); })
        );
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => closeModal(m.id));
                closeDetailPanel();
            }
        });

        // ── API helpers ──────────────────────────────────────────────────────────
        async function getAPI(endpoint) {
            const res = await fetch(`${API}${endpoint}`, {
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            return res.json();
        }

        async function postAPI(endpoint, body = {}) {
            const res = await fetch(`${API}${endpoint}`, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            return res.json();
        }

        async function deleteAPI(endpoint, body = {}) {
            const res = await fetch(`${API}${endpoint}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            return res.json();
        }

        // ── XSS-safe escape ──────────────────────────────────────────────────────
        function esc(str) {
            return String(str ?? '')
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Avatar HTML ──────────────────────────────────────────────────────────
        function avatarHtml(user, size = 'md') {
            const initials = ((user.first_name?.[0] ?? '') + (user.last_name?.[0] ?? '')).toUpperCase() || '?';
            if (user.avatar) {
                return `<img src="${API}/media/thumb/${esc(user.id)}?type=avatar"
                         alt="${esc(user.first_name)}"
                         class="avatar avatar--${size}"
                         loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="avatar avatar--${size} avatar--fallback" style="display:none">${esc(initials)}</div>`;
            }
            return `<div class="avatar avatar--${size} avatar--fallback">${esc(initials)}</div>`;
        }

        // ── Online badge ─────────────────────────────────────────────────────────
        function onlineBadge(user) {
            return user.is_online
                ? '<span class="online-dot" aria-label="Online" title="Online"></span>'
                : '';
        }

        // ════════════════════════════════════════════
        // LOAD CONTACTS
        // ════════════════════════════════════════════
        async function loadContacts() {
            try {
                const data = await getAPI('/users/contacts');
                if (!data.success) { showContactsError(); return; }

                allContacts = data.contacts || [];
                renderContactBadge(allContacts.length);
                renderContacts(allContacts);
            } catch {
                showContactsError();
            }
        }

        function showContactsError() {
            document.getElementById('contacts-alpha-list').innerHTML =
                '<p class="text-muted" style="padding:24px;font-size:13px">Could not load contacts.</p>';
        }

        // ════════════════════════════════════════════
        // RENDER CONTACTS (alphabetical groups)
        // ════════════════════════════════════════════
        function renderContacts(list) {
            const container = document.getElementById('contacts-alpha-list');
            const emptyEl   = document.getElementById('empty-contacts');
            const jumpNav   = document.getElementById('alpha-jump');

            // Clear skeletons
            container.innerHTML = '';

            if (!list.length) {
                emptyEl.hidden  = false;
                jumpNav.hidden  = true;
                document.getElementById('contacts-count').textContent = '';
                return;
            }

            emptyEl.hidden  = false; // keep hidden
            emptyEl.hidden  = true;
            jumpNav.hidden  = false;
            document.getElementById('contacts-count').textContent =
                `(${list.length})`;

            // Sort
            const sort   = document.getElementById('contacts-sort').value;
            const sorted = sortContacts([...list], sort);

            // Group alphabetically
            const groups = {};
            sorted.forEach(c => {
                const first = (c.first_name?.[0] ?? '#').toUpperCase();
                const key   = /^[A-Z]$/.test(first) ? first : '#';
                if (!groups[key]) groups[key] = [];
                groups[key].push(c);
            });

            const letters = Object.keys(groups).sort((a, b) =>
                a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b)
            );

            const fragment = document.createDocumentFragment();

            letters.forEach(letter => {
                // Letter heading
                const heading = document.createElement('div');
                heading.className = 'contacts-group';
                heading.id = 'group-' + letter;
                heading.innerHTML =
                    `<h3 class="contacts-group-letter" aria-label="Contacts starting with ${letter}">${esc(letter)}</h3>`;

                // Highlight alpha-jump btn
                const jumpBtn = document.querySelector(`.alpha-jump__btn[data-letter="${letter}"]`);
                if (jumpBtn) jumpBtn.classList.add('alpha-jump__btn--active');

                // Items
                const ul = document.createElement('div');
                ul.className = 'contact-list';
                ul.setAttribute('role', 'list');

                groups[letter].forEach(contact => {
                    ul.appendChild(buildContactItem(contact));
                });

                heading.appendChild(ul);
                fragment.appendChild(heading);
            });

            container.appendChild(fragment);
        }

        function buildContactItem(contact) {
            const item = document.createElement('div');
            item.className = 'contact-item';
            item.setAttribute('role', 'listitem');
            item.dataset.userId = contact.id;
            item.tabIndex = 0;
            item.setAttribute('aria-label',
                `${contact.first_name} ${contact.last_name ?? ''} ${contact.username ? '@' + contact.username : ''}`);

            item.innerHTML = `
            <div class="contact-item__avatar-wrap">
                ${avatarHtml(contact, 'md')}
                ${onlineBadge(contact)}
            </div>
            <div class="contact-item__info">
                <p class="contact-item__name">
                    ${esc(contact.first_name)} ${esc(contact.last_name ?? '')}
                </p>
                <p class="contact-item__sub">
                    ${contact.username ? '@' + esc(contact.username) : esc(contact.phone ?? '')}
                </p>
            </div>
            <div class="contact-item__actions" aria-label="Quick actions">
                <button class="icon-btn contact-item__msg"
                        data-id="${contact.id}"
                        aria-label="Message ${esc(contact.first_name)}"
                        title="Message">
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
                        <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
                    </svg>
                </button>
                <button class="icon-btn contact-item__more"
                        data-id="${contact.id}"
                        aria-label="More options"
                        aria-haspopup="true"
                        title="More">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="12" cy="5" r="1.5"/>
                        <circle cx="12" cy="12" r="1.5"/>
                        <circle cx="12" cy="19" r="1.5"/>
                    </svg>
                </button>
            </div>
        `;

            // Click item → open detail panel
            item.addEventListener('click', e => {
                if (e.target.closest('.contact-item__actions')) return;
                openDetailPanel(contact);
            });
            item.addEventListener('keydown', e => {
                if (e.key === 'Enter') openDetailPanel(contact);
            });

            // Message button
            item.querySelector('.contact-item__msg').addEventListener('click', e => {
                e.stopPropagation();
                startChat(contact.id, 'private');
            });

            // More button → context menu
            item.querySelector('.contact-item__more').addEventListener('click', e => {
                e.stopPropagation();
                showContactContextMenu(e, contact);
            });

            return item;
        }

        // ── Sort helper ──────────────────────────────────────────────────────────
        function sortContacts(list, sort) {
            switch (sort) {
                case 'name-asc':
                    return list.sort((a, b) =>
                        (a.first_name + (a.last_name ?? '')).localeCompare(b.first_name + (b.last_name ?? '')));
                case 'name-desc':
                    return list.sort((a, b) =>
                        (b.first_name + (b.last_name ?? '')).localeCompare(a.first_name + (a.last_name ?? '')));
                case 'online':
                    return list.sort((a, b) => (b.is_online ? 1 : 0) - (a.is_online ? 1 : 0));
                case 'recent':
                    return list.sort((a, b) =>
                        new Date(b.contact_added_at ?? 0) - new Date(a.contact_added_at ?? 0));
                default:
                    return list;
            }
        }

        // ── Badge ────────────────────────────────────────────────────────────────
        function renderContactBadge(count) {
            const badge = document.getElementById('badge-contacts');
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
        }

        // ════════════════════════════════════════════
        // CONTACT DETAIL PANEL
        // ════════════════════════════════════════════
        function openDetailPanel(contact) {
            detailUserId = contact.id;

            // Avatar
            const avatarEl = document.getElementById('detail-avatar-el');
            avatarEl.outerHTML = `<div id="detail-avatar-el">${avatarHtml(contact, 'xl')}</div>`;

            // Info
            document.getElementById('detail-name').textContent =
                `${contact.first_name} ${contact.last_name ?? ''}`.trim();
            document.getElementById('detail-username').textContent =
                contact.username ? '@' + contact.username : '';
            document.getElementById('detail-status').textContent =
                contact.is_online ? '● Online' : 'last seen recently';

            // Phone
            if (contact.phone) {
                document.getElementById('detail-phone').textContent = contact.phone;
                document.getElementById('detail-row-phone').hidden = false;
            } else {
                document.getElementById('detail-row-phone').hidden = true;
            }

            // Bio
            if (contact.bio) {
                document.getElementById('detail-bio').textContent = contact.bio;
                document.getElementById('detail-row-bio').hidden = false;
            } else {
                document.getElementById('detail-row-bio').hidden = true;
            }

            // Member since
            document.getElementById('detail-since').textContent =
                contact.created_at
                    ? new Date(contact.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                    : '—';

            // Local note
            const note = STORE.getItem('contact_note_' + contact.id) || '';
            document.getElementById('contact-note').value = note;

            // Show panel
            const panel   = document.getElementById('contact-detail');
            const overlay = document.getElementById('contact-detail-overlay');
            panel.hidden   = false;
            overlay.hidden = false;
            document.getElementById('btn-detail-close').focus();
        }

        function closeDetailPanel() {
            document.getElementById('contact-detail').hidden = true;
            document.getElementById('contact-detail-overlay').hidden = true;
            detailUserId = null;
        }

        document.getElementById('btn-detail-close').addEventListener('click', closeDetailPanel);
        document.getElementById('contact-detail-overlay').addEventListener('click', closeDetailPanel);

        // Detail action buttons
        document.getElementById('detail-btn-message').addEventListener('click', () => {
            if (detailUserId) startChat(detailUserId, 'private');
        });
        document.getElementById('detail-btn-secret').addEventListener('click', () => {
            if (detailUserId) startChat(detailUserId, 'secret');
        });
        document.getElementById('detail-btn-call').addEventListener('click', () => {
            toast('Voice calls coming soon.', 'info');
        });
        document.getElementById('detail-btn-profile').addEventListener('click', () => {
            if (detailUserId)
                window.location.href = '/app/profile?id=' + detailUserId;
        });

        // Save note
        document.getElementById('btn-save-note').addEventListener('click', () => {
            if (!detailUserId) return;
            const note = document.getElementById('contact-note').value.trim();
            STORE.setItem('contact_note_' + detailUserId, note);
            toast('✓ Note saved.', 'success');
        });

        // Remove from detail
        document.getElementById('detail-btn-remove').addEventListener('click', () => {
            if (!detailUserId) return;
            const contact = allContacts.find(c => c.id === detailUserId);
            if (!contact) return;
            confirmRemove(contact);
        });

        // Block from detail
        document.getElementById('detail-btn-block').addEventListener('click', () => {
            if (!detailUserId) return;
            const contact = allContacts.find(c => c.id === detailUserId);
            if (!contact) return;
            confirmBlock(contact, false);
        });

        // ════════════════════════════════════════════
        // CONTACT CONTEXT MENU
        // ════════════════════════════════════════════
        let ctxMenu = null;

        function showContactContextMenu(e, contact) {
            removeCtxMenu();

            const menu = document.createElement('div');
            menu.className = 'context-menu';
            menu.setAttribute('role', 'menu');
            menu.innerHTML = `
            <button class="context-menu__item" role="menuitem" data-action="message">💬 Message</button>
            <button class="context-menu__item" role="menuitem" data-action="secret">🔐 Secret Chat</button>
            <button class="context-menu__item" role="menuitem" data-action="profile">👤 View Profile</button>
            <div class="context-menu__divider"></div>
            <button class="context-menu__item context-menu__item--danger" role="menuitem" data-action="remove">🗑 Remove Contact</button>
            <button class="context-menu__item context-menu__item--danger" role="menuitem" data-action="block">🚫 Block</button>
        `;

            // Position near click
            const x = Math.min(e.clientX, window.innerWidth  - 200);
            const y = Math.min(e.clientY, window.innerHeight - 200);
            menu.style.cssText = `position:fixed;left:${x}px;top:${y}px;z-index:9999`;
            document.body.appendChild(menu);
            ctxMenu = menu;

            menu.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', () => {
                    removeCtxMenu();
                    switch (btn.dataset.action) {
                        case 'message': startChat(contact.id, 'private'); break;
                        case 'secret':  startChat(contact.id, 'secret');  break;
                        case 'profile': window.location.href = '/app/profile?id=' + contact.id; break;
                        case 'remove':  confirmRemove(contact); break;
                        case 'block':   confirmBlock(contact, false); break;
                    }
                });
            });

            setTimeout(() => document.addEventListener('click', removeCtxMenu, { once: true }), 0);
        }

        function removeCtxMenu() {
            if (ctxMenu) { ctxMenu.remove(); ctxMenu = null; }
        }

        // ════════════════════════════════════════════
        // REMOVE CONTACT
        // ════════════════════════════════════════════
        let pendingRemoveId = null;

        function confirmRemove(contact) {
            pendingRemoveId = contact.id;
            document.getElementById('remove-modal-text').textContent =
                `Remove ${contact.first_name} ${contact.last_name ?? ''} from your contacts?`;
            openModal('remove-modal');
        }

        document.getElementById('btn-remove-confirm').addEventListener('click', async () => {
            if (!pendingRemoveId) return;
            const btn = document.getElementById('btn-remove-confirm');
            btn.disabled = true;
            btn.textContent = 'Removing…';

            try {
                const data = await deleteAPI('/users/contacts/remove', { user_id: pendingRemoveId });
                if (data.success) {
                    allContacts = allContacts.filter(c => c.id !== pendingRemoveId);
                    renderContacts(filterContacts(allContacts));
                    renderContactBadge(allContacts.length);
                    closeDetailPanel();
                    toast('✓ Contact removed.', 'success');
                } else {
                    toast(data.message || 'Failed to remove contact.', 'error');
                }
            } catch {
                toast('Network error.', 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = 'Remove';
                pendingRemoveId = null;
                closeModal('remove-modal');
            }
        });

        // ════════════════════════════════════════════
        // BLOCK / UNBLOCK
        // ════════════════════════════════════════════
        let pendingBlockId  = null;
        let pendingIsUnblock = false;

        function confirmBlock(contact, isUnblock) {
            pendingBlockId   = contact.id;
            pendingIsUnblock = isUnblock;

            document.getElementById('block-modal-title').textContent =
                isUnblock ? 'Unblock User' : 'Block User';
            document.getElementById('block-modal-text').textContent = isUnblock
                ? `Unblock ${contact.first_name}? They will be able to message you again.`
                : `Block ${contact.first_name}? They won't be able to message you or see your profile.`;
            openModal('block-modal');
        }

        document.getElementById('btn-block-confirm').addEventListener('click', async () => {
            if (!pendingBlockId) return;
            const btn      = document.getElementById('btn-block-confirm');
            const endpoint = pendingIsUnblock ? '/users/unblock' : '/users/block';
            btn.disabled   = true;
            btn.textContent = '…';

            try {
                const data = await postAPI(endpoint, { user_id: pendingBlockId });
                if (data.success) {
                    toast(pendingIsUnblock ? '✓ User unblocked.' : '✓ User blocked.', 'success');
                    if (!pendingIsUnblock) {
                        // Remove from contacts list
                        allContacts = allContacts.filter(c => c.id !== pendingBlockId);
                        renderContacts(filterContacts(allContacts));
                        renderContactBadge(allContacts.length);
                        closeDetailPanel();
                    }
                    // Refresh blocked list if on that tab
                    if (activeTab === 'blocked') loadBlocked();
                } else {
                    toast(data.message || 'Failed.', 'error');
                }
            } catch {
                toast('Network error.', 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = 'Confirm';
                pendingBlockId  = null;
                closeModal('block-modal');
            }
        });

        // ════════════════════════════════════════════
        // LOAD BLOCKED USERS
        // ════════════════════════════════════════════
        async function loadBlocked() {
            const list    = document.getElementById('blocked-list');
            const emptyEl = document.getElementById('empty-blocked');

            list.innerHTML = '';
            try {
                const data = await getAPI('/users/blocked');
                if (!data.success) { list.innerHTML = '<p class="text-muted" style="padding:16px;font-size:13px">Could not load blocked users.</p>'; return; }

                const blocked = data.blocked_users || [];
                const badge   = document.getElementById('badge-blocked');
                if (blocked.length) {
                    badge.textContent = String(blocked.length);
                    badge.hidden = false;
                } else {
                    badge.hidden = true;
                }

                if (!blocked.length) {
                    emptyEl.hidden = false;
                    return;
                }
                emptyEl.hidden = true;

                blocked.forEach(user => {
                    const item = document.createElement('div');
                    item.className = 'contact-item';
                    item.setAttribute('role', 'listitem');
                    item.innerHTML = `
                    <div class="contact-item__avatar-wrap">
                        ${avatarHtml(user, 'md')}
                    </div>
                    <div class="contact-item__info">
                        <p class="contact-item__name">${esc(user.first_name)} ${esc(user.last_name ?? '')}</p>
                        <p class="contact-item__sub text-muted" style="font-size:12px">
                            ${user.username ? '@' + esc(user.username) : ''}
                        </p>
                    </div>
                    <div class="contact-item__actions">
                        <button class="btn btn--ghost btn--xs unblock-btn"
                                data-id="${user.id}"
                                data-name="${esc(user.first_name)}"
                                aria-label="Unblock ${esc(user.first_name)}">
                            Unblock
                        </button>
                    </div>
                `;
                    list.appendChild(item);
                });

                list.querySelectorAll('.unblock-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const uid  = parseInt(btn.dataset.id, 10);
                        const name = btn.dataset.name;
                        confirmBlock({ id: uid, first_name: name }, true);
                    });
                });

            } catch {
                list.innerHTML = '<p class="text-muted" style="padding:16px;font-size:13px">Network error.</p>';
            }
        }

        // ════════════════════════════════════════════
        // TAB SWITCHING
        // ════════════════════════════════════════════
        document.querySelectorAll('.chat-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.chat-tab').forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');

                activeTab = tab.dataset.tab;
                document.body.dataset.activeTab = activeTab;

                // Update URL without reload
                const url = new URL(window.location.href);
                url.searchParams.set('tab', activeTab);
                history.replaceState(null, '', url.toString());

                // Show/hide panels
                document.getElementById('panel-contacts').hidden = activeTab !== 'contacts';
                document.getElementById('panel-blocked').hidden  = activeTab !== 'blocked';

                // Update title
                document.getElementById('contacts-title').firstChild.textContent =
                    activeTab === 'contacts' ? 'Contacts ' : 'Blocked Users ';

                if (activeTab === 'blocked') loadBlocked();
            });
        });

        // ════════════════════════════════════════════
        // SEARCH CONTACTS (local filter)
        // ════════════════════════════════════════════
        const searchInput = document.getElementById('contacts-search');
        const clearBtn    = document.getElementById('btn-contacts-search-clear');

        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim();
            clearBtn.hidden = !q;
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                if (!q) {
                    // Hide search results, show normal list
                    document.getElementById('search-results-section').hidden = true;
                    renderContacts(allContacts);
                    return;
                }
                // Local filter first (instant)
                const localResults = filterContacts(allContacts, q);
                renderContacts(localResults);

                // Then search globally (new users not in contacts)
                searchGlobalUsers(q);
            }, 300);
        });

        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            clearBtn.hidden   = true;
            document.getElementById('search-results-section').hidden = true;
            renderContacts(allContacts);
            searchInput.focus();
        });

        function filterContacts(list, q = '') {
            if (!q) return list;
            const lower = q.toLowerCase().replace(/^@/, '');
            return list.filter(c =>
                (c.username  ?? '').toLowerCase().includes(lower) ||
                (c.phone     ?? '').includes(lower) ||
                (c.email     ?? '').toLowerCase().includes(lower) ||
                // Also match by display name locally (only within own contacts — not global search)
                (c.first_name + ' ' + (c.last_name ?? '')).toLowerCase().includes(lower)
            );
        }

        async function searchGlobalUsers(q) {
            if (q.length < 2) return;
            const section    = document.getElementById('search-results-section');
            const resultList = document.getElementById('search-results-list');

            try {
                const data = await getAPI(`/users/search?q=${encodeURIComponent(q)}&limit=10`);
                if (!data.success || !data.users?.length) {
                    section.hidden = true;
                    return;
                }

                // Filter out already-contacts from global results
                const contactIds = new Set(allContacts.map(c => c.id));
                const newUsers   = data.users.filter(u => !contactIds.has(u.id) && u.id !== cfg.userId);

                if (!newUsers.length) { section.hidden = true; return; }

                section.hidden   = false;
                resultList.innerHTML = '';

                newUsers.forEach(user => {
                    const item = document.createElement('div');
                    item.className = 'contact-item contact-item--search-result';
                    item.setAttribute('role', 'listitem');
                    item.innerHTML = `
                    <div class="contact-item__avatar-wrap">${avatarHtml(user, 'md')}</div>
                    <div class="contact-item__info">
                        <p class="contact-item__name">
                            ${esc(user.first_name)} ${esc(user.last_name ?? '')}
                        </p>
                        <p class="contact-item__sub">
                            ${user.username ? '@' + esc(user.username) : esc(user.phone ?? '')}
                        </p>
                    </div>
                    <button class="btn btn--primary btn--xs add-result-btn"
                            data-id="${user.id}"
                            aria-label="Add ${esc(user.first_name)} as contact">
                        + Add
                    </button>
                `;
                    item.querySelector('.add-result-btn').addEventListener('click', async () => {
                        const btn  = item.querySelector('.add-result-btn');
                        btn.disabled = true;
                        btn.textContent = '…';
                        const res = await postAPI('/users/contacts/add', { user_id: user.id });
                        if (res.success) {
                            allContacts.push({ ...user, contact_added_at: new Date().toISOString() });
                            btn.textContent = '✓ Added';
                            btn.className   = 'btn btn--ghost btn--xs';
                            renderContactBadge(allContacts.length);
                            toast(`✓ ${user.first_name} added to contacts.`, 'success');
                        } else {
                            btn.disabled    = false;
                            btn.textContent = '+ Add';
                            toast(res.message || 'Failed.', 'error');
                        }
                    });
                    resultList.appendChild(item);
                });
            } catch {
                section.hidden = true;
            }
        }

        // ════════════════════════════════════════════
        // ADD CONTACT MODAL
        // ════════════════════════════════════════════
        function openAddContactModal() {
            selectedUserId = null;
            document.getElementById('add-contact-query').value    = '';
            document.getElementById('add-contact-results').innerHTML = '';
            document.getElementById('add-contact-selected').hidden = true;
            document.getElementById('add-selected-card').innerHTML = '';
            document.getElementById('btn-add-confirm').disabled   = true;
            document.getElementById('add-query-err').textContent  = '';
            document.getElementById('add-search-hint').textContent = '';
            openModal('add-contact-modal');
            document.getElementById('add-contact-query').focus();
        }

        document.getElementById('btn-add-contact').addEventListener('click', openAddContactModal);
        document.getElementById('btn-add-first-contact')?.addEventListener('click', openAddContactModal);

        // Detect search type
        const queryInput = document.getElementById('add-contact-query');
        queryInput.addEventListener('input', () => {
            const v    = queryInput.value.trim();
            const hint = document.getElementById('add-search-hint');
            if (!v) { hint.textContent = ''; return; }
            if (v.startsWith('@') || /^[a-zA-Z0-9_]+$/.test(v)) hint.textContent = 'Searching by username…';
            else if (v.startsWith('+') || /^[0-9]/.test(v))     hint.textContent = 'Searching by phone…';
            else if (v.includes('@'))                             hint.textContent = 'Searching by email…';
            else                                                  hint.textContent = '';
        });

        // Search button or Enter key
        async function doAddSearch() {
            const q    = queryInput.value.trim();
            const errEl = document.getElementById('add-query-err');
            errEl.textContent = '';

            if (!q || q.length < 2) {
                errEl.textContent = 'Enter at least 2 characters.';
                return;
            }

            const searchBtn = document.getElementById('btn-add-search');
            searchBtn.disabled = true;

            const resultsEl = document.getElementById('add-contact-results');
            resultsEl.innerHTML = '<p class="text-muted" style="font-size:13px;padding:8px">Searching…</p>';

            try {
                const data = await getAPI(`/users/search?q=${encodeURIComponent(q)}&limit=10`);
                resultsEl.innerHTML = '';
                document.getElementById('add-contact-selected').hidden = true;
                document.getElementById('btn-add-confirm').disabled    = true;
                selectedUserId = null;

                if (!data.success || !data.users?.length) {
                    resultsEl.innerHTML =
                        '<p class="text-muted" style="font-size:13px;padding:8px">No users found.</p>';
                    return;
                }

                data.users.forEach(user => {
                    if (user.id === cfg.userId) return; // skip self
                    const alreadyAdded = allContacts.some(c => c.id === user.id);

                    const item = document.createElement('div');
                    item.className = 'add-contact-result-item';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', 'false');
                    item.innerHTML = `
                    <div class="contact-item__avatar-wrap">${avatarHtml(user, 'sm')}</div>
                    <div class="contact-item__info">
                        <p class="contact-item__name">
                            ${esc(user.first_name)} ${esc(user.last_name ?? '')}
                        </p>
                        <p class="contact-item__sub">
                            ${user.username ? '@' + esc(user.username) : esc(user.phone ?? '')}
                        </p>
                    </div>
                    ${alreadyAdded
                        ? '<span class="badge badge--success" style="font-size:11px">✓ Contact</span>'
                        : ''
                    }
                `;

                    if (!alreadyAdded) {
                        item.style.cursor = 'pointer';
                        item.tabIndex     = 0;
                        item.addEventListener('click',   () => selectAddUser(user, item));
                        item.addEventListener('keydown', e => { if (e.key === 'Enter') selectAddUser(user, item); });
                    }

                    resultsEl.appendChild(item);
                });

            } catch {
                resultsEl.innerHTML =
                    '<p class="text-muted" style="font-size:13px;padding:8px">Network error. Try again.</p>';
            } finally {
                searchBtn.disabled = false;
            }
        }

        document.getElementById('btn-add-search').addEventListener('click', doAddSearch);
        queryInput.addEventListener('keydown', e => { if (e.key === 'Enter') doAddSearch(); });

        function selectAddUser(user, item) {
            // Deselect all
            document.querySelectorAll('.add-contact-result-item').forEach(el => {
                el.classList.remove('add-contact-result-item--selected');
                el.setAttribute('aria-selected', 'false');
            });
            item.classList.add('add-contact-result-item--selected');
            item.setAttribute('aria-selected', 'true');

            selectedUserId = user.id;
            document.getElementById('btn-add-confirm').disabled = false;

            // Show selected card
            const card = document.getElementById('add-selected-card');
            card.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px">
                ${avatarHtml(user, 'md')}
                <div>
                    <p style="font-weight:600">${esc(user.first_name)} ${esc(user.last_name ?? '')}</p>
                    <p style="font-size:12px;color:var(--text-muted)">
                        ${user.username ? '@' + esc(user.username) : esc(user.phone ?? '')}
                    </p>
                </div>
            </div>
        `;
            document.getElementById('add-contact-selected').hidden = false;
        }

        document.getElementById('btn-add-confirm').addEventListener('click', async () => {
            if (!selectedUserId) return;
            const btn = document.getElementById('btn-add-confirm');
            btn.disabled    = true;
            btn.textContent = 'Adding…';

            try {
                const data = await postAPI('/users/contacts/add', { user_id: selectedUserId });
                if (data.success) {
                    // Add to local state
                    const user = data.contact ?? { id: selectedUserId };
                    allContacts.push({ ...user, contact_added_at: new Date().toISOString() });
                    renderContacts(allContacts);
                    renderContactBadge(allContacts.length);
                    toast(`✓ ${data.contact?.first_name ?? 'User'} added to contacts.`, 'success');
                    closeModal('add-contact-modal');
                } else {
                    toast(data.message || 'Could not add contact.', 'error');
                }
            } catch {
                toast('Network error.', 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = 'Add Contact';
            }
        });

        // ════════════════════════════════════════════
        // ALPHA QUICK-JUMP
        // ════════════════════════════════════════════
        document.querySelectorAll('.alpha-jump__btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const letter = btn.dataset.letter;
                const group  = document.getElementById('group-' + letter);
                if (group) group.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // ════════════════════════════════════════════
        // SORT
        // ════════════════════════════════════════════
        document.getElementById('contacts-sort').addEventListener('change', () => {
            renderContacts(allContacts);
        });

        // ════════════════════════════════════════════
        // START CHAT helper
        // ════════════════════════════════════════════
        async function startChat(userId, type = 'private') {
            const t = toast('Opening chat…', 'info', 2000);
            try {
                const data = await postAPI('/chats/create', { type, member_ids: [userId] });
                if (data.success) {
                    window.location.href = '/app/chat?chat=' + data.chat.id;
                } else {
                    toast(data.message || 'Could not open chat.', 'error');
                }
            } catch {
                toast('Network error.', 'error');
            }
        }

        // ════════════════════════════════════════════
        // IMPORT (Web Contacts API)
        // ════════════════════════════════════════════
        document.getElementById('btn-import-contacts').addEventListener('click', async () => {
            if (!('contacts' in navigator && 'ContactsManager' in window)) {
                toast('Contact import is not supported on this device/browser.', 'info', 4000);
                return;
            }
            try {
                const props    = ['name', 'tel', 'email'];
                const selected = await navigator.contacts.select(props, { multiple: true });
                if (!selected.length) return;

                const btn = document.getElementById('btn-import-contacts');
                btn.disabled = true;
                const importing = toast(`Importing ${selected.length} contacts…`, 'info', 0);

                let added = 0;
                for (const c of selected) {
                    const phone = c.tel?.[0]  ?? null;
                    const email = c.email?.[0] ?? null;
                    if (!phone && !email) continue;
                    try {
                        const query = phone ?? email;
                        const found = await getAPI(`/users/search?q=${encodeURIComponent(query)}&limit=1`);
                        if (found.success && found.users?.[0]) {
                            const uid = found.users[0].id;
                            if (uid !== cfg.userId && !allContacts.some(x => x.id === uid)) {
                                const res = await postAPI('/users/contacts/add', { user_id: uid });
                                if (res.success) {
                                    allContacts.push({ ...found.users[0], contact_added_at: new Date().toISOString() });
                                    added++;
                                }
                            }
                        }
                    } catch { /* skip individual failures */ }
                }

                importing.remove();
                renderContacts(allContacts);
                renderContactBadge(allContacts.length);
                toast(added ? `✓ ${added} contact(s) imported.` : 'No matching users found.', 'success');
            } catch (err) {
                if (err.name !== 'AbortError') toast('Import failed.', 'error');
            } finally {
                document.getElementById('btn-import-contacts').disabled = false;
            }
        });

        // ════════════════════════════════════════════
        // EXPORT
        // ════════════════════════════════════════════
        document.getElementById('btn-export-contacts').addEventListener('click', e => {
            e.stopPropagation();
            const dd = document.getElementById('export-dropdown');
            dd.hidden = !dd.hidden;
        });
        document.addEventListener('click', () => {
            document.getElementById('export-dropdown').hidden = true;
        });

        document.querySelectorAll('[data-export]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('export-dropdown').hidden = true;
                const fmt = btn.dataset.export;
                if (fmt === 'json') exportJSON();
                if (fmt === 'vcard') exportVCard();
            });
        });

        function exportJSON() {
            const data = allContacts.map(c => ({
                first_name: c.first_name,
                last_name:  c.last_name  ?? '',
                username:   c.username   ?? '',
                phone:      c.phone      ?? '',
                email:      c.email      ?? '',
            }));
            downloadFile(JSON.stringify(data, null, 2), 'contacts.json', 'application/json');
            toast('✓ Contacts exported as JSON.', 'success');
        }

        function exportVCard() {
            const lines = [];
            allContacts.forEach(c => {
                lines.push('BEGIN:VCARD');
                lines.push('VERSION:3.0');
                lines.push(`FN:${c.first_name} ${c.last_name ?? ''}`.trim());
                lines.push(`N:${c.last_name ?? ''};${c.first_name};;;`);
                if (c.phone)    lines.push(`TEL;TYPE=CELL:${c.phone}`);
                if (c.email)    lines.push(`EMAIL:${c.email}`);
                if (c.username) lines.push(`X-NAMAK-USERNAME:@${c.username}`);
                lines.push('END:VCARD');
            });
            downloadFile(lines.join('\r\n'), 'contacts.vcf', 'text/vcard');
            toast('✓ Contacts exported as vCard.', 'success');
        }

        function downloadFile(content, filename, mime) {
            const blob = new Blob([content], { type: mime });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = filename;
            a.click();
            URL.revokeObjectURL(url);
        }

        // ════════════════════════════════════════════
        // INIT
        // ════════════════════════════════════════════
        loadContacts();
        if (activeTab === 'blocked') loadBlocked();

        // Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/assets/js/service-worker.js', { scope: '/' })
                .catch(e => console.warn('[SW]', e));
        }

    })();
</script>

</body>
</html>

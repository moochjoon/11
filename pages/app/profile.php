<?php
declare(strict_types=1);

/**
 * Profile Page
 * Namak Messenger — pages/app/profile.php
 *
 * Two modes (resolved from URL):
 *  /app/profile          → own profile (editable)
 *  /app/profile?id=123   → another user's public profile
 *  /app/profile?username=xxx → same but by username
 *
 * Own profile sections:
 *  - Avatar + name + username + bio
 *  - Edit inline
 *  - Privacy settings
 *  - Linked devices
 *  - Active sessions
 *  - Danger zone (delete account)
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

// ── Resolve target user ───────────────────────────────────────────────────────
$targetId = isset($_GET['id'])       ? (int)    $_GET['id']       : null;
$targetUN = isset($_GET['username']) ? trim($_GET['username'])     : null;

$isOwnProfile = false;
$target       = null;

if (!$targetId && !$targetUN) {
    // Own profile
    $isOwnProfile = true;
    $target       = $me;
} elseif ($targetId) {
    $target = $userRepo->findById($targetId);
    $isOwnProfile = ($targetId === (int) $myId);
} elseif ($targetUN) {
    $target = $userRepo->findByUsername(strtolower($targetUN));
    $isOwnProfile = ($target && (int)($target['id'] ?? 0) === (int) $myId);
}

if (!$target || !($target['is_active'] ?? true)) {
    http_response_code(404);
    header('Location: /404');
    exit;
}

// ── Block check for non-own profiles ─────────────────────────────────────────
if (!$isOwnProfile && $userRepo->isBlocked((int) $target['id'], (int) $myId)) {
    http_response_code(404);
    header('Location: /404');
    exit;
}

$lang    = $_COOKIE['lang'] ?? 'en';
$dir     = $lang === 'fa' ? 'rtl' : 'ltr';
$appName = $_ENV['APP_NAME'] ?? 'Namak';

// Pre-load relationship flags
$isContact  = !$isOwnProfile && $userRepo->isContact((int) $myId, (int) $target['id']);
$isBlocking = !$isOwnProfile && $userRepo->isBlocked((int) $myId, (int) $target['id']);

// Page title
$pageTitle = $isOwnProfile
    ? 'My Profile — ' . $appName
    : htmlspecialchars(trim($target['first_name'] . ' ' . ($target['last_name'] ?? ''))) . ' — ' . $appName;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#2196f3">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>

    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/profile.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">

    <script>
        (function(){
            var t = localStorage.getItem('namak_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="app-body profile-page"
      data-user-id="<?= (int) $myId ?>"
      data-target-id="<?= (int) $target['id'] ?>"
      data-own="<?= $isOwnProfile ? '1' : '0' ?>">

<!-- ══════════════════════════════════════════════════════════════════════════
     APP SHELL  (same sidebar as chat.php — reused layout)
══════════════════════════════════════════════════════════════════════════ -->
<div class="app-shell" id="app-shell">

    <!-- ════════════════════════════════════════
         SIDEBAR  (identical to chat.php)
    ════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar" role="complementary" aria-label="Navigation">
        <header class="sidebar__header">
            <a href="/app/chat" class="icon-btn" aria-label="Back to chats">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 5l-7 7 7 7"/>
                </svg>
            </a>
            <span class="sidebar__header-title">
                <?= $isOwnProfile ? 'My Profile' : 'Profile' ?>
            </span>
            <?php if ($isOwnProfile): ?>
                <button class="icon-btn" id="btn-edit-toggle" aria-label="Edit profile" title="Edit profile">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
            <?php endif; ?>
        </header>
        <nav class="sidebar__footer" role="navigation" aria-label="App navigation">
            <a href="/app/chat"     class="sidebar__nav-item" aria-label="Chats">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <span>Chats</span>
            </a>
            <a href="/app/contacts" class="sidebar__nav-item" aria-label="Contacts">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                <span>Contacts</span>
            </a>
            <a href="/app/settings" class="sidebar__nav-item" aria-label="Settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                <span>Settings</span>
            </a>
            <a href="/app/profile" class="sidebar__nav-item sidebar__nav-item--profile active" aria-label="My profile">
                <div class="sidebar__my-avatar">
                    <?php if (!empty($me['avatar'])): ?>
                        <img src="/api/v1/media/download/<?= htmlspecialchars($me['avatar']) ?>"
                             alt="" class="avatar avatar--sm">
                    <?php else: ?>
                        <div class="avatar avatar--sm avatar--fallback">
                            <?= mb_strtoupper(mb_substr($me['first_name'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <span><?= htmlspecialchars($me['first_name']) ?></span>
            </a>
        </nav>
    </aside>

    <!-- ════════════════════════════════════════
         MAIN CONTENT
    ════════════════════════════════════════ -->
    <main class="profile-main" role="main" id="profile-main">

        <!-- ── HERO / COVER ──────────────────────────────────────────────── -->
        <div class="profile-hero" id="profile-hero">

            <!-- Cover gradient (no actual cover photo — privacy) -->
            <div class="profile-hero__cover" id="profile-cover" aria-hidden="true"></div>

            <!-- Avatar -->
            <div class="profile-hero__avatar-wrap" id="avatar-wrap">
                <?php
                $avatarSrc = null;
                if (!empty($target['avatar'])) {
                    $avatarSrc = '/api/v1/media/thumb/' . (int) $target['id'] . '?type=avatar';
                }
                $initials = mb_strtoupper(
                    mb_substr($target['first_name'], 0, 1) .
                    mb_substr($target['last_name'] ?? '', 0, 1)
                );
                ?>
                <div class="profile-avatar" id="profile-avatar"
                    <?php if ($isOwnProfile): ?>
                        role="button" tabindex="0" aria-label="Change profile photo"
                        id="btn-avatar-change"
                    <?php endif; ?>>
                    <?php if ($avatarSrc): ?>
                        <img src="<?= htmlspecialchars($avatarSrc) ?>"
                             alt="<?= htmlspecialchars($target['first_name']) ?>'s avatar"
                             class="profile-avatar__img"
                             id="avatar-img"
                             loading="lazy">
                    <?php else: ?>
                        <div class="profile-avatar__fallback" id="avatar-fallback"
                             aria-label="<?= htmlspecialchars($initials) ?>">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isOwnProfile): ?>
                        <div class="profile-avatar__overlay" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
                                <circle cx="12" cy="13" r="4"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Online indicator -->
                <?php if (!$isOwnProfile): ?>
                    <span class="profile-avatar__online" id="online-dot"
                          aria-label="Online status" hidden></span>
                <?php endif; ?>
            </div>

            <!-- Name + username -->
            <div class="profile-hero__info">
                <h1 class="profile-hero__name" id="profile-name">
                    <?= htmlspecialchars(trim($target['first_name'] . ' ' . ($target['last_name'] ?? ''))) ?>
                </h1>
                <?php if (!empty($target['username'])): ?>
                    <p class="profile-hero__username" id="profile-username">
                        @<?= htmlspecialchars($target['username']) ?>
                    </p>
                <?php endif; ?>

                <!-- Last seen (respects privacy) -->
                <p class="profile-hero__lastseen" id="profile-lastseen" aria-live="polite">
                    <?php if ($isOwnProfile): ?>
                        <span class="badge badge--online">● Online</span>
                    <?php else: ?>
                        <span class="text-muted">last seen recently</span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Action buttons (non-own profile) -->
            <?php if (!$isOwnProfile): ?>
                <div class="profile-hero__actions" role="group" aria-label="User actions">
                    <button class="btn btn--primary" id="btn-send-message" data-id="<?= (int) $target['id'] ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
                        </svg>
                        Message
                    </button>

                    <?php if ($isContact): ?>
                        <button class="btn btn--ghost" id="btn-toggle-contact" data-action="remove">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            Remove Contact
                        </button>
                    <?php else: ?>
                        <button class="btn btn--ghost" id="btn-toggle-contact" data-action="add">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                                <path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/>
                            </svg>
                            Add Contact
                        </button>
                    <?php endif; ?>

                    <button class="icon-btn" id="btn-more-user" aria-label="More options" aria-haspopup="true">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                        </svg>
                    </button>
                    <div class="dropdown" id="user-more-dropdown" role="menu" hidden>
                        <button class="dropdown__item" role="menuitem" data-action="secret-chat">🔐 Secret Chat</button>
                        <button class="dropdown__item" role="menuitem" data-action="share">📤 Share Contact</button>
                        <button class="dropdown__item" role="menuitem" data-action="mute">🔕 Mute</button>
                        <div class="dropdown__divider"></div>
                        <button class="dropdown__item dropdown__item--danger" role="menuitem" id="btn-block-user"
                                data-blocked="<?= $isBlocking ? '1' : '0' ?>">
                            <?= $isBlocking ? '✅ Unblock User' : '🚫 Block User' ?>
                        </button>
                        <button class="dropdown__item dropdown__item--danger" role="menuitem" id="btn-report-user">
                            ⚠️ Report
                        </button>
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- /profile-hero -->

        <!-- ── PROFILE INFO CARD ──────────────────────────────────────────── -->
        <div class="profile-card" id="profile-info-card">

            <!-- Bio -->
            <?php if (!empty($target['bio']) || $isOwnProfile): ?>
                <div class="profile-row" id="row-bio">
                    <div class="profile-row__icon" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                    </div>
                    <div class="profile-row__content">
                        <p class="profile-row__label">Bio</p>
                        <p class="profile-row__value" id="bio-text">
                            <?= !empty($target['bio'])
                                ? htmlspecialchars($target['bio'])
                                : '<span class="text-muted">No bio yet.</span>' ?>
                        </p>
                    </div>
                    <?php if ($isOwnProfile): ?>
                        <button class="profile-row__edit icon-btn" data-edit="bio" aria-label="Edit bio">✏️</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Phone (privacy-controlled) -->
            <?php
            $showPhone = $isOwnProfile ||
                ($target['privacy_phone'] ?? 'contacts') === 'everyone' ||
                (($target['privacy_phone'] ?? 'contacts') === 'contacts' && $isContact);
            ?>
            <?php if ($showPhone && !empty($target['phone'])): ?>
                <div class="profile-row" id="row-phone">
                    <div class="profile-row__icon" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/></svg>
                    </div>
                    <div class="profile-row__content">
                        <p class="profile-row__label">Phone</p>
                        <p class="profile-row__value">
                            <a href="tel:<?= htmlspecialchars($target['phone']) ?>" class="link">
                                <?= htmlspecialchars($target['phone']) ?>
                            </a>
                        </p>
                    </div>
                    <?php if ($isOwnProfile): ?>
                        <button class="profile-row__edit icon-btn" data-edit="phone" aria-label="Edit phone">✏️</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Email (own only) -->
            <?php if ($isOwnProfile && !empty($me['email'])): ?>
                <div class="profile-row" id="row-email">
                    <div class="profile-row__icon" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                    </div>
                    <div class="profile-row__content">
                        <p class="profile-row__label">Email</p>
                        <p class="profile-row__value"><?= htmlspecialchars($me['email']) ?></p>
                    </div>
                    <button class="profile-row__edit icon-btn" data-edit="email" aria-label="Edit email">✏️</button>
                </div>
            <?php endif; ?>

            <!-- Username -->
            <div class="profile-row" id="row-username">
                <div class="profile-row__icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                </div>
                <div class="profile-row__content">
                    <p class="profile-row__label">Username</p>
                    <p class="profile-row__value">
                        <?= !empty($target['username'])
                            ? '@' . htmlspecialchars($target['username'])
                            : '<span class="text-muted">Not set</span>' ?>
                    </p>
                </div>
                <?php if ($isOwnProfile): ?>
                    <button class="profile-row__edit icon-btn" data-edit="username" aria-label="Edit username">✏️</button>
                <?php endif; ?>
            </div>

            <!-- Member since -->
            <div class="profile-row">
                <div class="profile-row__icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                </div>
                <div class="profile-row__content">
                    <p class="profile-row__label">Member since</p>
                    <p class="profile-row__value">
                        <?= date('F j, Y', strtotime($target['created_at'])) ?>
                    </p>
                </div>
            </div>

        </div><!-- /profile-info-card -->

        <!-- ════════════════════════════════════════
             OWN PROFILE: Extended sections
        ════════════════════════════════════════ -->
        <?php if ($isOwnProfile): ?>

            <!-- ── Privacy Settings ──────────────────────────────────────────── -->
            <section class="profile-section" id="section-privacy">
                <h2 class="profile-section__title">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="profile-section__icon">
                        <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Privacy
                </h2>

                <!-- Last seen -->
                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Last seen &amp; Online</p>
                        <p class="profile-row__sub">Who can see when you were last online</p>
                    </div>
                    <select class="form-select" id="privacy-last-seen"
                            data-key="privacy_last_seen"
                            aria-label="Last seen privacy">
                        <option value="everyone"  <?= ($me['privacy_last_seen'] ?? 'everyone') === 'everyone'  ? 'selected' : '' ?>>Everyone</option>
                        <option value="contacts"  <?= ($me['privacy_last_seen'] ?? 'everyone') === 'contacts'  ? 'selected' : '' ?>>My Contacts</option>
                        <option value="nobody"    <?= ($me['privacy_last_seen'] ?? 'everyone') === 'nobody'    ? 'selected' : '' ?>>Nobody</option>
                    </select>
                </div>

                <!-- Profile photo -->
                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Profile Photo</p>
                        <p class="profile-row__sub">Who can see your profile photo</p>
                    </div>
                    <select class="form-select" id="privacy-avatar"
                            data-key="privacy_avatar"
                            aria-label="Profile photo privacy">
                        <option value="everyone" <?= ($me['privacy_avatar'] ?? 'everyone') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                        <option value="contacts" <?= ($me['privacy_avatar'] ?? 'everyone') === 'contacts' ? 'selected' : '' ?>>My Contacts</option>
                        <option value="nobody"   <?= ($me['privacy_avatar'] ?? 'everyone') === 'nobody'   ? 'selected' : '' ?>>Nobody</option>
                    </select>
                </div>

                <!-- Phone number -->
                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Phone Number</p>
                        <p class="profile-row__sub">Who can see your phone number</p>
                    </div>
                    <select class="form-select" id="privacy-phone"
                            data-key="privacy_phone"
                            aria-label="Phone number privacy">
                        <option value="everyone" <?= ($me['privacy_phone'] ?? 'contacts') === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                        <option value="contacts" <?= ($me['privacy_phone'] ?? 'contacts') === 'contacts' ? 'selected' : '' ?>>My Contacts</option>
                        <option value="nobody"   <?= ($me['privacy_phone'] ?? 'contacts') === 'nobody'   ? 'selected' : '' ?>>Nobody</option>
                    </select>
                </div>

                <!-- Searchable by -->
                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Searchable by</p>
                        <p class="profile-row__sub">How others can find you (never by name)</p>
                    </div>
                    <div class="privacy-search-checks" role="group" aria-label="Search options">
                        <?php
                        $searchPrivacy = $me['privacy_search'] ?? 'username,phone,email';
                        $searchOptions = explode(',', $searchPrivacy);
                        foreach (['username' => 'Username', 'phone' => 'Phone', 'email' => 'Email'] as $val => $label):
                            ?>
                            <label class="form-check form-check--inline">
                                <input type="checkbox"
                                       class="form-check__input privacy-search-opt"
                                       value="<?= $val ?>"
                                    <?= in_array($val, $searchOptions, true) ? 'checked' : '' ?>
                                       aria-label="<?= $label ?>">
                                <span class="form-check__box"></span>
                                <span class="form-check__label"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Two-Factor Auth -->
                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Two-Step Verification</p>
                        <p class="profile-row__sub">
                            <?= !empty($me['two_factor_enabled']) ? '✅ Enabled' : '⚠️ Disabled' ?>
                        </p>
                    </div>
                    <button class="btn btn--ghost btn--sm" id="btn-2fa">
                        <?= !empty($me['two_factor_enabled']) ? 'Manage' : 'Enable' ?>
                    </button>
                </div>

                <button class="btn btn--primary btn--sm" id="btn-save-privacy" style="margin-top:8px">
                    Save Privacy
                </button>
            </section>

            <!-- ── Active Sessions ───────────────────────────────────────────── -->
            <section class="profile-section" id="section-sessions">
                <h2 class="profile-section__title">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="profile-section__icon">
                        <path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm5.771 7H5V5h10v7H8.771z" clip-rule="evenodd"/>
                    </svg>
                    Active Sessions
                </h2>
                <div class="sessions-list" id="sessions-list" role="list">
                    <div class="skeleton-session" aria-hidden="true">
                        <div class="skeleton skeleton--line skeleton--short"></div>
                        <div class="skeleton skeleton--line skeleton--long"></div>
                    </div>
                </div>
                <button class="btn btn--danger btn--sm" id="btn-revoke-all-sessions" style="margin-top:8px">
                    Terminate All Other Sessions
                </button>
            </section>

            <!-- ── E2E Encryption Keys ───────────────────────────────────────── -->
            <section class="profile-section" id="section-keys">
                <h2 class="profile-section__title">
                    🔐 Encryption Keys
                </h2>
                <p class="text-muted" style="font-size:13px;margin-bottom:12px;line-height:1.6">
                    Your public key is shared with contacts for end-to-end encryption.
                    Your private key is stored only in this browser.
                </p>

                <div class="profile-row">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Public Key</p>
                        <p class="profile-row__value"
                           style="font-family:monospace;font-size:11px;word-break:break-all;
                              max-height:60px;overflow:hidden;cursor:pointer"
                           id="public-key-display"
                           title="Click to copy"
                           role="button" tabindex="0"
                           aria-label="Copy public key">
                            <?= htmlspecialchars(mb_substr($me['public_key'] ?? 'Not generated', 0, 80)) ?>…
                        </p>
                    </div>
                    <button class="icon-btn" id="btn-copy-pubkey" aria-label="Copy public key" title="Copy">📋</button>
                </div>

                <div class="profile-row">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Private Key</p>
                        <p class="profile-row__value text-muted" style="font-size:13px">
                            Stored locally in this browser only.<br>
                            <small>If you lose it, secret chats cannot be recovered.</small>
                        </p>
                    </div>
                    <button class="btn btn--ghost btn--sm" id="btn-export-privkey">Export</button>
                </div>

                <button class="btn btn--ghost btn--sm" id="btn-regen-keys" style="margin-top:8px">
                    🔄 Regenerate Keys
                </button>
                <p class="text-muted" style="font-size:11px;margin-top:6px">
                    ⚠️ Regenerating keys will invalidate all existing secret chats.
                </p>
            </section>

            <!-- ── Danger Zone ───────────────────────────────────────────────── -->
            <section class="profile-section profile-section--danger" id="section-danger">
                <h2 class="profile-section__title profile-section__title--danger">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="profile-section__icon">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    Danger Zone
                </h2>

                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Log Out</p>
                        <p class="profile-row__sub">Sign out from this device</p>
                    </div>
                    <button class="btn btn--ghost btn--sm" id="btn-logout">Log Out</button>
                </div>

                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label">Log Out All Devices</p>
                        <p class="profile-row__sub">Terminate all active sessions everywhere</p>
                    </div>
                    <button class="btn btn--danger btn--sm" id="btn-logout-all">Log Out All</button>
                </div>

                <div class="profile-row profile-row--setting">
                    <div class="profile-row__content">
                        <p class="profile-row__label text-danger">Delete Account</p>
                        <p class="profile-row__sub">
                            Permanently delete your account and all data.
                            This action <strong>cannot be undone</strong>.
                        </p>
                    </div>
                    <button class="btn btn--danger btn--sm" id="btn-delete-account">Delete</button>
                </div>
            </section>

        <?php endif; // isOwnProfile ?>

    </main>

</div><!-- /app-shell -->

<!-- ════════════════════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════════════════════ -->

<!-- ── Edit field modal (inline editing) ── -->
<div class="modal-overlay" id="edit-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title" id="edit-modal-title">Edit</h2>
            <button class="icon-btn modal__close" data-close="edit-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <div class="form-group" id="edit-modal-group">
                <label class="form-label" id="edit-modal-label" for="edit-modal-input"></label>
                <div class="form-input-wrap">
                    <textarea id="edit-modal-input" class="form-input form-input--textarea"
                              rows="3" maxlength="255"
                              aria-describedby="edit-modal-err"></textarea>
                </div>
                <span class="form-hint" id="edit-modal-hint"></span>
                <span class="form-error" id="edit-modal-err" role="alert" aria-live="polite"></span>
            </div>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="edit-modal">Cancel</button>
            <button class="btn btn--primary" id="btn-edit-save">Save</button>
        </div>
    </div>
</div>

<!-- ── Avatar change modal ── -->
<div class="modal-overlay" id="avatar-modal" role="dialog" aria-modal="true" aria-label="Change profile photo" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">Profile Photo</h2>
            <button class="icon-btn modal__close" data-close="avatar-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <div class="avatar-modal-actions">
                <button class="avatar-modal-option" id="btn-avatar-upload">
                    <span>📷</span>
                    <span>Upload Photo</span>
                </button>
                <button class="avatar-modal-option" id="btn-avatar-remove"
                    <?= empty($me['avatar']) ? 'disabled' : '' ?>>
                    <span>🗑</span>
                    <span>Remove Photo</span>
                </button>
            </div>
            <input type="file" id="avatar-file-input"
                   accept="image/jpeg,image/png,image/webp,image/gif"
                   style="display:none" aria-hidden="true">
            <!-- Crop preview -->
            <div id="avatar-crop-wrap" hidden style="margin-top:16px;text-align:center">
                <canvas id="avatar-crop-canvas" width="200" height="200"
                        style="border-radius:50%;border:2px solid var(--border);max-width:200px"></canvas>
                <p style="font-size:12px;color:var(--text-muted);margin-top:8px">
                    Preview — drag to reposition
                </p>
            </div>
        </div>
        <div class="modal__footer" id="avatar-modal-footer">
            <button class="btn btn--ghost" data-close="avatar-modal">Cancel</button>
            <button class="btn btn--primary" id="btn-avatar-save" hidden>Save Photo</button>
        </div>
    </div>
</div>

<!-- ── Delete account confirmation ── -->
<div class="modal-overlay" id="delete-account-modal" role="dialog" aria-modal="true" aria-label="Delete account" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title text-danger">⚠️ Delete Account</h2>
            <button class="icon-btn modal__close" data-close="delete-account-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;line-height:1.6">
                This will permanently delete your account, all messages, files,
                and contacts. <strong>This cannot be undone.</strong>
            </p>
            <div class="form-group">
                <label class="form-label" for="delete-confirm-input">
                    Type your username to confirm:
                    <strong><?= htmlspecialchars($me['username'] ?? '') ?></strong>
                </label>
                <input type="text" id="delete-confirm-input" class="form-input"
                       placeholder="<?= htmlspecialchars($me['username'] ?? '') ?>"
                       autocomplete="off" autocapitalize="none" spellcheck="false">
            </div>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="delete-account-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-delete-account-confirm" disabled>
                Delete Forever
            </button>
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

<!-- ── Report modal ── -->
<div class="modal-overlay" id="report-modal" role="dialog" aria-modal="true" aria-label="Report user" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">⚠️ Report User</h2>
            <button class="icon-btn modal__close" data-close="report-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
                Select a reason:
            </p>
            <div class="report-reasons" role="radiogroup" aria-label="Report reason">
                <?php foreach ([
                                   'spam'        => 'Spam or scam',
                                   'abuse'       => 'Harassment or abuse',
                                   'fake'        => 'Fake account',
                                   'violence'    => 'Violence or harmful content',
                                   'other'       => 'Other',
                               ] as $val => $label): ?>
                    <label class="form-check">
                        <input type="radio" class="form-check__input" name="report-reason"
                               value="<?= $val ?>">
                        <span class="form-check__box form-check__box--radio"></span>
                        <span class="form-check__label"><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <textarea id="report-comment" class="form-input form-input--textarea"
                      rows="3" maxlength="500" placeholder="Additional details (optional)"
                      style="margin-top:12px"></textarea>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="report-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-report-submit">Send Report</button>
        </div>
    </div>
</div>

<!-- Regerate keys confirm -->
<div class="modal-overlay" id="regen-keys-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">🔄 Regenerate Keys</h2>
            <button class="icon-btn modal__close" data-close="regen-keys-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:14px;color:var(--text-muted);line-height:1.6">
                Regenerating your encryption keys will <strong>invalidate all existing secret chats</strong>.
                You will need to restart secret conversations with all contacts.
                Are you sure?
            </p>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="regen-keys-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-regen-confirm">Regenerate</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ══════════════════════════════════════════════════════════════════════════
     CONFIG + SCRIPTS
══════════════════════════════════════════════════════════════════════════ -->
<script>
    window.NAMAK_CONFIG = {
        userId:       <?= (int) $myId ?>,
        targetId:     <?= (int) $target['id'] ?>,
        isOwnProfile: <?= $isOwnProfile ? 'true' : 'false' ?>,
        username:     <?= json_encode($me['username']    ?? '') ?>,
        publicKey:    <?= json_encode($me['public_key']  ?? '') ?>,
        api:          '/api/v1',
    };
</script>

<script src="/assets/js/modules/storage.js"></script>
<script src="/assets/js/modules/api.js"></script>
<script src="/assets/js/modules/ui.js"></script>
<script src="/assets/js/modules/auth.js"></script>
<script src="/assets/js/pages/profile.js"></script>

<script>
    (function () {
        'use strict';

        const API = '/api/v1';
        const cfg = window.NAMAK_CONFIG;

        // ── Modal helpers ────────────────────────────────────────────────────────
        function openModal(id)  { document.getElementById(id).hidden = false; }
        function closeModal(id) { document.getElementById(id).hidden = true;  }

        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.dataset.close));
        });
        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape')
                document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => closeModal(m.id));
        });

        // ── Theme ────────────────────────────────────────────────────────────────
        const savedTheme = localStorage.getItem('namak_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

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
            return t;
        }

        // ── PATCH helper ─────────────────────────────────────────────────────────
        async function patchAPI(endpoint, body) {
            const res  = await fetch(`${API}${endpoint}`, {
                method:      'PATCH',
                headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'include',
                body:        JSON.stringify(body),
            });
            return res.json();
        }

        async function postAPI(endpoint, body = {}) {
            const res  = await fetch(`${API}${endpoint}`, {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'include',
                body:        JSON.stringify(body),
            });
            return res.json();
        }

        // ════════════════════════════════════════════
        // OWN PROFILE LOGIC
        // ════════════════════════════════════════════
        if (cfg.isOwnProfile) {

            // ── Inline edit fields ───────────────────────────────────────────────
            const editConfigs = {
                bio:      { label: 'Bio',          hint: 'Max 255 characters.',            maxlength: 255, multiline: true  },
                username: { label: 'Username',     hint: '3–32 chars: letters, numbers, _', maxlength: 32,  multiline: false },
                phone:    { label: 'Phone Number', hint: 'Include country code (+98…)',     maxlength: 20,  multiline: false },
                email:    { label: 'Email',        hint: '',                                maxlength: 255, multiline: false },
                first_name: { label: 'First Name', hint: '',                               maxlength: 64,  multiline: false },
                last_name:  { label: 'Last Name',  hint: '',                               maxlength: 64,  multiline: false },
            };

            let activeEditField = null;

            document.querySelectorAll('[data-edit]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const field = btn.dataset.edit;
                    const conf  = editConfigs[field];
                    if (!conf) return;

                    activeEditField = field;
                    document.getElementById('edit-modal-title').textContent = 'Edit ' + conf.label;
                    document.getElementById('edit-modal-label').textContent = conf.label;
                    document.getElementById('edit-modal-hint').textContent  = conf.hint;
                    document.getElementById('edit-modal-err').textContent   = '';

                    const inp = document.getElementById('edit-modal-input');
                    inp.maxLength = conf.maxlength;
                    inp.rows = conf.multiline ? 4 : 1;

                    // Pre-fill from current DOM
                    const valueEl = btn.closest('.profile-row')?.querySelector('.profile-row__value');
                    let current = valueEl?.textContent.trim().replace(/^@/, '') ?? '';
                    if (current === 'No bio yet.' || current === 'Not set') current = '';
                    inp.value = current;

                    openModal('edit-modal');
                    inp.focus();
                });
            });

            document.getElementById('btn-edit-save').addEventListener('click', async () => {
                if (!activeEditField) return;
                const val  = document.getElementById('edit-modal-input').value.trim();
                const errEl = document.getElementById('edit-modal-err');
                errEl.textContent = '';

                const btn = document.getElementById('btn-edit-save');
                btn.disabled = true;
                btn.textContent = 'Saving…';

                try {
                    const data = await patchAPI('/users/update', { [activeEditField]: val });
                    if (!data.success) {
                        errEl.textContent = data.errors?.[activeEditField]
                            ?? data.message
                            ?? 'Update failed.';
                        return;
                    }
                    // Update DOM
                    const row = document.getElementById('row-' + activeEditField);
                    if (row) {
                        const valEl = row.querySelector('.profile-row__value');
                        if (valEl) {
                            if (activeEditField === 'username') {
                                valEl.textContent = val ? '@' + val : '';
                            } else {
                                valEl.textContent = val || '';
                            }
                        }
                    }
                    // Update page title if name changed
                    if (['first_name', 'last_name'].includes(activeEditField)) {
                        // Re-fetch full name from server response
                        const u = data.user;
                        document.getElementById('profile-name').textContent =
                            (u.first_name + ' ' + (u.last_name ?? '')).trim();
                    }

                    toast('✓ ' + (editConfigs[activeEditField]?.label ?? 'Field') + ' updated.', 'success');
                    closeModal('edit-modal');

                } catch {
                    errEl.textContent = 'Network error. Please try again.';
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Save';
                }
            });

            // ── Avatar change ────────────────────────────────────────────────────
            const btnAvatarChange = document.getElementById('btn-avatar-change');
            if (btnAvatarChange) {
                btnAvatarChange.addEventListener('click', () => openModal('avatar-modal'));
                btnAvatarChange.addEventListener('keydown', e => {
                    if (e.key === 'Enter') openModal('avatar-modal');
                });
            }

            document.getElementById('btn-avatar-upload').addEventListener('click', () => {
                document.getElementById('avatar-file-input').click();
            });

            document.getElementById('avatar-file-input').addEventListener('change', function () {
                const file = this.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) {
                    toast('File too large. Max 5 MB.', 'error');
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => {
                    const canvas = document.getElementById('avatar-crop-canvas');
                    const ctx    = canvas.getContext('2d');
                    const img    = new Image();
                    img.onload = () => {
                        const size = Math.min(img.width, img.height);
                        const x    = (img.width  - size) / 2;
                        const y    = (img.height - size) / 2;
                        canvas.width = canvas.height = 200;
                        ctx.clearRect(0, 0, 200, 200);
                        ctx.save();
                        ctx.beginPath();
                        ctx.arc(100, 100, 100, 0, Math.PI * 2);
                        ctx.clip();
                        ctx.drawImage(img, x, y, size, size, 0, 0, 200, 200);
                        ctx.restore();
                    };
                    img.src = e.target.result;
                    document.getElementById('avatar-crop-wrap').hidden = false;
                    document.getElementById('btn-avatar-save').hidden  = false;
                };
                reader.readAsDataURL(file);
            });

            document.getElementById('btn-avatar-save').addEventListener('click', async () => {
                const file  = document.getElementById('avatar-file-input').files[0];
                if (!file) return;
                const btn = document.getElementById('btn-avatar-save');
                btn.disabled = true;
                btn.textContent = 'Uploading…';

                try {
                    const fd = new FormData();
                    fd.append('file',    file);
                    fd.append('type',    'image');
                    fd.append('chat_id', '0');
                    const upRes  = await fetch(`${API}/media/upload`, {
                        method: 'POST', body: fd, credentials: 'include',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const upData = await upRes.json();
                    if (!upData.success) { toast('Upload failed.', 'error'); return; }

                    const res  = await patchAPI('/users/update', { avatar: upData.media.filename });
                    if (!res.success) { toast(res.message || 'Failed to set avatar.', 'error'); return; }

                    // Update avatar in DOM
                    const avatarImg = document.getElementById('avatar-img');
                    const avatarFB  = document.getElementById('avatar-fallback');
                    const newSrc    = upData.media.url;
                    if (avatarImg) {
                        avatarImg.src = newSrc;
                        avatarImg.style.display = 'block';
                    } else {
                        const newImg = document.createElement('img');
                        newImg.id        = 'avatar-img';
                        newImg.src       = newSrc;
                        newImg.className = 'profile-avatar__img';
                        document.getElementById('profile-avatar').prepend(newImg);
                    }
                    if (avatarFB) avatarFB.style.display = 'none';

                    toast('✓ Profile photo updated.', 'success');
                    closeModal('avatar-modal');

                } catch {
                    toast('Network error.', 'error');
                } finally {
                    btn.disabled    = false;
                    btn.textContent = 'Save Photo';
                }
            });

            document.getElementById('btn-avatar-remove').addEventListener('click', async () => {
                try {
                    const data = await patchAPI('/users/update', { avatar: null });
                    if (!data.success) { toast('Failed to remove avatar.', 'error'); return; }
                    const avatarImg = document.getElementById('avatar-img');
                    if (avatarImg) avatarImg.remove();
                    const fb = document.getElementById('avatar-fallback');
                    if (fb) fb.style.display = '';
                    toast('✓ Profile photo removed.', 'success');
                    closeModal('avatar-modal');
                } catch {
                    toast('Network error.', 'error');
                }
            });

            // ── Privacy save ─────────────────────────────────────────────────────
            document.getElementById('btn-save-privacy').addEventListener('click', async () => {
                const searchOpts = [...document.querySelectorAll('.privacy-search-opt:checked')]
                    .map(c => c.value).join(',') || 'username';

                const body = {
                    privacy_last_seen: document.getElementById('privacy-last-seen').value,
                    privacy_avatar:    document.getElementById('privacy-avatar').value,
                    privacy_phone:     document.getElementById('privacy-phone').value,
                    privacy_search:    searchOpts,
                };

                const btn = document.getElementById('btn-save-privacy');
                btn.disabled = true;
                btn.textContent = 'Saving…';

                try {
                    const data = await patchAPI('/users/privacy', body);
                    toast(data.success ? '✓ Privacy settings saved.' : (data.message || 'Failed.'),
                        data.success ? 'success' : 'error');
                } catch {
                    toast('Network error.', 'error');
                } finally {
                    btn.disabled    = false;
                    btn.textContent = 'Save Privacy';
                }
            });

            // ── Load sessions ────────────────────────────────────────────────────
            (async function loadSessions() {
                try {
                    const res  = await fetch(`${API}/auth/sessions`, {
                        credentials: 'include',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const data = await res.json();
                    const list = document.getElementById('sessions-list');
                    list.innerHTML = '';
                    if (!data.success || !data.sessions?.length) {
                        list.innerHTML = '<p class="text-muted" style="font-size:13px">No active sessions found.</p>';
                        return;
                    }
                    data.sessions.forEach(s => {
                        const item = document.createElement('div');
                        item.className = 'session-item';
                        item.setAttribute('role', 'listitem');
                        item.innerHTML = `
                        <div class="session-item__icon" aria-hidden="true">
                            ${s.device_type === 'mobile' ? '📱' : s.device_type === 'tablet' ? '📟' : '💻'}
                        </div>
                        <div class="session-item__info">
                            <p class="session-item__device">${escHtml(s.device_name || s.user_agent || 'Unknown device')}</p>
                            <p class="session-item__meta">
                                ${escHtml(s.ip_address || '')}
                                · Last active: ${escHtml(s.last_active_at || 'Unknown')}
                                ${s.is_current ? ' · <strong>Current</strong>' : ''}
                            </p>
                        </div>
                        ${!s.is_current
                            ? `<button class="btn btn--danger btn--xs session-revoke"
                                       data-token="${escHtml(s.id)}" aria-label="Revoke session">
                                   Revoke
                               </button>`
                            : ''
                        }
                    `;
                        list.appendChild(item);
                    });

                    // Revoke single session
                    list.querySelectorAll('.session-revoke').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const sid = btn.dataset.token;
                            btn.disabled = true;
                            btn.textContent = '…';
                            const r = await postAPI('/auth/sessions/revoke', { session_id: sid });
                            if (r.success) {
                                btn.closest('.session-item').remove();
                                toast('Session revoked.', 'success');
                            } else {
                                btn.disabled    = false;
                                btn.textContent = 'Revoke';
                                toast('Failed.', 'error');
                            }
                        });
                    });

                } catch {
                    document.getElementById('sessions-list').innerHTML =
                        '<p class="text-muted" style="font-size:13px">Could not load sessions.</p>';
                }
            })();

            // ── Revoke ALL sessions ───────────────────────────────────────────────
            document.getElementById('btn-revoke-all-sessions').addEventListener('click', async () => {
                const btn = document.getElementById('btn-revoke-all-sessions');
                btn.disabled = true;
                try {
                    const data = await postAPI('/auth/sessions/revoke-all');
                    toast(data.success ? '✓ All other sessions terminated.' : (data.message || 'Failed.'),
                        data.success ? 'success' : 'error');
                    if (data.success) document.getElementById('sessions-list').innerHTML =
                        '<p class="text-muted" style="font-size:13px">No other active sessions.</p>';
                } catch {
                    toast('Network error.', 'error');
                } finally {
                    btn.disabled = false;
                }
            });

            // ── Copy public key ───────────────────────────────────────────────────
            document.getElementById('btn-copy-pubkey').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(cfg.publicKey || '');
                    toast('✓ Public key copied.', 'success');
                } catch {
                    toast('Copy failed.', 'error');
                }
            });
            document.getElementById('public-key-display').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(cfg.publicKey || '');
                    toast('✓ Public key copied.', 'success');
                } catch {}
            });

            // ── Export private key ────────────────────────────────────────────────
            document.getElementById('btn-export-privkey').addEventListener('click', async () => {
                try {
                    const req = indexedDB.open('namak_keys', 1);
                    req.onsuccess = e => {
                        const db  = e.target.result;
                        const tx  = db.transaction('keys', 'readonly');
                        const get = tx.objectStore('keys').get(cfg.userId);
                        get.onsuccess = () => {
                            const key = get.result?.private_key;
                            if (!key) { toast('No private key found in this browser.', 'error'); return; }
                            const blob = new Blob([key], { type: 'text/plain' });
                            const url  = URL.createObjectURL(blob);
                            const a    = document.createElement('a');
                            a.href     = url;
                            a.download = 'namak-private-key.txt';
                            a.click();
                            URL.revokeObjectURL(url);
                            toast('✓ Private key downloaded.', 'success');
                        };
                    };
                } catch {
                    toast('Could not access key storage.', 'error');
                }
            });

            // ── Regenerate keys ───────────────────────────────────────────────────
            document.getElementById('btn-regen-keys').addEventListener('click', () => openModal('regen-keys-modal'));
            document.getElementById('btn-regen-confirm').addEventListener('click', async () => {
                closeModal('regen-keys-modal');
                const t = toast('Regenerating keys…', 'info', 0);
                try {
                    const data = await postAPI('/auth/regenerate-keys');
                    t.remove();
                    if (!data.success) { toast(data.message || 'Failed.', 'error'); return; }
                    // Store new private key
                    await storeKeyIDB(cfg.userId, data.private_key);
                    toast('✓ Keys regenerated. All secret chats invalidated.', 'warning', 5000);
                } catch {
                    t.remove();
                    toast('Network error.', 'error');
                }
            });

            // ── Logout ────────────────────────────────────────────────────────────
            document.getElementById('btn-logout').addEventListener('click', async () => {
                await postAPI('/auth/logout');
                localStorage.removeItem('namak_token');
                sessionStorage.removeItem('namak_token');
                window.location.href = '/auth/login';
            });

            document.getElementById('btn-logout-all').addEventListener('click', async () => {
                await postAPI('/auth/logout-all');
                localStorage.clear();
                sessionStorage.clear();
                window.location.href = '/auth/login';
            });

            // ── Delete account ────────────────────────────────────────────────────
            document.getElementById('btn-delete-account').addEventListener('click', () => {
                document.getElementById('delete-confirm-input').value = '';
                document.getElementById('btn-delete-account-confirm').disabled = true;
                openModal('delete-account-modal');
            });

            document.getElementById('delete-confirm-input').addEventListener('input', function () {
                const expected = cfg.username || '';
                document.getElementById('btn-delete-account-confirm').disabled =
                    this.value.trim() !== expected;
            });

            document.getElementById('btn-delete-account-confirm').addEventListener('click', async () => {
                const btn = document.getElementById('btn-delete-account-confirm');
                btn.disabled = true;
                btn.textContent = 'Deleting…';
                try {
                    const data = await fetch(`${API}/users/delete`, {
                        method: 'DELETE', credentials: 'include',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    }).then(r => r.json());

                    if (!data.success) {
                        toast(data.message || 'Deletion failed.', 'error');
                        btn.disabled    = false;
                        btn.textContent = 'Delete Forever';
                        return;
                    }
                    localStorage.clear();
                    sessionStorage.clear();
                    window.location.href = '/auth/login?deleted=1';
                } catch {
                    toast('Network error.', 'error');
                    btn.disabled    = false;
                    btn.textContent = 'Delete Forever';
                }
            });

        }

        // ════════════════════════════════════════════
        // OTHER USER PROFILE LOGIC
        // ════════════════════════════════════════════
        if (!cfg.isOwnProfile) {

            // ── Send message ──────────────────────────────────────────────────────
            document.getElementById('btn-send-message')?.addEventListener('click', async () => {
                const t = toast('Opening chat…', 'info', 2000);
                try {
                    const data = await postAPI('/chats/create', {
                        type:       'private',
                        member_ids: [cfg.targetId],
                    });
                    if (data.success) {
                        window.location.href = '/app/chat?chat=' + data.chat.id;
                    } else {
                        toast(data.message || 'Could not open chat.', 'error');
                    }
                } catch {
                    toast('Network error.', 'error');
                }
            });

            // ── Add / remove contact ──────────────────────────────────────────────
            document.getElementById('btn-toggle-contact')?.addEventListener('click', async function () {
                const action = this.dataset.action;
                const endpoint = action === 'add'
                    ? '/users/contacts/add'
                    : '/users/contacts/remove';

                this.disabled = true;
                try {
                    const data = await postAPI(endpoint, { user_id: cfg.targetId });
                    if (data.success) {
                        if (action === 'add') {
                            this.dataset.action = 'remove';
                            this.querySelector('span:last-child')?.remove();
                            this.innerHTML = `
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">…</svg>
                            Remove Contact`;
                            toast('✓ Contact added.', 'success');
                        } else {
                            this.dataset.action = 'add';
                            this.innerHTML = `
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">…</svg>
                            Add Contact`;
                            toast('✓ Contact removed.', 'success');
                        }
                    } else {
                        toast(data.message || 'Action failed.', 'error');
                    }
                } catch {
                    toast('Network error.', 'error');
                } finally {
                    this.disabled = false;
                }
            });

            // ── More dropdown ─────────────────────────────────────────────────────
            document.getElementById('btn-more-user')?.addEventListener('click', e => {
                e.stopPropagation();
                const dd = document.getElementById('user-more-dropdown');
                dd.hidden = !dd.hidden;
            });
            document.addEventListener('click', () => {
                const dd = document.getElementById('user-more-dropdown');
                if (dd) dd.hidden = true;
            });

            // ── Block / Unblock ───────────────────────────────────────────────────
            document.getElementById('btn-block-user')?.addEventListener('click', function () {
                const blocked = this.dataset.blocked === '1';
                document.getElementById('block-modal-title').textContent =
                    blocked ? 'Unblock User' : 'Block User';
                document.getElementById('block-modal-text').textContent =
                    blocked
                        ? 'Are you sure you want to unblock this user?'
                        : 'Are you sure you want to block this user? They will not be able to message you.';
                openModal('block-modal');
            });

            document.getElementById('btn-block-confirm').addEventListener('click', async () => {
                const btn     = document.getElementById('btn-block-user');
                const blocked = btn.dataset.blocked === '1';
                const endpoint = blocked
                    ? '/users/unblock'
                    : '/users/block';
                closeModal('block-modal');
                try {
                    const data = await postAPI(endpoint, { user_id: cfg.targetId });
                    if (data.success) {
                        btn.dataset.blocked = blocked ? '0' : '1';
                        btn.textContent     = blocked ? '🚫 Block User' : '✅ Unblock User';
                        toast(blocked ? '✓ User unblocked.' : '✓ User blocked.', 'success');
                    } else {
                        toast(data.message || 'Failed.', 'error');
                    }
                } catch {
                    toast('Network error.', 'error');
                }
            });

            // ── Report ────────────────────────────────────────────────────────────
            document.getElementById('btn-report-user')?.addEventListener('click', () => {
                document.getElementById('user-more-dropdown').hidden = true;
                openModal('report-modal');
            });

            document.getElementById('btn-report-submit').addEventListener('click', async () => {
                const reason = document.querySelector('input[name="report-reason"]:checked')?.value;
                if (!reason) { toast('Please select a reason.', 'error'); return; }
                const comment = document.getElementById('report-comment').value.trim();
                const btn     = document.getElementById('btn-report-submit');
                btn.disabled  = true;
                try {
                    const data = await postAPI('/users/report', {
                        user_id: cfg.targetId,
                        reason,
                        comment: comment || null,
                    });
                    toast(data.success ? '✓ Report submitted. Thank you.' : (data.message || 'Failed.'),
                        data.success ? 'success' : 'error');
                    if (data.success) closeModal('report-modal');
                } catch {
                    toast('Network error.', 'error');
                } finally {
                    btn.disabled = false;
                }
            });

            // ── Secret chat ───────────────────────────────────────────────────────
            document.querySelector('[data-action="secret-chat"]')?.addEventListener('click', async () => {
                document.getElementById('user-more-dropdown').hidden = true;
                const data = await postAPI('/chats/create', {
                    type:       'secret',
                    member_ids: [cfg.targetId],
                });
                if (data.success) {
                    window.location.href = '/app/chat?chat=' + data.chat.id;
                } else {
                    toast(data.message || 'Could not create secret chat.', 'error');
                }
            });
        }

        // ════════════════════════════════════════════
        // SHARED UTILS
        // ════════════════════════════════════════════

        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        async function storeKeyIDB(userId, privateKey) {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open('namak_keys', 1);
                req.onupgradeneeded = e => e.target.result.createObjectStore('keys', { keyPath: 'user_id' });
                req.onsuccess = e => {
                    const db = e.target.result;
                    const tx = db.transaction('keys', 'readwrite');
                    tx.objectStore('keys').put({ user_id: userId, private_key: privateKey });
                    tx.oncomplete = resolve;
                    tx.onerror    = reject;
                };
                req.onerror = reject;
            });
        }

        // ── Service Worker ────────────────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/assets/js/service-worker.js', { scope: '/' })
                .catch(e => console.warn('[SW]', e));
        }

    })();
</script>

</body>
</html>

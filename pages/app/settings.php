<?php
declare(strict_types=1);

/**
 * Settings Page
 * Namak Messenger — pages/app/settings.php
 *
 * Sections:
 *  1. Appearance     — theme, font size, language, bubble style
 *  2. Notifications  — sound, desktop, badge, preview
 *  3. Chat Settings  — enter to send, media auto-download, link preview
 *  4. Storage        — cache size, clear cache, IndexedDB usage
 *  5. Accessibility  — motion reduce, high contrast, screen reader hints
 *  6. About          — version, changelog, open-source licenses
 *
 * All settings stored client-side in localStorage.
 * Server-side: notification & privacy prefs synced via API.
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
$appName = $_ENV['APP_NAME']    ?? 'Namak';
$version = $_ENV['APP_VERSION'] ?? '1.0.0';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#2196f3">
    <meta name="robots" content="noindex, nofollow">
    <title>Settings — <?= htmlspecialchars($appName) ?></title>

    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/settings.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">

    <script>
        (function(){
            var t = localStorage.getItem('namak_theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="app-body settings-page" data-user-id="<?= (int) $myId ?>">

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
            <span class="sidebar__header-title">Settings</span>
        </header>

        <!-- Settings navigation (left panel) -->
        <nav class="settings-nav" role="tablist" aria-label="Settings sections" aria-orientation="vertical">
            <a href="#section-appearance"    class="settings-nav__item active" role="tab" aria-selected="true"  data-section="appearance">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v11a3 3 0 106 0V4a2 2 0 00-2-2H4zm1 14a1 1 0 100-2 1 1 0 000 2zm5-1.757l4.9-4.9a2 2 0 000-2.828L13.485 5.1a2 2 0 00-2.828 0L10 5.757v8.486zM16 18H9.071l6-6H16a2 2 0 012 2v2a2 2 0 01-2 2z" clip-rule="evenodd"/></svg>
                Appearance
            </a>
            <a href="#section-notifications" class="settings-nav__item" role="tab" aria-selected="false" data-section="notifications">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                Notifications
            </a>
            <a href="#section-chat"          class="settings-nav__item" role="tab" aria-selected="false" data-section="chat">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/></svg>
                Chat
            </a>
            <a href="#section-storage"       class="settings-nav__item" role="tab" aria-selected="false" data-section="storage">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/></svg>
                Storage
            </a>
            <a href="#section-accessibility" class="settings-nav__item" role="tab" aria-selected="false" data-section="accessibility">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                Accessibility
            </a>
            <a href="#section-about"         class="settings-nav__item" role="tab" aria-selected="false" data-section="about">
                <svg viewBox="0 0 20 20" fill="currentColor" class="settings-nav__icon"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                About
            </a>
        </nav>

        <nav class="sidebar__footer" role="navigation" aria-label="App navigation">
            <a href="/app/chat"     class="sidebar__nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><span>Chats</span></a>
            <a href="/app/contacts" class="sidebar__nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg><span>Contacts</span></a>
            <a href="/app/settings" class="sidebar__nav-item active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg><span>Settings</span></a>
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
         SETTINGS MAIN
    ════════════════════════════════════════ -->
    <main class="settings-main" id="settings-main" role="main">

        <div class="settings-header">
            <h1 class="settings-header__title">Settings</h1>
            <p class="settings-header__sub">Customize your <?= htmlspecialchars($appName) ?> experience</p>
        </div>

        <!-- ══════════════════════════════════════
             1. APPEARANCE
        ══════════════════════════════════════ -->
        <section class="settings-section active" id="section-appearance" aria-labelledby="title-appearance">
            <h2 class="settings-section__title" id="title-appearance">
                🎨 Appearance
            </h2>

            <!-- Theme -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Theme</p>
                    <p class="setting-row__sub">Choose light or dark mode</p>
                </div>
                <div class="theme-selector" role="radiogroup" aria-label="Theme">
                    <label class="theme-option" data-theme="light">
                        <input type="radio" name="theme" value="light" class="sr-only">
                        <div class="theme-option__preview theme-option__preview--light" aria-hidden="true">
                            <div class="theme-preview-header"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--in"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--out"></div>
                        </div>
                        <span class="theme-option__label">Light</span>
                    </label>
                    <label class="theme-option" data-theme="dark">
                        <input type="radio" name="theme" value="dark" class="sr-only">
                        <div class="theme-option__preview theme-option__preview--dark" aria-hidden="true">
                            <div class="theme-preview-header"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--in"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--out"></div>
                        </div>
                        <span class="theme-option__label">Dark</span>
                    </label>
                    <label class="theme-option" data-theme="system">
                        <input type="radio" name="theme" value="system" class="sr-only">
                        <div class="theme-option__preview theme-option__preview--system" aria-hidden="true">
                            <div class="theme-preview-header"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--in"></div>
                            <div class="theme-preview-bubble theme-preview-bubble--out"></div>
                        </div>
                        <span class="theme-option__label">System</span>
                    </label>
                </div>
            </div>

            <!-- Accent color -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Accent Color</p>
                    <p class="setting-row__sub">Color used for buttons and highlights</p>
                </div>
                <div class="color-swatches" role="radiogroup" aria-label="Accent color">
                    <?php foreach ([
                                       'blue'   => ['#2196f3', 'Blue'],
                                       'teal'   => ['#009688', 'Teal'],
                                       'green'  => ['#4caf50', 'Green'],
                                       'purple' => ['#9c27b0', 'Purple'],
                                       'pink'   => ['#e91e63', 'Pink'],
                                       'orange' => ['#ff9800', 'Orange'],
                                   ] as $key => [$hex, $label]): ?>
                        <label class="color-swatch" title="<?= $label ?>" aria-label="<?= $label ?>">
                            <input type="radio" name="accent" value="<?= $key ?>" class="sr-only">
                            <span class="color-swatch__dot" style="background:<?= $hex ?>"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Font size -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Font Size</p>
                    <p class="setting-row__sub">Message text size</p>
                </div>
                <div class="font-size-control" role="group" aria-label="Font size">
                    <button class="font-size-btn" id="btn-font-dec" aria-label="Decrease font size">A−</button>
                    <span class="font-size-val" id="font-size-val" aria-live="polite">14px</span>
                    <button class="font-size-btn" id="btn-font-inc" aria-label="Increase font size">A+</button>
                </div>
            </div>

            <!-- Chat bubble style -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Bubble Style</p>
                    <p class="setting-row__sub">Shape of message bubbles</p>
                </div>
                <select class="form-select" id="bubble-style" aria-label="Bubble style">
                    <option value="rounded">Rounded (default)</option>
                    <option value="sharp">Sharp corners</option>
                    <option value="ios">iOS style</option>
                    <option value="minimal">Minimal (no bubble)</option>
                </select>
            </div>

            <!-- Chat background -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Chat Background</p>
                    <p class="setting-row__sub">Background pattern in chat</p>
                </div>
                <div class="bg-selector" role="radiogroup" aria-label="Chat background">
                    <?php foreach ([
                                       'none'    => '⬜ None',
                                       'dots'    => '⠿ Dots',
                                       'grid'    => '⊞ Grid',
                                       'wave'    => '〜 Wave',
                                   ] as $val => $label): ?>
                        <label class="bg-option">
                            <input type="radio" name="chat-bg" value="<?= $val ?>" class="sr-only">
                            <span class="bg-option__label"><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Language -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Language</p>
                    <p class="setting-row__sub">App display language</p>
                </div>
                <select class="form-select" id="app-language" aria-label="Language">
                    <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
                    <option value="fa" <?= $lang === 'fa' ? 'selected' : '' ?>>🇮🇷 فارسی</option>
                </select>
            </div>

        </section>

        <!-- ══════════════════════════════════════
             2. NOTIFICATIONS
        ══════════════════════════════════════ -->
        <section class="settings-section" id="section-notifications" aria-labelledby="title-notifications">
            <h2 class="settings-section__title" id="title-notifications">
                🔔 Notifications
            </h2>

            <!-- Desktop notifications -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Desktop Notifications</p>
                    <p class="setting-row__sub">Show system notifications for new messages</p>
                </div>
                <div class="setting-row__control">
                    <label class="toggle" aria-label="Desktop notifications">
                        <input type="checkbox" id="notif-desktop" class="toggle__input">
                        <span class="toggle__track"></span>
                    </label>
                    <button class="btn btn--ghost btn--xs" id="btn-notif-request" hidden>
                        Allow
                    </button>
                </div>
            </div>

            <!-- Sound -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Notification Sound</p>
                    <p class="setting-row__sub">Play sound for new messages</p>
                </div>
                <label class="toggle" aria-label="Notification sound">
                    <input type="checkbox" id="notif-sound" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Sound selection -->
            <div class="setting-row setting-row--indent" id="sound-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Sound</p>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <select class="form-select form-select--sm" id="notif-sound-select" aria-label="Notification sound">
                        <option value="default">Default</option>
                        <option value="ping">Ping</option>
                        <option value="pop">Pop</option>
                        <option value="chime">Chime</option>
                        <option value="none">None</option>
                    </select>
                    <button class="icon-btn" id="btn-preview-sound" aria-label="Preview sound" title="Preview">▶</button>
                </div>
            </div>

            <!-- Message preview -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Message Preview</p>
                    <p class="setting-row__sub">Show message content in notification</p>
                </div>
                <label class="toggle" aria-label="Message preview in notifications">
                    <input type="checkbox" id="notif-preview" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Badge count -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Badge Count</p>
                    <p class="setting-row__sub">Show unread count on app icon</p>
                </div>
                <label class="toggle" aria-label="Badge count">
                    <input type="checkbox" id="notif-badge" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Do Not Disturb -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Do Not Disturb</p>
                    <p class="setting-row__sub">Silence all notifications temporarily</p>
                </div>
                <label class="toggle" aria-label="Do not disturb">
                    <input type="checkbox" id="notif-dnd" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- DND schedule -->
            <div class="setting-row setting-row--indent" id="dnd-schedule-row" hidden>
                <div class="setting-row__info">
                    <p class="setting-row__label">DND Schedule</p>
                    <p class="setting-row__sub">Auto-enable during these hours</p>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="time" id="dnd-start" class="form-input form-input--time" value="22:00" aria-label="DND start time">
                    <span>–</span>
                    <input type="time" id="dnd-end"   class="form-input form-input--time" value="08:00" aria-label="DND end time">
                </div>
            </div>

        </section>

        <!-- ══════════════════════════════════════
             3. CHAT SETTINGS
        ══════════════════════════════════════ -->
        <section class="settings-section" id="section-chat" aria-labelledby="title-chat">
            <h2 class="settings-section__title" id="title-chat">
                💬 Chat Settings
            </h2>

            <!-- Enter to send -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Enter to Send</p>
                    <p class="setting-row__sub">Press Enter to send; Shift+Enter for new line</p>
                </div>
                <label class="toggle" aria-label="Enter to send">
                    <input type="checkbox" id="enter-to-send" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Read receipts -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Read Receipts</p>
                    <p class="setting-row__sub">Show double-tick when messages are read</p>
                </div>
                <label class="toggle" aria-label="Read receipts">
                    <input type="checkbox" id="read-receipts" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Typing indicator -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Typing Indicator</p>
                    <p class="setting-row__sub">Show "typing…" when composing</p>
                </div>
                <label class="toggle" aria-label="Typing indicator">
                    <input type="checkbox" id="typing-indicator" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Link preview -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Link Preview</p>
                    <p class="setting-row__sub">Generate previews for links in messages</p>
                </div>
                <label class="toggle" aria-label="Link preview">
                    <input type="checkbox" id="link-preview" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Auto-download media -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Auto-Download Media</p>
                    <p class="setting-row__sub">Automatically download incoming media</p>
                </div>
                <div class="setting-row__control" role="group" aria-label="Auto-download options">
                    <?php foreach ([
                                       'auto-dl-images' => 'Images',
                                       'auto-dl-videos' => 'Videos',
                                       'auto-dl-files'  => 'Files',
                                       'auto-dl-voice'  => 'Voice',
                                   ] as $id => $label): ?>
                        <label class="form-check form-check--inline">
                            <input type="checkbox" id="<?= $id ?>" class="form-check__input auto-dl-opt"
                                <?= in_array($id, ['auto-dl-images','auto-dl-voice']) ? 'checked' : '' ?>>
                            <span class="form-check__box"></span>
                            <span class="form-check__label"><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Message grouping -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Group Messages</p>
                    <p class="setting-row__sub">Cluster consecutive messages from same sender</p>
                </div>
                <label class="toggle" aria-label="Group messages">
                    <input type="checkbox" id="group-messages" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Emoji large display -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Large Emoji</p>
                    <p class="setting-row__sub">Show emoji-only messages larger</p>
                </div>
                <label class="toggle" aria-label="Large emoji">
                    <input type="checkbox" id="large-emoji" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Spell check -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Spell Check</p>
                    <p class="setting-row__sub">Enable browser spell check in composer</p>
                </div>
                <label class="toggle" aria-label="Spell check">
                    <input type="checkbox" id="spell-check" class="toggle__input" checked>
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Polling interval -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Sync Interval</p>
                    <p class="setting-row__sub">How often to check for new messages</p>
                </div>
                <select class="form-select" id="poll-interval" aria-label="Sync interval">
                    <option value="1000">Every 1 second</option>
                    <option value="2000" selected>Every 2 seconds (recommended)</option>
                    <option value="5000">Every 5 seconds</option>
                    <option value="10000">Every 10 seconds</option>
                </select>
            </div>

        </section>

        <!-- ══════════════════════════════════════
             4. STORAGE
        ══════════════════════════════════════ -->
        <section class="settings-section" id="section-storage" aria-labelledby="title-storage">
            <h2 class="settings-section__title" id="title-storage">
                🗄 Storage &amp; Data
            </h2>

            <!-- Usage overview -->
            <div class="storage-usage" id="storage-usage" aria-label="Storage usage">
                <div class="storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="0" id="storage-bar">
                    <div class="storage-bar__fill" id="storage-bar-fill" style="width:0%"></div>
                </div>
                <div class="storage-breakdown" id="storage-breakdown">
                    <div class="storage-item">
                        <span class="storage-item__dot storage-item__dot--media"></span>
                        <span>Media</span>
                        <span class="storage-item__size" id="sz-media">—</span>
                    </div>
                    <div class="storage-item">
                        <span class="storage-item__dot storage-item__dot--messages"></span>
                        <span>Messages cache</span>
                        <span class="storage-item__size" id="sz-messages">—</span>
                    </div>
                    <div class="storage-item">
                        <span class="storage-item__dot storage-item__dot--other"></span>
                        <span>Other</span>
                        <span class="storage-item__size" id="sz-other">—</span>
                    </div>
                    <div class="storage-item storage-item--total">
                        <span><strong>Total used</strong></span>
                        <span class="storage-item__size" id="sz-total"><strong>—</strong></span>
                    </div>
                </div>
            </div>

            <!-- Clear cache -->
            <div class="setting-row" style="margin-top:20px">
                <div class="setting-row__info">
                    <p class="setting-row__label">Clear Cache</p>
                    <p class="setting-row__sub">Remove cached images and files (they will re-download on demand)</p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-clear-cache">Clear</button>
            </div>

            <!-- Clear message history (local) -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Clear Local Message Cache</p>
                    <p class="setting-row__sub">Remove locally cached messages (does not delete from server)</p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-clear-messages">Clear</button>
            </div>

            <!-- Clear all local data -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label text-danger">Clear All Local Data</p>
                    <p class="setting-row__sub">Wipes all local storage, IndexedDB, service worker cache.<br>
                        <strong>This also removes your private key from this device.</strong>
                    </p>
                </div>
                <button class="btn btn--danger btn--sm" id="btn-clear-all">Clear All</button>
            </div>

            <!-- Export data -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Export My Data</p>
                    <p class="setting-row__sub">Download a JSON file of your account data</p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-export-data">Export</button>
            </div>

        </section>

        <!-- ══════════════════════════════════════
             5. ACCESSIBILITY
        ══════════════════════════════════════ -->
        <section class="settings-section" id="section-accessibility" aria-labelledby="title-accessibility">
            <h2 class="settings-section__title" id="title-accessibility">
                ♿ Accessibility
            </h2>

            <!-- Reduce motion -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Reduce Motion</p>
                    <p class="setting-row__sub">Minimize animations and transitions</p>
                </div>
                <label class="toggle" aria-label="Reduce motion">
                    <input type="checkbox" id="reduce-motion" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- High contrast -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">High Contrast</p>
                    <p class="setting-row__sub">Increase contrast for better readability</p>
                </div>
                <label class="toggle" aria-label="High contrast">
                    <input type="checkbox" id="high-contrast" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Large click targets -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Large Touch Targets</p>
                    <p class="setting-row__sub">Increase size of buttons for easier tapping</p>
                </div>
                <label class="toggle" aria-label="Large touch targets">
                    <input type="checkbox" id="large-targets" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Focus visible -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Always Show Focus Ring</p>
                    <p class="setting-row__sub">Show keyboard focus indicator at all times</p>
                </div>
                <label class="toggle" aria-label="Always show focus ring">
                    <input type="checkbox" id="focus-ring" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

            <!-- Screen reader mode -->
            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Screen Reader Optimized</p>
                    <p class="setting-row__sub">Add extra ARIA labels and descriptions</p>
                </div>
                <label class="toggle" aria-label="Screen reader optimized">
                    <input type="checkbox" id="screen-reader" class="toggle__input">
                    <span class="toggle__track"></span>
                </label>
            </div>

        </section>

        <!-- ══════════════════════════════════════
             6. ABOUT
        ══════════════════════════════════════ -->
        <section class="settings-section" id="section-about" aria-labelledby="title-about">
            <h2 class="settings-section__title" id="title-about">
                ℹ️ About
            </h2>

            <div class="about-hero">
                <?php if (!empty($_ENV['APP_LOGO'])): ?>
                    <img src="<?= htmlspecialchars($_ENV['APP_LOGO']) ?>" alt="<?= htmlspecialchars($appName) ?>" class="about-hero__logo">
                <?php else: ?>
                    <div class="about-hero__icon" aria-hidden="true">✉</div>
                <?php endif; ?>
                <h3 class="about-hero__name"><?= htmlspecialchars($appName) ?></h3>
                <p class="about-hero__version" id="app-version">Version <?= htmlspecialchars($version) ?></p>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Version</p>
                    <p class="setting-row__sub"><?= htmlspecialchars($version) ?></p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-check-update">Check for updates</button>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Changelog</p>
                    <p class="setting-row__sub">What's new in this version</p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-changelog">View</button>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Terms of Service</p>
                </div>
                <a href="/terms" class="btn btn--ghost btn--sm" target="_blank" rel="noopener">Read</a>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Privacy Policy</p>
                </div>
                <a href="/privacy" class="btn btn--ghost btn--sm" target="_blank" rel="noopener">Read</a>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Open Source Licenses</p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-licenses">View</button>
            </div>

            <div class="setting-row">
                <div class="setting-row__info">
                    <p class="setting-row__label">Debug Info</p>
                    <p class="setting-row__sub" id="debug-info-text" style="font-size:11px;font-family:monospace"></p>
                </div>
                <button class="btn btn--ghost btn--sm" id="btn-copy-debug">Copy</button>
            </div>

        </section>

        <!-- Save bar (floats at bottom when unsaved changes exist) -->
        <div class="settings-save-bar" id="settings-save-bar" hidden role="status" aria-live="polite">
            <p class="settings-save-bar__text">You have unsaved changes</p>
            <div class="settings-save-bar__actions">
                <button class="btn btn--ghost btn--sm" id="btn-discard-settings">Discard</button>
                <button class="btn btn--primary btn--sm" id="btn-save-settings">Save Changes</button>
            </div>
        </div>

    </main>

</div><!-- /app-shell -->

<!-- ════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════ -->

<!-- Changelog modal -->
<div class="modal-overlay" id="changelog-modal" role="dialog" aria-modal="true" aria-label="Changelog" hidden>
    <div class="modal">
        <div class="modal__header">
            <h2 class="modal__title">📋 Changelog</h2>
            <button class="icon-btn modal__close" data-close="changelog-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body" style="max-height:60vh;overflow-y:auto" id="changelog-body">
            <p class="text-muted" style="font-size:13px">Loading…</p>
        </div>
    </div>
</div>

<!-- Licenses modal -->
<div class="modal-overlay" id="licenses-modal" role="dialog" aria-modal="true" aria-label="Open source licenses" hidden>
    <div class="modal">
        <div class="modal__header">
            <h2 class="modal__title">📜 Open Source Licenses</h2>
            <button class="icon-btn modal__close" data-close="licenses-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body" style="max-height:60vh;overflow-y:auto">
            <p style="font-size:13px;color:var(--text-muted);line-height:1.8">
                This project uses only standard browser APIs and PHP built-ins.<br>
                No third-party JavaScript or CSS libraries are bundled.<br><br>
                All code is proprietary to <?= htmlspecialchars($appName) ?>.
            </p>
        </div>
    </div>
</div>

<!-- Clear all confirm modal -->
<div class="modal-overlay" id="clear-all-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title text-danger">⚠️ Clear All Local Data</h2>
            <button class="icon-btn modal__close" data-close="clear-all-modal" aria-label="Close">✕</button>
        </div>
        <div class="modal__body">
            <p style="font-size:14px;color:var(--text-muted);line-height:1.6">
                This will clear <strong>all locally stored data</strong> including:
            </p>
            <ul style="font-size:13px;color:var(--text-muted);margin:10px 0 0 16px;line-height:2">
                <li>Cached messages and media</li>
                <li>Your E2E private key (IndexedDB)</li>
                <li>All app settings and preferences</li>
                <li>Service worker cache</li>
            </ul>
            <p style="font-size:13px;color:var(--text-danger);margin-top:12px;font-weight:600">
                Your account and server data will NOT be deleted.
            </p>
        </div>
        <div class="modal__footer">
            <button class="btn btn--ghost" data-close="clear-all-modal">Cancel</button>
            <button class="btn btn--danger" id="btn-clear-all-confirm">Clear Everything</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ════════════════════════════════════════════════════════
     CONFIG + SCRIPTS
════════════════════════════════════════════════════════ -->
<script>
    window.NAMAK_CONFIG = {
        userId:  <?= (int) $myId ?>,
        version: <?= json_encode($version) ?>,
        api:     '/api/v1',
    };
</script>

<script src="/assets/js/modules/storage.js"></script>
<script src="/assets/js/modules/api.js"></script>
<script src="/assets/js/modules/ui.js"></script>

<script>
    (function () {
        'use strict';

        const STORE  = window.localStorage;
        const API    = '/api/v1';
        const KEYS   = {
            theme:       'namak_theme',
            accent:      'namak_accent',
            fontSize:    'namak_font_size',
            bubble:      'namak_bubble',
            chatBg:      'namak_chat_bg',
            lang:        'namak_lang',

            notifDesktop:'namak_notif_desktop',
            notifSound:  'namak_notif_sound',
            notifSoundSel:'namak_notif_sound_sel',
            notifPreview:'namak_notif_preview',
            notifBadge:  'namak_notif_badge',
            dnd:         'namak_dnd',
            dndStart:    'namak_dnd_start',
            dndEnd:      'namak_dnd_end',

            enterSend:   'namak_enter_send',
            readReceipts:'namak_read_receipts',
            typingInd:   'namak_typing_ind',
            linkPreview: 'namak_link_preview',
            autoDlImg:   'namak_auto_dl_images',
            autoDlVid:   'namak_auto_dl_videos',
            autoDlFile:  'namak_auto_dl_files',
            autoDlVoice: 'namak_auto_dl_voice',
            groupMsg:    'namak_group_messages',
            largeEmoji:  'namak_large_emoji',
            spellCheck:  'namak_spell_check',
            pollInterval:'namak_poll_interval',

            reduceMotion:'namak_reduce_motion',
            highContrast:'namak_high_contrast',
            largeTargets:'namak_large_targets',
            focusRing:   'namak_focus_ring',
            screenReader:'namak_screen_reader',
        };

        let hasUnsaved = false;

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
        document.querySelectorAll('[data-close]').forEach(b =>
            b.addEventListener('click', () => closeModal(b.dataset.close)));
        document.querySelectorAll('.modal-overlay').forEach(o =>
            o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); }));
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape')
                document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => closeModal(m.id));
        });

        // ── Mark unsaved ─────────────────────────────────────────────────────────
        function markUnsaved() {
            hasUnsaved = true;
            document.getElementById('settings-save-bar').hidden = false;
        }

        // ── Settings navigation ───────────────────────────────────────────────────
        document.querySelectorAll('.settings-nav__item').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                document.querySelectorAll('.settings-nav__item').forEach(l => {
                    l.classList.remove('active');
                    l.setAttribute('aria-selected', 'false');
                });
                link.classList.add('active');
                link.setAttribute('aria-selected', 'true');

                const sectionId = link.dataset.section;
                document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
                const target = document.getElementById('section-' + sectionId);
                if (target) {
                    target.classList.add('active');
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Also activate section via hash
        if (location.hash) {
            const link = document.querySelector(`.settings-nav__item[href="${location.hash}"]`);
            if (link) link.click();
        }

        // ════════════════════════════════════════════
        // LOAD SAVED SETTINGS
        // ════════════════════════════════════════════
        function loadAll() {

            // Theme
            const theme = STORE.getItem(KEYS.theme) || 'light';
            document.querySelectorAll('input[name="theme"]').forEach(r => {
                r.checked = r.value === theme;
                r.closest('.theme-option')?.classList.toggle('active', r.checked);
            });

            // Accent
            const accent = STORE.getItem(KEYS.accent) || 'blue';
            document.querySelectorAll('input[name="accent"]').forEach(r => {
                r.checked = r.value === accent;
                r.closest('.color-swatch')?.classList.toggle('active', r.checked);
            });

            // Font size
            const fs = parseInt(STORE.getItem(KEYS.fontSize) || '14', 10);
            document.getElementById('font-size-val').textContent = fs + 'px';
            document.documentElement.style.setProperty('--msg-font-size', fs + 'px');

            // Bubble style
            const bubble = STORE.getItem(KEYS.bubble) || 'rounded';
            document.getElementById('bubble-style').value = bubble;

            // Chat background
            const bg = STORE.getItem(KEYS.chatBg) || 'none';
            document.querySelectorAll('input[name="chat-bg"]').forEach(r => {
                r.checked = r.value === bg;
                r.closest('.bg-option')?.classList.toggle('active', r.checked);
            });

            // Language
            const lang = STORE.getItem(KEYS.lang) || 'en';
            document.getElementById('app-language').value = lang;

            // Notifications
            loadToggle('notif-desktop', KEYS.notifDesktop, true);
            loadToggle('notif-sound',   KEYS.notifSound,   true);
            loadToggle('notif-preview', KEYS.notifPreview, true);
            loadToggle('notif-badge',   KEYS.notifBadge,   true);
            loadToggle('notif-dnd',     KEYS.dnd,          false);
            document.getElementById('notif-sound-select').value =
                STORE.getItem(KEYS.notifSoundSel) || 'default';
            const dndStart = STORE.getItem(KEYS.dndStart) || '22:00';
            const dndEnd   = STORE.getItem(KEYS.dndEnd)   || '08:00';
            document.getElementById('dnd-start').value = dndStart;
            document.getElementById('dnd-end').value   = dndEnd;
            document.getElementById('dnd-schedule-row').hidden =
                !document.getElementById('notif-dnd').checked;

            // Chat
            loadToggle('enter-to-send',  KEYS.enterSend,   true);
            loadToggle('read-receipts',  KEYS.readReceipts, true);
            loadToggle('typing-indicator', KEYS.typingInd, true);
            loadToggle('link-preview',   KEYS.linkPreview,  true);
            loadToggle('group-messages', KEYS.groupMsg,     true);
            loadToggle('large-emoji',    KEYS.largeEmoji,   true);
            loadToggle('spell-check',    KEYS.spellCheck,   true);
            document.getElementById('auto-dl-images').checked =
                STORE.getItem(KEYS.autoDlImg)   !== 'false';
            document.getElementById('auto-dl-videos').checked =
                STORE.getItem(KEYS.autoDlVid)   === 'true';
            document.getElementById('auto-dl-files').checked  =
                STORE.getItem(KEYS.autoDlFile)  === 'true';
            document.getElementById('auto-dl-voice').checked  =
                STORE.getItem(KEYS.autoDlVoice) !== 'false';
            document.getElementById('poll-interval').value =
                STORE.getItem(KEYS.pollInterval) || '2000';

            // Accessibility
            loadToggle('reduce-motion', KEYS.reduceMotion, false);
            loadToggle('high-contrast', KEYS.highContrast, false);
            loadToggle('large-targets', KEYS.largeTargets, false);
            loadToggle('focus-ring',    KEYS.focusRing,    false);
            loadToggle('screen-reader', KEYS.screenReader, false);

            // Apply
            applyAll();
        }

        function loadToggle(id, key, defaultVal) {
            const stored = STORE.getItem(key);
            const val = stored !== null ? stored === 'true' : defaultVal;
            document.getElementById(id).checked = val;
        }

        // ════════════════════════════════════════════
        // APPLY SETTINGS TO DOM
        // ════════════════════════════════════════════
        function applyAll() {
            // Theme
            const theme = STORE.getItem(KEYS.theme) || 'light';
            const resolved = theme === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme;
            document.documentElement.setAttribute('data-theme', resolved);

            // Accent color
            const accentMap = {
                blue:   '#2196f3', teal: '#009688', green: '#4caf50',
                purple: '#9c27b0', pink: '#e91e63', orange: '#ff9800',
            };
            const accent = STORE.getItem(KEYS.accent) || 'blue';
            document.documentElement.style.setProperty('--primary', accentMap[accent] || accentMap.blue);

            // Font size
            const fs = parseInt(STORE.getItem(KEYS.fontSize) || '14', 10);
            document.documentElement.style.setProperty('--msg-font-size', fs + 'px');

            // Bubble style
            document.documentElement.dataset.bubble = STORE.getItem(KEYS.bubble) || 'rounded';

            // Chat background
            document.documentElement.dataset.chatBg = STORE.getItem(KEYS.chatBg) || 'none';

            // Reduce motion
            if (STORE.getItem(KEYS.reduceMotion) === 'true') {
                document.documentElement.classList.add('reduce-motion');
            } else {
                document.documentElement.classList.remove('reduce-motion');
            }

            // High contrast
            if (STORE.getItem(KEYS.highContrast) === 'true') {
                document.documentElement.classList.add('high-contrast');
            } else {
                document.documentElement.classList.remove('high-contrast');
            }

            // Focus ring
            if (STORE.getItem(KEYS.focusRing) === 'true') {
                document.documentElement.classList.add('focus-always-visible');
            } else {
                document.documentElement.classList.remove('focus-always-visible');
            }

            // Large targets
            if (STORE.getItem(KEYS.largeTargets) === 'true') {
                document.documentElement.classList.add('large-targets');
            } else {
                document.documentElement.classList.remove('large-targets');
            }
        }

        // ════════════════════════════════════════════
        // BIND CONTROLS
        // ════════════════════════════════════════════

        // ── Theme radio ──────────────────────────────────────────────────────────
        document.querySelectorAll('input[name="theme"]').forEach(r => {
            r.addEventListener('change', () => {
                STORE.setItem(KEYS.theme, r.value);
                document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
                r.closest('.theme-option')?.classList.add('active');
                applyAll();
                markUnsaved();
            });
        });

        // ── Accent color ─────────────────────────────────────────────────────────
        document.querySelectorAll('input[name="accent"]').forEach(r => {
            r.addEventListener('change', () => {
                STORE.setItem(KEYS.accent, r.value);
                document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
                r.closest('.color-swatch')?.classList.add('active');
                applyAll();
                markUnsaved();
            });
        });

        // ── Font size ────────────────────────────────────────────────────────────
        let fontSize = parseInt(STORE.getItem(KEYS.fontSize) || '14', 10);
        document.getElementById('btn-font-dec').addEventListener('click', () => {
            if (fontSize <= 10) return;
            fontSize--;
            STORE.setItem(KEYS.fontSize, String(fontSize));
            document.getElementById('font-size-val').textContent = fontSize + 'px';
            document.documentElement.style.setProperty('--msg-font-size', fontSize + 'px');
            markUnsaved();
        });
        document.getElementById('btn-font-inc').addEventListener('click', () => {
            if (fontSize >= 22) return;
            fontSize++;
            STORE.setItem(KEYS.fontSize, String(fontSize));
            document.getElementById('font-size-val').textContent = fontSize + 'px';
            document.documentElement.style.setProperty('--msg-font-size', fontSize + 'px');
            markUnsaved();
        });

        // ── Bubble style ─────────────────────────────────────────────────────────
        document.getElementById('bubble-style').addEventListener('change', function () {
            STORE.setItem(KEYS.bubble, this.value);
            applyAll();
            markUnsaved();
        });

        // ── Chat background ───────────────────────────────────────────────────────
        document.querySelectorAll('input[name="chat-bg"]').forEach(r => {
            r.addEventListener('change', () => {
                STORE.setItem(KEYS.chatBg, r.value);
                document.querySelectorAll('.bg-option').forEach(o => o.classList.remove('active'));
                r.closest('.bg-option')?.classList.add('active');
                applyAll();
                markUnsaved();
            });
        });

        // ── Language ─────────────────────────────────────────────────────────────
        document.getElementById('app-language').addEventListener('change', function () {
            document.cookie = `lang=${this.value}; path=/; max-age=${30 * 86400}; SameSite=Strict`;
            STORE.setItem(KEYS.lang, this.value);
            toast('Language changed. Reloading…', 'info', 1500);
            setTimeout(() => window.location.reload(), 1500);
        });

        // ── Toggle helpers ────────────────────────────────────────────────────────
        function bindToggle(id, key, onChange) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', () => {
                STORE.setItem(key, String(el.checked));
                markUnsaved();
                if (onChange) onChange(el.checked);
            });
        }

        // ── Notifications ────────────────────────────────────────────────────────
        bindToggle('notif-desktop', KEYS.notifDesktop, async checked => {
            if (checked && 'Notification' in window) {
                if (Notification.permission === 'default') {
                    const perm = await Notification.requestPermission();
                    if (perm !== 'granted') {
                        document.getElementById('notif-desktop').checked = false;
                        STORE.setItem(KEYS.notifDesktop, 'false');
                        toast('Notification permission denied.', 'error');
                    }
                } else if (Notification.permission === 'denied') {
                    document.getElementById('notif-desktop').checked = false;
                    STORE.setItem(KEYS.notifDesktop, 'false');
                    toast('Please allow notifications in your browser settings.', 'error', 5000);
                }
            }
        });

        // Show "Allow" button if permission not yet granted
        if ('Notification' in window && Notification.permission === 'default') {
            document.getElementById('btn-notif-request').hidden = false;
            document.getElementById('btn-notif-request').addEventListener('click', async () => {
                const perm = await Notification.requestPermission();
                if (perm === 'granted') {
                    document.getElementById('notif-desktop').checked = true;
                    STORE.setItem(KEYS.notifDesktop, 'true');
                    document.getElementById('btn-notif-request').hidden = true;
                    toast('✓ Notifications enabled.', 'success');
                }
            });
        }

        bindToggle('notif-sound',   KEYS.notifSound);
        bindToggle('notif-preview', KEYS.notifPreview);
        bindToggle('notif-badge',   KEYS.notifBadge);

        bindToggle('notif-dnd', KEYS.dnd, checked => {
            document.getElementById('dnd-schedule-row').hidden = !checked;
        });

        document.getElementById('notif-sound-select').addEventListener('change', function () {
            STORE.setItem(KEYS.notifSoundSel, this.value);
            markUnsaved();
        });

        // Preview sound
        document.getElementById('btn-preview-sound').addEventListener('click', () => {
            const sel = document.getElementById('notif-sound-select').value;
            if (sel === 'none') return;
            const ctx   = new (window.AudioContext || window.webkitAudioContext)();
            const osc   = ctx.createOscillator();
            const gain  = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            const freqs = { default: 880, ping: 1200, pop: 600, chime: 1400 };
            osc.frequency.value = freqs[sel] || 880;
            osc.type = sel === 'chime' ? 'sine' : 'triangle';
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        });

        document.getElementById('dnd-start').addEventListener('change', function () {
            STORE.setItem(KEYS.dndStart, this.value); markUnsaved();
        });
        document.getElementById('dnd-end').addEventListener('change', function () {
            STORE.setItem(KEYS.dndEnd, this.value); markUnsaved();
        });

        // ── Chat settings ─────────────────────────────────────────────────────────
        bindToggle('enter-to-send',    KEYS.enterSend);
        bindToggle('read-receipts',    KEYS.readReceipts);
        bindToggle('typing-indicator', KEYS.typingInd);
        bindToggle('link-preview',     KEYS.linkPreview);
        bindToggle('group-messages',   KEYS.groupMsg);
        bindToggle('large-emoji',      KEYS.largeEmoji);
        bindToggle('spell-check',      KEYS.spellCheck, checked => {
            const composer = document.querySelector('#msg-composer');
            if (composer) composer.spellcheck = checked;
        });

        document.querySelectorAll('.auto-dl-opt').forEach(cb => {
            cb.addEventListener('change', () => {
                const keyMap = {
                    'auto-dl-images': KEYS.autoDlImg,
                    'auto-dl-videos': KEYS.autoDlVid,
                    'auto-dl-files':  KEYS.autoDlFile,
                    'auto-dl-voice':  KEYS.autoDlVoice,
                };
                STORE.setItem(keyMap[cb.id], String(cb.checked));
                markUnsaved();
            });
        });

        document.getElementById('poll-interval').addEventListener('change', function () {
            STORE.setItem(KEYS.pollInterval, this.value);
            markUnsaved();
        });

        // ── Accessibility ─────────────────────────────────────────────────────────
        bindToggle('reduce-motion', KEYS.reduceMotion, () => applyAll());
        bindToggle('high-contrast', KEYS.highContrast, () => applyAll());
        bindToggle('large-targets', KEYS.largeTargets, () => applyAll());
        bindToggle('focus-ring',    KEYS.focusRing,    () => applyAll());
        bindToggle('screen-reader', KEYS.screenReader);

        // ── Save / Discard bar ────────────────────────────────────────────────────
        document.getElementById('btn-save-settings').addEventListener('click', async () => {
            const btn = document.getElementById('btn-save-settings');
            btn.disabled = true;
            btn.textContent = 'Saving…';

            // Sync server-side prefs (read receipts, typing indicator)
            try {
                await fetch(`${API}/users/privacy`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include',
                    body: JSON.stringify({
                        show_read_receipts: document.getElementById('read-receipts').checked,
                        show_typing:        document.getElementById('typing-indicator').checked,
                    }),
                });
            } catch { /* non-critical */ }

            toast('✓ Settings saved.', 'success');
            hasUnsaved = false;
            document.getElementById('settings-save-bar').hidden = true;
            btn.disabled    = false;
            btn.textContent = 'Save Changes';
        });

        document.getElementById('btn-discard-settings').addEventListener('click', () => {
            loadAll();
            hasUnsaved = false;
            document.getElementById('settings-save-bar').hidden = true;
            toast('Changes discarded.', 'info');
        });

        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', e => {
            if (hasUnsaved) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // ════════════════════════════════════════════
        // STORAGE SECTION
        // ════════════════════════════════════════════

        function formatBytes(bytes) {
            if (bytes < 1024)         return bytes + ' B';
            if (bytes < 1048576)      return (bytes / 1024).toFixed(1) + ' KB';
            if (bytes < 1073741824)   return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 1073741824).toFixed(2) + ' GB';
        }

        async function measureStorage() {
            let lsBytes = 0, idbBytes = 0, cacheBytes = 0;

            // localStorage
            for (let i = 0; i < STORE.length; i++) {
                const k = STORE.key(i);
                lsBytes += (k.length + (STORE.getItem(k) || '').length) * 2;
            }

            // Cache API (service worker cache)
            if ('caches' in window) {
                try {
                    const names = await caches.keys();
                    for (const name of names) {
                        const cache = await caches.open(name);
                        const reqs  = await cache.keys();
                        for (const req of reqs) {
                            const res  = await cache.match(req);
                            const blob = await res?.blob();
                            cacheBytes += blob?.size || 0;
                        }
                    }
                } catch {}
            }

            // Storage API estimate
            let total = 0, quota = 0;
            if (navigator.storage?.estimate) {
                const est = await navigator.storage.estimate();
                total = est.usage || 0;
                quota = est.quota || 0;
            }

            idbBytes = Math.max(0, total - lsBytes - cacheBytes);

            const pct = quota > 0 ? Math.min(100, (total / quota * 100)) : 0;
            document.getElementById('storage-bar-fill').style.width = pct + '%';
            document.getElementById('storage-bar').setAttribute('aria-valuenow', String(Math.round(pct)));

            document.getElementById('sz-media').textContent    = formatBytes(cacheBytes);
            document.getElementById('sz-messages').textContent = formatBytes(idbBytes);
            document.getElementById('sz-other').textContent    = formatBytes(lsBytes);
            document.getElementById('sz-total').innerHTML      = `<strong>${formatBytes(total)}</strong>`;
        }

        measureStorage();

        // Clear cache
        document.getElementById('btn-clear-cache').addEventListener('click', async () => {
            if ('caches' in window) {
                const names = await caches.keys();
                await Promise.all(names.map(n => caches.delete(n)));
            }
            toast('✓ Cache cleared.', 'success');
            measureStorage();
        });

        // Clear local messages
        document.getElementById('btn-clear-messages').addEventListener('click', async () => {
            const req = indexedDB.open('namak_messages', 1);
            req.onsuccess = e => {
                const db = e.target.result;
                const stores = Array.from(db.objectStoreNames);
                const tx = db.transaction(stores, 'readwrite');
                stores.forEach(s => tx.objectStore(s).clear());
                tx.oncomplete = () => {
                    toast('✓ Message cache cleared.', 'success');
                    measureStorage();
                };
            };
            req.onerror = () => toast('Could not clear messages.', 'error');
        });

        // Clear ALL local data
        document.getElementById('btn-clear-all').addEventListener('click', () => openModal('clear-all-modal'));
        document.getElementById('btn-clear-all-confirm').addEventListener('click', async () => {
            closeModal('clear-all-modal');
            // Clear localStorage + sessionStorage
            localStorage.clear();
            sessionStorage.clear();
            // Clear IndexedDB
            const dbs = ['namak_messages', 'namak_keys', 'namak_chats'];
            for (const name of dbs) {
                await new Promise(res => {
                    const d = indexedDB.deleteDatabase(name);
                    d.onsuccess = d.onerror = res;
                });
            }
            // Clear service worker cache
            if ('caches' in window) {
                const names = await caches.keys();
                await Promise.all(names.map(n => caches.delete(n)));
            }
            // Unregister service worker
            if ('serviceWorker' in navigator) {
                const regs = await navigator.serviceWorker.getRegistrations();
                await Promise.all(regs.map(r => r.unregister()));
            }
            toast('All local data cleared. Redirecting…', 'info', 2000);
            setTimeout(() => window.location.href = '/auth/login', 2000);
        });

        // Export data
        document.getElementById('btn-export-data').addEventListener('click', async () => {
            const btn = document.getElementById('btn-export-data');
            btn.disabled = true;
            btn.textContent = 'Exporting…';
            try {
                const res  = await fetch(`${API}/users/export`, {
                    credentials: 'include',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const blob = await res.blob();
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = 'namak-data-export.json';
                a.click();
                URL.revokeObjectURL(url);
                toast('✓ Data exported.', 'success');
            } catch {
                toast('Export failed.', 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = 'Export';
            }
        });

        // ════════════════════════════════════════════
        // ABOUT SECTION
        // ════════════════════════════════════════════

        document.getElementById('btn-check-update').addEventListener('click', async () => {
            const btn = document.getElementById('btn-check-update');
            btn.disabled = true;
            btn.textContent = 'Checking…';
            try {
                const res  = await fetch(`${API}/system/version`, {
                    credentials: 'include',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.latest && data.latest !== window.NAMAK_CONFIG.version) {
                    toast(`New version available: ${data.latest}`, 'info', 6000);
                } else {
                    toast('✓ You are on the latest version.', 'success');
                }
            } catch {
                toast('Could not check for updates.', 'error');
            } finally {
                btn.disabled    = false;
                btn.textContent = 'Check for updates';
            }
        });

        document.getElementById('btn-changelog').addEventListener('click', async () => {
            openModal('changelog-modal');
            const body = document.getElementById('changelog-body');
            body.innerHTML = '<p class="text-muted" style="font-size:13px">Loading…</p>';
            try {
                const res  = await fetch('/CHANGELOG.md');
                if (!res.ok) throw new Error();
                const text = await res.text();
                // Minimal MD→HTML for changelog (headings + bullets only)
                const html = text
                    .replace(/^### (.+)$/gm, '<h4 style="margin:12px 0 4px;font-size:13px">$1</h4>')
                    .replace(/^## (.+)$/gm,  '<h3 style="margin:16px 0 6px;font-size:14px;font-weight:600">$1</h3>')
                    .replace(/^# (.+)$/gm,   '<h2 style="font-size:16px;font-weight:700;margin-bottom:8px">$1</h2>')
                    .replace(/^\- (.+)$/gm,  '<li style="font-size:13px;color:var(--text-muted);margin:2px 0">$1</li>')
                    .replace(/(<li>[\s\S]*?<\/li>)/gm, '<ul style="padding-left:16px">$1</ul>');
                body.innerHTML = html;
            } catch {
                body.innerHTML = '<p class="text-muted" style="font-size:13px">Could not load changelog.</p>';
            }
        });

        document.getElementById('btn-licenses').addEventListener('click', () => openModal('licenses-modal'));

        // Debug info
        (function buildDebugInfo() {
            const info = [
                `App: ${window.NAMAK_CONFIG.version}`,
                `UA: ${navigator.userAgent.slice(0, 60)}`,
                `Lang: ${navigator.language}`,
                `Theme: ${STORE.getItem(KEYS.theme) || 'light'}`,
                `SW: ${'serviceWorker' in navigator ? 'supported' : 'no'}`,
                `IDB: ${'indexedDB' in window ? 'supported' : 'no'}`,
            ].join('\n');
            document.getElementById('debug-info-text').textContent = info;

            document.getElementById('btn-copy-debug').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(info);
                    toast('✓ Debug info copied.', 'success');
                } catch {
                    toast('Copy failed.', 'error');
                }
            });
        })();

        // ── Service Worker ────────────────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/assets/js/service-worker.js', { scope: '/' })
                .catch(e => console.warn('[SW]', e));
        }

        // ── Init ──────────────────────────────────────────────────────────────────
        loadAll();

    })();
</script>

</body>
</html>

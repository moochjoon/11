<?php
declare(strict_types=1);

/**
 * Register Page
 * Namak Messenger — pages/auth/register.php
 *
 * Multi-step registration (3 steps):
 *  Step 1 — Account info   (username, email, phone)
 *  Step 2 — Security       (password + confirm)
 *  Step 3 — Profile        (first_name, last_name, optional avatar preview)
 *
 * - Zero external dependencies
 * - E2E key pair: private key stored in IndexedDB ONLY (shown once warning)
 * - Telegram-style UI
 */

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use Namak\Services\Auth;
use Namak\Core\Request;

$request = new Request();
$auth    = new Auth();

// Already logged in → redirect
$token = $_COOKIE['access_token'] ?? $request->getBearerToken();
if ($token && $auth->validateToken($token)) {
    header('Location: /app/chat');
    exit;
}

$lang    = $_COOKIE['lang'] ?? 'en';
$dir     = $lang === 'fa' ? 'rtl' : 'ltr';
$i18n    = require BASE_PATH . '/lib/i18n/' . (in_array($lang, ['en','fa']) ? $lang : 'en') . '.php';
$appName = $_ENV['APP_NAME'] ?? 'Namak';
$appLogo = $_ENV['APP_LOGO'] ?? null;

// Check if registration is enabled
$registrationEnabled = ($_ENV['REGISTRATION_ENABLED'] ?? 'true') === 'true';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#2196f3">
    <meta name="description" content="<?= htmlspecialchars($appName) ?> — Create Account">
    <meta name="robots" content="noindex, nofollow">

    <title><?= htmlspecialchars($appName) ?> — Create Account</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/auth.css">

    <style>
        :root {
            --auth-bg:         #f0f2f5;
            --auth-card-bg:    #ffffff;
            --auth-primary:    #2196f3;
            --auth-primary-h:  #1976d2;
            --auth-text:       #111827;
            --auth-text-muted: #6b7280;
            --auth-border:     #e5e7eb;
            --auth-input-bg:   #f9fafb;
            --auth-error:      #ef4444;
            --auth-success:    #22c55e;
            --auth-radius:     12px;
            --auth-shadow:     0 4px 24px rgba(0,0,0,.08);
        }
        [data-theme="dark"] {
            --auth-bg:         #0f172a;
            --auth-card-bg:    #1e293b;
            --auth-text:       #f1f5f9;
            --auth-text-muted: #94a3b8;
            --auth-border:     #334155;
            --auth-input-bg:   #0f172a;
        }

        /* ── Step progress bar ── */
        .steps-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 28px;
        }
        .step-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--auth-border);
            color: var(--auth-text-muted);
            font-size: 13px; font-weight: 600;
            display: flex; align-items: center; justify-content: center;
            transition: background .25s, color .25s;
            position: relative; z-index: 1;
        }
        .step-dot.active  { background: var(--auth-primary); color: #fff; }
        .step-dot.done    { background: var(--auth-success);  color: #fff; }
        .step-connector {
            flex: 1; height: 3px; max-width: 56px;
            background: var(--auth-border);
            transition: background .25s;
        }
        .step-connector.done { background: var(--auth-success); }

        /* ── Step panels ── */
        .step-panel { display: none; }
        .step-panel.active { display: block; }

        /* ── Password strength ── */
        .pwd-strength {
            margin-top: 6px;
            display: flex; gap: 4px; align-items: center;
        }
        .pwd-strength__bar {
            flex: 1; height: 4px; border-radius: 2px;
            background: var(--auth-border);
            transition: background .3s;
        }
        .pwd-strength__label {
            font-size: 11px; color: var(--auth-text-muted);
            min-width: 50px; text-align: end;
        }

        /* ── Avatar picker ── */
        .avatar-picker {
            display: flex; flex-direction: column;
            align-items: center; gap: 12px; margin-bottom: 20px;
        }
        .avatar-picker__preview {
            width: 88px; height: 88px; border-radius: 50%;
            background: var(--auth-border);
            overflow: hidden; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            border: 3px dashed var(--auth-primary);
            transition: border-color .2s;
            position: relative;
        }
        .avatar-picker__preview:hover { border-color: var(--auth-primary-h); }
        .avatar-picker__preview img {
            width: 100%; height: 100%; object-fit: cover; display: none;
        }
        .avatar-picker__preview .avatar-placeholder {
            font-size: 32px; user-select: none; color: var(--auth-text-muted);
        }
        .avatar-picker__hint {
            font-size: 12px; color: var(--auth-text-muted); text-align: center;
        }

        /* ── Private key warning box ── */
        .key-warning {
            background: #fff7ed; border: 1.5px solid #fb923c;
            border-radius: 10px; padding: 14px 16px;
            margin-bottom: 16px; font-size: 13px;
            color: #92400e; display: flex; gap: 10px;
            line-height: 1.5;
        }
        [data-theme="dark"] .key-warning {
            background: #431407; border-color: #c2410c; color: #fed7aa;
        }
        .key-warning__icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

        /* ── Username availability indicator ── */
        .username-status {
            font-size: 12px; margin-top: 4px; display: flex;
            align-items: center; gap: 4px; min-height: 18px;
        }
        .username-status.checking { color: var(--auth-text-muted); }
        .username-status.available { color: var(--auth-success); }
        .username-status.taken     { color: var(--auth-error); }
    </style>
</head>
<body class="auth-page" data-theme="light">

<!-- ══════════════════════════════════════════════════════
     TOP BAR
══════════════════════════════════════════════════════ -->
<header class="auth-topbar">
    <div class="auth-topbar__brand">
        <?php if ($appLogo): ?>
            <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>" class="auth-topbar__logo">
        <?php else: ?>
            <span class="auth-topbar__icon">✉</span>
        <?php endif; ?>
        <span class="auth-topbar__name"><?= htmlspecialchars($appName) ?></span>
    </div>
    <div class="auth-topbar__actions">
        <button class="icon-btn" id="btn-lang" title="Switch language" aria-label="Switch language">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
        </button>
        <button class="icon-btn" id="btn-theme" title="Toggle theme" aria-label="Toggle theme">
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </div>
</header>

<!-- ══════════════════════════════════════════════════════
     REGISTRATION DISABLED
══════════════════════════════════════════════════════ -->
<?php if (!$registrationEnabled): ?>
    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-card__hero">
                <div class="auth-card__app-icon">
                    <svg viewBox="0 0 48 48" fill="none">
                        <circle cx="24" cy="24" r="24" fill="#ef4444"/>
                        <path d="M16 16l16 16M32 16L16 32" stroke="#fff" stroke-width="3.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <h1 class="auth-card__title">Registration Disabled</h1>
                <p class="auth-card__subtitle">New registrations are currently disabled by the administrator.</p>
            </div>
            <a href="/auth/login" class="btn btn--primary btn--full">Back to Sign In</a>
        </div>
    </main>
<?php else: ?>

    <!-- ══════════════════════════════════════════════════════
         MAIN CARD
    ══════════════════════════════════════════════════════ -->
    <main class="auth-main">
        <div class="auth-card auth-card--wide" role="main">

            <!-- Hero -->
            <div class="auth-card__hero">
                <?php if ($appLogo): ?>
                    <img src="<?= htmlspecialchars($appLogo) ?>" alt="" class="auth-card__app-logo">
                <?php else: ?>
                    <div class="auth-card__app-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <circle cx="24" cy="24" r="24" fill="var(--auth-primary)"/>
                            <path d="M14 24h20M24 14l10 10-10 10" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <h1 class="auth-card__title">Create Account</h1>
                <p class="auth-card__subtitle">Join <?= htmlspecialchars($appName) ?> in seconds</p>
            </div>

            <!-- Global error -->
            <div class="alert alert--error" id="global-error" role="alert" aria-live="polite" hidden>
                <svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span id="global-error-text"></span>
            </div>

            <!-- ── Step progress ── -->
            <div class="steps-bar" role="progressbar" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1" id="steps-bar">
                <div class="step-dot active" id="dot-1" aria-label="Step 1: Account">1</div>
                <div class="step-connector" id="conn-1"></div>
                <div class="step-dot" id="dot-2" aria-label="Step 2: Security">2</div>
                <div class="step-connector" id="conn-2"></div>
                <div class="step-dot" id="dot-3" aria-label="Step 3: Profile">3</div>
            </div>

            <form id="register-form" novalidate autocomplete="off" spellcheck="false">

                <!-- ════════════════════════════════════════
                     STEP 1 — Account
                ════════════════════════════════════════ -->
                <div class="step-panel active" id="step-1" aria-label="Step 1: Account information">

                    <!-- Username -->
                    <div class="form-group" id="group-username">
                        <label class="form-label" for="username">
                            Username <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/></svg>
                        </span>
                            <span class="form-input-prefix">@</span>
                            <input type="text" id="username" name="username"
                                   class="form-input form-input--prefix"
                                   placeholder="your_username"
                                   autocomplete="username"
                                   autocorrect="off" autocapitalize="none"
                                   spellcheck="false" minlength="3" maxlength="32"
                                   pattern="[a-zA-Z0-9_]+"
                                   required aria-describedby="username-err">
                        </div>
                        <div class="username-status" id="username-status" aria-live="polite"></div>
                        <span class="form-error" id="username-err" role="alert" aria-live="polite"></span>
                        <span class="form-hint">3–32 characters. Letters, numbers, underscores only.</span>
                    </div>

                    <!-- Email -->
                    <div class="form-group" id="group-email">
                        <label class="form-label" for="email">
                            Email <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                        </span>
                            <input type="email" id="email" name="email"
                                   class="form-input"
                                   placeholder="you@example.com"
                                   autocomplete="email"
                                   maxlength="255"
                                   required aria-describedby="email-err">
                        </div>
                        <span class="form-error" id="email-err" role="alert" aria-live="polite"></span>
                    </div>

                    <!-- Phone -->
                    <div class="form-group" id="group-phone">
                        <label class="form-label" for="phone">
                            Phone Number <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/></svg>
                        </span>
                            <input type="tel" id="phone" name="phone"
                                   class="form-input"
                                   placeholder="+1 234 567 8900"
                                   autocomplete="tel"
                                   maxlength="20"
                                   required aria-describedby="phone-err">
                        </div>
                        <span class="form-error" id="phone-err" role="alert" aria-live="polite"></span>
                        <span class="form-hint">Include country code (e.g. +98 for Iran).</span>
                    </div>

                    <button type="button" class="btn btn--primary btn--full" id="btn-step1">
                        Continue
                        <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-right">
                            <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>

                </div><!-- /step-1 -->

                <!-- ════════════════════════════════════════
                     STEP 2 — Security
                ════════════════════════════════════════ -->
                <div class="step-panel" id="step-2" aria-label="Step 2: Set password">

                    <!-- Password -->
                    <div class="form-group" id="group-password">
                        <label class="form-label" for="password">
                            Password <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                        </span>
                            <input type="password" id="password" name="password"
                                   class="form-input"
                                   placeholder="Create a strong password"
                                   autocomplete="new-password"
                                   minlength="8" maxlength="128"
                                   required aria-describedby="password-err">
                            <button type="button" class="form-input-toggle" id="toggle-pwd1" tabindex="-1" aria-label="Toggle password visibility">
                                <svg class="eye-show" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                <svg class="eye-hide" viewBox="0 0 20 20" fill="currentColor" style="display:none"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.064 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>
                            </button>
                        </div>
                        <!-- Strength bars -->
                        <div class="pwd-strength" id="pwd-strength" aria-live="polite">
                            <div class="pwd-strength__bar" id="s1"></div>
                            <div class="pwd-strength__bar" id="s2"></div>
                            <div class="pwd-strength__bar" id="s3"></div>
                            <div class="pwd-strength__bar" id="s4"></div>
                            <span class="pwd-strength__label" id="s-label"></span>
                        </div>
                        <span class="form-error" id="password-err" role="alert" aria-live="polite"></span>
                        <span class="form-hint">Min 8 characters. Use letters, numbers and symbols.</span>
                    </div>

                    <!-- Confirm password -->
                    <div class="form-group" id="group-confirm">
                        <label class="form-label" for="confirm">
                            Confirm Password <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        </span>
                            <input type="password" id="confirm" name="confirm"
                                   class="form-input"
                                   placeholder="Repeat your password"
                                   autocomplete="new-password"
                                   maxlength="128"
                                   required aria-describedby="confirm-err">
                            <button type="button" class="form-input-toggle" id="toggle-pwd2" tabindex="-1" aria-label="Toggle confirm password visibility">
                                <svg class="eye-show" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                <svg class="eye-hide" viewBox="0 0 20 20" fill="currentColor" style="display:none"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.064 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>
                            </button>
                        </div>
                        <span class="form-error" id="confirm-err" role="alert" aria-live="polite"></span>
                    </div>

                    <!-- E2E key warning -->
                    <div class="key-warning" role="note">
                        <span class="key-warning__icon">🔐</span>
                        <div>
                            <strong>End-to-End Encryption Key</strong><br>
                            A private encryption key will be generated for your account.
                            It will be shown <strong>once</strong> after registration.
                            We store it in your browser only — if you lose it,
                            secret chats cannot be recovered.
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="button" class="btn btn--ghost" id="btn-back1">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                                <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                            </svg>
                            Back
                        </button>
                        <button type="button" class="btn btn--primary btn--flex" id="btn-step2">
                            Continue
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-right">
                                <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>

                </div><!-- /step-2 -->

                <!-- ════════════════════════════════════════
                     STEP 3 — Profile
                ════════════════════════════════════════ -->
                <div class="step-panel" id="step-3" aria-label="Step 3: Your profile">

                    <!-- Avatar picker -->
                    <div class="avatar-picker">
                        <div class="avatar-picker__preview" id="avatar-preview"
                             role="button" tabindex="0" aria-label="Choose profile photo">
                            <img id="avatar-img" src="" alt="Preview">
                            <span class="avatar-placeholder" id="avatar-placeholder">📷</span>
                        </div>
                        <input type="file" id="avatar-file" name="avatar"
                               accept="image/jpeg,image/png,image/webp,image/gif"
                               style="display:none" aria-hidden="true">
                        <p class="avatar-picker__hint">
                            Tap to add a profile photo (optional)<br>
                            <small>JPEG, PNG, WebP, GIF — max 5 MB</small>
                        </p>
                    </div>

                    <!-- First name -->
                    <div class="form-group" id="group-first_name">
                        <label class="form-label" for="first_name">
                            First Name <span class="form-label__required">*</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                        </span>
                            <input type="text" id="first_name" name="first_name"
                                   class="form-input"
                                   placeholder="Your first name"
                                   autocomplete="given-name"
                                   maxlength="64"
                                   required aria-describedby="first_name-err">
                        </div>
                        <span class="form-error" id="first_name-err" role="alert" aria-live="polite"></span>
                    </div>

                    <!-- Last name -->
                    <div class="form-group" id="group-last_name">
                        <label class="form-label" for="last_name">
                            Last Name <span class="form-label__muted">(optional)</span>
                        </label>
                        <div class="form-input-wrap">
                        <span class="form-input-icon">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                        </span>
                            <input type="text" id="last_name" name="last_name"
                                   class="form-input"
                                   placeholder="Your last name"
                                   autocomplete="family-name"
                                   maxlength="64">
                        </div>
                    </div>

                    <!-- Terms -->
                    <div class="form-group" id="group-terms">
                        <label class="form-check">
                            <input type="checkbox" id="terms" name="terms"
                                   class="form-check__input" required aria-describedby="terms-err">
                            <span class="form-check__box"></span>
                            <span class="form-check__label">
                            I agree to the
                            <a href="/terms" class="link" target="_blank" rel="noopener">Terms of Service</a>
                            and
                            <a href="/privacy" class="link" target="_blank" rel="noopener">Privacy Policy</a>
                        </span>
                        </label>
                        <span class="form-error" id="terms-err" role="alert" aria-live="polite"></span>
                    </div>

                    <div class="btn-row">
                        <button type="button" class="btn btn--ghost" id="btn-back2">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="btn__icon-left">
                                <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                            </svg>
                            Back
                        </button>
                        <button type="submit" class="btn btn--primary btn--flex" id="btn-submit" aria-busy="false">
                            <span class="btn__text">Create Account</span>
                            <span class="btn__spinner" hidden aria-hidden="true">
                            <svg class="spinner" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4" stroke-dashoffset="10"/>
                            </svg>
                        </span>
                        </button>
                    </div>

                </div><!-- /step-3 -->

            </form>

            <!-- Divider + login link -->
            <div class="auth-divider"><span>or</span></div>
            <p class="auth-card__footer-text">
                Already have an account?
                <a href="/auth/login" class="link">Sign in</a>
            </p>

        </div><!-- /.auth-card -->
    </main>

    <!-- ══════════════════════════════════════════════════════
         PRIVATE KEY MODAL  (shown once after success)
    ══════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="key-modal" role="dialog" aria-modal="true"
         aria-label="Save your private key" hidden>
        <div class="modal modal--sm">
            <div class="modal__header">
                <h2 class="modal__title">🔐 Save Your Private Key</h2>
            </div>
            <div class="modal__body">
                <p style="font-size:13px;color:var(--auth-text-muted);margin-bottom:12px;line-height:1.6">
                    This key encrypts your secret chats. It is stored <strong>only in your browser</strong>
                    and <strong>cannot be recovered</strong> if lost. Copy and store it somewhere safe.
                </p>
                <div class="key-display" id="key-display"
                     style="font-family:monospace;font-size:11px;word-break:break-all;
                        background:var(--auth-input-bg);border:1px solid var(--auth-border);
                        border-radius:8px;padding:12px;max-height:120px;overflow-y:auto;
                        user-select:all;cursor:text"></div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn btn--ghost btn--sm" id="btn-copy-key">
                        📋 Copy Key
                    </button>
                    <button class="btn btn--ghost btn--sm" id="btn-download-key">
                        💾 Download
                    </button>
                </div>
                <label class="form-check" style="margin-top:14px">
                    <input type="checkbox" id="key-confirm-check" class="form-check__input">
                    <span class="form-check__box"></span>
                    <span class="form-check__label" style="font-size:13px">
                    I have saved my private key
                </span>
                </label>
            </div>
            <div class="modal__footer">
                <button class="btn btn--primary btn--full" id="btn-key-done" disabled>
                    Continue to Chat
                </button>
            </div>
        </div>
    </div>

    <!-- Language modal (same as login) -->
    <div class="modal-overlay" id="lang-modal" role="dialog" aria-modal="true" aria-label="Select language" hidden>
        <div class="modal modal--sm">
            <div class="modal__header">
                <h2 class="modal__title">Language</h2>
                <button class="icon-btn modal__close" id="lang-modal-close" aria-label="Close">✕</button>
            </div>
            <ul class="lang-list" role="listbox">
                <li class="lang-list__item <?= $lang === 'en' ? 'lang-list__item--active' : '' ?>"
                    role="option" data-lang="en" data-dir="ltr" tabindex="0">
                    <span class="lang-list__flag">🇬🇧</span>
                    <span class="lang-list__name">English</span>
                </li>
                <li class="lang-list__item <?= $lang === 'fa' ? 'lang-list__item--active' : '' ?>"
                    role="option" data-lang="fa" data-dir="rtl" tabindex="0">
                    <span class="lang-list__flag">🇮🇷</span>
                    <span class="lang-list__name">فارسی</span>
                </li>
            </ul>
        </div>
    </div>

<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════════════════ -->
<script>
    (function () {
        'use strict';

        const API   = '/api/v1';
        const STORE = window.localStorage;

        // ── Theme ────────────────────────────────────────────────────────────────
        const savedTheme = STORE.getItem('namak_theme') || 'light';
        applyTheme(savedTheme);
        document.getElementById('btn-theme').addEventListener('click', () => {
            const next = document.body.dataset.theme === 'light' ? 'dark' : 'light';
            applyTheme(next);
            STORE.setItem('namak_theme', next);
        });
        function applyTheme(t) {
            document.body.dataset.theme = t;
            document.querySelector('.icon-sun').style.display  = t === 'dark'  ? 'none' : '';
            document.querySelector('.icon-moon').style.display = t === 'light' ? 'none' : '';
        }

        // ── Lang modal ───────────────────────────────────────────────────────────
        const btnLang    = document.getElementById('btn-lang');
        const langModal  = document.getElementById('lang-modal');
        const langClose  = document.getElementById('lang-modal-close');
        if (btnLang) {
            btnLang.addEventListener('click', () => { langModal.hidden = false; });
            langClose.addEventListener('click', () => { langModal.hidden = true; });
            langModal.addEventListener('click', e => { if (e.target === langModal) langModal.hidden = true; });
            document.querySelectorAll('.lang-list__item').forEach(item => {
                item.addEventListener('click', () => {
                    document.cookie = `lang=${item.dataset.lang}; path=/; max-age=${30*86400}; SameSite=Strict`;
                    window.location.reload();
                });
            });
        }

        // ── Registration disabled — nothing more to do ───────────────────────────
        if (!document.getElementById('register-form')) return;

        // ── Step management ──────────────────────────────────────────────────────
        let currentStep = 1;

        function goToStep(n) {
            // Hide current
            document.getElementById('step-' + currentStep).classList.remove('active');
            // Update dots
            const dot = document.getElementById('dot-' + currentStep);
            dot.classList.remove('active');
            dot.classList.add('done');
            dot.innerHTML = '✓';

            if (n > 1) {
                document.getElementById('conn-' + (n - 1)).classList.add('done');
            }

            currentStep = n;
            document.getElementById('step-' + n).classList.add('active');
            document.getElementById('dot-' + n).classList.add('active');
            document.getElementById('dot-' + n).classList.remove('done');
            document.getElementById('dot-' + n).textContent = String(n);
            document.getElementById('steps-bar').setAttribute('aria-valuenow', String(n));

            // Scroll card into view
            document.querySelector('.auth-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function goBack(to) {
            document.getElementById('step-' + currentStep).classList.remove('active');
            document.getElementById('dot-' + currentStep).classList.remove('active');

            currentStep = to;
            document.getElementById('step-' + to).classList.add('active');
            document.getElementById('dot-' + to).classList.add('active');
            document.getElementById('dot-' + to).classList.remove('done');
            document.getElementById('dot-' + to).textContent = String(to);
            if (to < 3) {
                const conn = document.getElementById('conn-' + to);
                if (conn) conn.classList.remove('done');
            }
            document.getElementById('steps-bar').setAttribute('aria-valuenow', String(to));
        }

        // ── Error helpers ────────────────────────────────────────────────────────
        function clearErrors() {
            document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
            document.querySelectorAll('.form-group').forEach(el => el.classList.remove('form-group--error'));
            const ge = document.getElementById('global-error');
            if (ge) ge.hidden = true;
        }

        function setFieldError(id, msg) {
            const grpId = id.replace(/-/g, '_');
            const group = document.getElementById('group-' + grpId);
            const errEl = document.getElementById(grpId + '-err');
            if (group) group.classList.add('form-group--error');
            if (errEl) errEl.textContent = msg;
        }

        function showGlobalError(msg) {
            const ge  = document.getElementById('global-error');
            const txt = document.getElementById('global-error-text');
            txt.textContent = msg;
            ge.hidden = false;
            ge.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // ── Password toggle helper ────────────────────────────────────────────────
        function addPasswordToggle(btnId, inputId) {
            const btn   = document.getElementById(btnId);
            const input = document.getElementById(inputId);
            if (!btn || !input) return;
            btn.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.querySelector('.eye-show').style.display = show ? 'none' : '';
                btn.querySelector('.eye-hide').style.display = show ? '' : 'none';
                input.focus();
            });
        }
        addPasswordToggle('toggle-pwd1', 'password');
        addPasswordToggle('toggle-pwd2', 'confirm');

        // ── Password strength ────────────────────────────────────────────────────
        const pwdInput = document.getElementById('password');
        pwdInput.addEventListener('input', () => updateStrength(pwdInput.value));

        function updateStrength(pwd) {
            let score = 0;
            if (pwd.length >= 8)                       score++;
            if (pwd.length >= 12)                      score++;
            if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd))                     score++;
            if (/[^A-Za-z0-9]/.test(pwd))              score++;

            const level  = Math.min(4, Math.ceil(score * 4 / 5));
            const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e'];
            const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];

            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById('s' + i);
                bar.style.background = i <= level ? colors[level] : 'var(--auth-border)';
            }
            document.getElementById('s-label').textContent = labels[level] || '';
        }

        // ── Username availability check (debounced) ───────────────────────────────
        const usernameInput  = document.getElementById('username');
        const usernameStatus = document.getElementById('username-status');
        let   usernameTimer  = null;

        usernameInput.addEventListener('input', () => {
            clearTimeout(usernameTimer);
            const val = usernameInput.value.trim();

            if (val.length < 3) {
                usernameStatus.className = 'username-status';
                usernameStatus.textContent = '';
                return;
            }

            if (!/^[a-zA-Z0-9_]+$/.test(val)) {
                usernameStatus.className = 'username-status taken';
                usernameStatus.textContent = '✗ Only letters, numbers and underscores';
                return;
            }

            usernameStatus.className = 'username-status checking';
            usernameStatus.textContent = '⋯ Checking availability…';

            usernameTimer = setTimeout(async () => {
                try {
                    const res  = await fetch(`${API}/users/search?q=${encodeURIComponent(val)}&mode=username_exact`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'include',
                    });
                    const data = await res.json();
                    const taken = data.success && data.users && data.users.length > 0;
                    usernameStatus.className = 'username-status ' + (taken ? 'taken' : 'available');
                    usernameStatus.textContent = taken ? '✗ Already taken' : '✓ Available';
                } catch {
                    usernameStatus.className = 'username-status';
                    usernameStatus.textContent = '';
                }
            }, 500);
        });

        // ── Avatar picker ─────────────────────────────────────────────────────────
        const avatarPreview = document.getElementById('avatar-preview');
        const avatarFile    = document.getElementById('avatar-file');
        const avatarImg     = document.getElementById('avatar-img');
        const avatarPH      = document.getElementById('avatar-placeholder');

        avatarPreview.addEventListener('click',   () => avatarFile.click());
        avatarPreview.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') avatarFile.click(); });

        avatarFile.addEventListener('change', () => {
            const file = avatarFile.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                alert('Image too large. Max 5 MB.');
                avatarFile.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                avatarImg.src = e.target.result;
                avatarImg.style.display = 'block';
                avatarPH.style.display  = 'none';
            };
            reader.readAsDataURL(file);
        });

        // ── Step 1 validation + navigation ────────────────────────────────────────
        document.getElementById('btn-step1').addEventListener('click', () => {
            clearErrors();
            let ok = true;

            const username = usernameInput.value.trim();
            const email    = document.getElementById('email').value.trim();
            const phone    = document.getElementById('phone').value.trim();

            if (!username || username.length < 3) {
                setFieldError('username', 'Username must be at least 3 characters.'); ok = false;
            } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                setFieldError('username', 'Only letters, numbers and underscores.'); ok = false;
            } else if (usernameStatus.classList.contains('taken')) {
                setFieldError('username', 'This username is already taken.'); ok = false;
            }

            if (!email) {
                setFieldError('email', 'Email is required.'); ok = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setFieldError('email', 'Please enter a valid email address.'); ok = false;
            }

            if (!phone) {
                setFieldError('phone', 'Phone number is required.'); ok = false;
            } else if (!/^\+?[0-9\s\-().]{7,20}$/.test(phone)) {
                setFieldError('phone', 'Please enter a valid phone number.'); ok = false;
            }

            if (ok) goToStep(2);
        });

        // ── Step 2 validation + navigation ────────────────────────────────────────
        document.getElementById('btn-step2').addEventListener('click', () => {
            clearErrors();
            let ok = true;

            const pwd     = document.getElementById('password').value;
            const confirm = document.getElementById('confirm').value;

            if (!pwd || pwd.length < 8) {
                setFieldError('password', 'Password must be at least 8 characters.'); ok = false;
            }
            if (!confirm) {
                setFieldError('confirm', 'Please confirm your password.'); ok = false;
            } else if (pwd !== confirm) {
                setFieldError('confirm', 'Passwords do not match.'); ok = false;
            }

            if (ok) goToStep(3);
        });

        // ── Back buttons ──────────────────────────────────────────────────────────
        document.getElementById('btn-back1').addEventListener('click', () => { clearErrors(); goBack(1); });
        document.getElementById('btn-back2').addEventListener('click', () => { clearErrors(); goBack(2); });

        // ── Final submit ──────────────────────────────────────────────────────────
        const form    = document.getElementById('register-form');
        const btnSub  = document.getElementById('btn-submit');

        form.addEventListener('submit', async e => {
            e.preventDefault();
            clearErrors();

            const firstName = document.getElementById('first_name').value.trim();
            const terms     = document.getElementById('terms').checked;
            let ok = true;

            if (!firstName) {
                setFieldError('first_name', 'First name is required.'); ok = false;
            }
            if (!terms) {
                setFieldError('terms', 'You must agree to the Terms of Service.'); ok = false;
            }
            if (!ok) return;

            setLoading(true);

            try {
                // ── If avatar selected, upload first ──────────────────────────────
                let avatarFilename = null;
                if (avatarFile.files[0]) {
                    const fd = new FormData();
                    fd.append('file',    avatarFile.files[0]);
                    fd.append('type',    'image');
                    fd.append('chat_id', '0');  // pre-upload (no chat yet) — server handles 0
                    const upRes  = await fetch(`${API}/media/upload`, {
                        method: 'POST', body: fd, credentials: 'include',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const upData = await upRes.json();
                    if (upData.success) avatarFilename = upData.media?.filename ?? null;
                }

                // ── Register ──────────────────────────────────────────────────────
                const payload = {
                    username:   document.getElementById('username').value.trim().toLowerCase(),
                    email:      document.getElementById('email').value.trim().toLowerCase(),
                    phone:      document.getElementById('phone').value.trim(),
                    password:   document.getElementById('password').value,
                    first_name: firstName,
                    last_name:  document.getElementById('last_name').value.trim() || null,
                    avatar:     avatarFilename,
                };

                const res  = await fetch(`${API}/auth/register`, {
                    method:      'POST',
                    headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include',
                    body:        JSON.stringify(payload),
                });

                const data = await res.json();

                if (!res.ok || !data.success) {
                    handleServerErrors(res.status, data);
                    // If error is in step 1 or 2 fields, navigate back
                    navigateToErrorStep(data.errors || {});
                    return;
                }

                // ── Store tokens ──────────────────────────────────────────────────
                STORE.setItem('namak_token',   data.access_token);
                STORE.setItem('namak_expires', String(Date.now() + data.expires_in * 1000));
                STORE.setItem('namak_user',    JSON.stringify(data.user));

                // ── Store private key in IndexedDB ────────────────────────────────
                if (data.private_key) {
                    await storePrivateKey(data.user.id, data.private_key);
                    showKeyModal(data.private_key);
                } else {
                    window.location.href = '/app/chat';
                }

            } catch (err) {
                showGlobalError('Network error. Please check your connection.');
                console.error('[Register]', err);
            } finally {
                setLoading(false);
            }
        });

        // ── Loading state ────────────────────────────────────────────────────────
        function setLoading(on) {
            btnSub.setAttribute('aria-busy', on ? 'true' : 'false');
            btnSub.disabled = on;
            btnSub.querySelector('.btn__text').hidden    = on;
            btnSub.querySelector('.btn__spinner').hidden = !on;
        }

        // ── Server error handler ─────────────────────────────────────────────────
        function handleServerErrors(status, data) {
            if (status === 429) {
                showGlobalError(data.message || 'Too many attempts. Please try again later.');
                return;
            }
            if (data.errors) {
                Object.entries(data.errors).forEach(([field, msg]) => {
                    setFieldError(field, Array.isArray(msg) ? msg[0] : msg);
                });
                return;
            }
            showGlobalError(data.message || 'Registration failed. Please try again.');
        }

        function navigateToErrorStep(errors) {
            const step1Fields = ['username', 'email', 'phone'];
            const step2Fields = ['password', 'confirm'];
            const keys        = Object.keys(errors);
            if (keys.some(k => step1Fields.includes(k)) && currentStep !== 1) goBack(1);
            else if (keys.some(k => step2Fields.includes(k)) && currentStep !== 2) goBack(2);
        }

        // ── Private key modal ────────────────────────────────────────────────────
        function showKeyModal(privateKey) {
            const modal    = document.getElementById('key-modal');
            const display  = document.getElementById('key-display');
            const btnDone  = document.getElementById('btn-key-done');
            const chk      = document.getElementById('key-confirm-check');
            const btnCopy  = document.getElementById('btn-copy-key');
            const btnDl    = document.getElementById('btn-download-key');

            display.textContent = privateKey;
            modal.hidden = false;

            chk.addEventListener('change', () => { btnDone.disabled = !chk.checked; });

            btnCopy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(privateKey);
                    btnCopy.textContent = '✓ Copied!';
                    setTimeout(() => { btnCopy.textContent = '📋 Copy Key'; }, 2000);
                } catch {
                    // Fallback
                    display.select?.();
                    document.execCommand('copy');
                }
            });

            btnDl.addEventListener('click', () => {
                const blob = new Blob([privateKey], { type: 'text/plain' });
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = 'namak-private-key.txt';
                a.click();
                URL.revokeObjectURL(url);
            });

            btnDone.addEventListener('click', () => {
                modal.hidden = true;
                window.location.href = '/app/chat';
            });

            // Prevent closing by clicking outside
            modal.addEventListener('click', e => {
                if (e.target === modal) {
                    // Shake to indicate "you must save the key first"
                    modal.querySelector('.modal').style.animation = 'none';
                    setTimeout(() => {
                        modal.querySelector('.modal').style.animation = '';
                    }, 100);
                }
            });
        }

        // ── IndexedDB: store private key ─────────────────────────────────────────
        async function storePrivateKey(userId, privateKey) {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open('namak_keys', 1);
                req.onupgradeneeded = e => {
                    e.target.result.createObjectStore('keys', { keyPath: 'user_id' });
                };
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

        // ── Real-time inline validation clear ────────────────────────────────────
        document.querySelectorAll('.form-input, .form-check__input').forEach(input => {
            input.addEventListener('input', () => {
                const grp = input.closest('.form-group');
                if (grp) {
                    grp.classList.remove('form-group--error');
                    const errEl = grp.querySelector('.form-error');
                    if (errEl) errEl.textContent = '';
                }
            });
        });

        // ── Service Worker ───────────────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/assets/js/service-worker.js', { scope: '/' })
                .catch(err => console.warn('[SW]', err));
        }

    })();
</script>

</body>
</html>

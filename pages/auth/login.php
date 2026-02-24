<?php
declare(strict_types=1);

/**
 * Login Page
 * Namak Messenger — pages/auth/login.php
 *
 * - English LTR default, Persian RTL optional
 * - Zero external dependencies (all assets local)
 * - Telegram-style UI
 * - Redirects to /app/chat if already authenticated
 */

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use Namak\Services\Auth;
use Namak\Core\Request;

$request = new Request();
$auth    = new Auth();

// Already logged in → redirect to chat
$token = $_COOKIE['access_token'] ?? $request->getBearerToken();
if ($token && $auth->validateToken($token)) {
    header('Location: /app/chat');
    exit;
}

// Load i18n strings (default: English)
$lang   = $_COOKIE['lang'] ?? 'en';
$dir    = $lang === 'fa' ? 'rtl' : 'ltr';
$i18n   = require BASE_PATH . '/lib/i18n/' . (in_array($lang, ['en','fa']) ? $lang : 'en') . '.php';

// App config (from install)
$appName = $_ENV['APP_NAME'] ?? 'Namak';
$appLogo = $_ENV['APP_LOGO'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#2196f3">
    <meta name="description" content="<?= htmlspecialchars($appName) ?> — Sign in">
    <meta name="robots" content="noindex, nofollow">

    <title><?= htmlspecialchars($appName) ?> — Sign In</title>

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">

    <!-- Local CSS only -->
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/auth.css">

    <!-- Inline critical theme vars (avoids FOUC) -->
    <style>
        :root {
            --auth-bg:          #f0f2f5;
            --auth-card-bg:     #ffffff;
            --auth-primary:     #2196f3;
            --auth-primary-h:   #1976d2;
            --auth-text:        #111827;
            --auth-text-muted:  #6b7280;
            --auth-border:      #e5e7eb;
            --auth-input-bg:    #f9fafb;
            --auth-error:       #ef4444;
            --auth-success:     #22c55e;
            --auth-radius:      12px;
            --auth-shadow:      0 4px 24px rgba(0,0,0,.08);
        }
        [data-theme="dark"] {
            --auth-bg:          #0f172a;
            --auth-card-bg:     #1e293b;
            --auth-text:        #f1f5f9;
            --auth-text-muted:  #94a3b8;
            --auth-border:      #334155;
            --auth-input-bg:    #0f172a;
        }
    </style>
</head>
<body class="auth-page" data-theme="light">

<!-- ══════════════════════════════════════════════════════
     TOP BAR  (language switcher + theme toggle)
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
        <!-- Language toggle -->
        <button class="icon-btn" id="btn-lang" title="Switch language" aria-label="Switch language">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
        </button>
        <!-- Theme toggle -->
        <button class="icon-btn" id="btn-theme" title="Toggle theme" aria-label="Toggle theme">
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </div>
</header>

<!-- ══════════════════════════════════════════════════════
     MAIN CARD
══════════════════════════════════════════════════════ -->
<main class="auth-main">
    <div class="auth-card" role="main">

        <!-- Logo / App icon -->
        <div class="auth-card__hero">
            <?php if ($appLogo): ?>
                <img src="<?= htmlspecialchars($appLogo) ?>" alt="" class="auth-card__app-logo">
            <?php else: ?>
                <div class="auth-card__app-icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none">
                        <circle cx="24" cy="24" r="24" fill="var(--auth-primary)"/>
                        <path d="M10 24l8 8 20-20" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            <?php endif; ?>
            <h1 class="auth-card__title"><?= htmlspecialchars($appName) ?></h1>
            <p class="auth-card__subtitle">Sign in to your account</p>
        </div>

        <!-- ── Global error banner ── -->
        <div class="alert alert--error" id="global-error" role="alert" aria-live="polite" hidden>
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span id="global-error-text"></span>
        </div>

        <!-- ── Login form ── -->
        <form id="login-form" class="auth-form" novalidate autocomplete="off" spellcheck="false">

            <!-- Identifier: username | email | phone -->
            <div class="form-group" id="group-identifier">
                <label class="form-label" for="identifier">
                    Username, Email or Phone
                </label>
                <div class="form-input-wrap">
                    <span class="form-input-icon">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                    </span>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        class="form-input"
                        placeholder="Enter username, email or phone"
                        autocomplete="username"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
                        maxlength="255"
                        required
                        aria-describedby="identifier-err"
                    >
                </div>
                <span class="form-error" id="identifier-err" role="alert" aria-live="polite"></span>
            </div>

            <!-- Password -->
            <div class="form-group" id="group-password">
                <label class="form-label" for="password">
                    Password
                </label>
                <div class="form-input-wrap">
                    <span class="form-input-icon">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                    </span>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        maxlength="128"
                        required
                        aria-describedby="password-err"
                    >
                    <button type="button" class="form-input-toggle" id="toggle-password"
                            aria-label="Show/hide password" tabindex="-1">
                        <svg class="eye-show" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                        <svg class="eye-hide" viewBox="0 0 20 20" fill="currentColor" style="display:none"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.064 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>
                    </button>
                </div>
                <span class="form-error" id="password-err" role="alert" aria-live="polite"></span>
            </div>

            <!-- Remember me -->
            <div class="form-row form-row--between">
                <label class="form-check">
                    <input type="checkbox" id="remember" name="remember" class="form-check__input">
                    <span class="form-check__box"></span>
                    <span class="form-check__label">Remember me</span>
                </label>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn btn--primary btn--full" id="btn-login" aria-busy="false">
                <span class="btn__text">Sign In</span>
                <span class="btn__spinner" hidden aria-hidden="true">
                    <svg class="spinner" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4" stroke-dashoffset="10"/>
                    </svg>
                </span>
            </button>

        </form>

        <!-- Divider -->
        <div class="auth-divider"><span>or</span></div>

        <!-- Register link -->
        <p class="auth-card__footer-text">
            Don't have an account?
            <a href="/auth/register" class="link">Create one</a>
        </p>

    </div><!-- /.auth-card -->
</main>

<!-- ══════════════════════════════════════════════════════
     LANGUAGE PICKER MODAL
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="lang-modal" role="dialog" aria-modal="true" aria-label="Select language" hidden>
    <div class="modal modal--sm">
        <div class="modal__header">
            <h2 class="modal__title">Language</h2>
            <button class="icon-btn modal__close" id="lang-modal-close" aria-label="Close">✕</button>
        </div>
        <ul class="lang-list" role="listbox">
            <li class="lang-list__item <?= $lang === 'en' ? 'lang-list__item--active' : '' ?>"
                role="option" aria-selected="<?= $lang === 'en' ? 'true' : 'false' ?>"
                data-lang="en" data-dir="ltr" tabindex="0">
                <span class="lang-list__flag">🇬🇧</span>
                <span class="lang-list__name">English</span>
                <?php if ($lang === 'en'): ?>
                    <svg class="lang-list__check" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                <?php endif; ?>
            </li>
            <li class="lang-list__item <?= $lang === 'fa' ? 'lang-list__item--active' : '' ?>"
                role="option" aria-selected="<?= $lang === 'fa' ? 'true' : 'false' ?>"
                data-lang="fa" data-dir="rtl" tabindex="0">
                <span class="lang-list__flag">🇮🇷</span>
                <span class="lang-list__name">فارسی</span>
                <?php if ($lang === 'fa'): ?>
                    <svg class="lang-list__check" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS  (all local, no CDN)
══════════════════════════════════════════════════════ -->
<script>
    (function () {
        'use strict';

        // ── Constants ────────────────────────────────────────────────────────────
        const API   = '/api/v1';
        const STORE = window.localStorage;

        // ── DOM refs ─────────────────────────────────────────────────────────────
        const form         = document.getElementById('login-form');
        const btnLogin     = document.getElementById('btn-login');
        const btnTheme     = document.getElementById('btn-theme');
        const btnLang      = document.getElementById('btn-lang');
        const langModal    = document.getElementById('lang-modal');
        const langClose    = document.getElementById('lang-modal-close');
        const globalError  = document.getElementById('global-error');
        const globalErrTxt = document.getElementById('global-error-text');
        const togglePwd    = document.getElementById('toggle-password');
        const pwdInput     = document.getElementById('password');
        const idInput      = document.getElementById('identifier');

        // ── Theme ────────────────────────────────────────────────────────────────
        const savedTheme = STORE.getItem('namak_theme') || 'light';
        applyTheme(savedTheme);

        btnTheme.addEventListener('click', () => {
            const current = document.body.dataset.theme || 'light';
            const next    = current === 'light' ? 'dark' : 'light';
            applyTheme(next);
            STORE.setItem('namak_theme', next);
        });

        function applyTheme(theme) {
            document.body.dataset.theme = theme;
            document.querySelector('.icon-sun').style.display  = theme === 'dark'  ? 'none'  : '';
            document.querySelector('.icon-moon').style.display = theme === 'light' ? 'none'  : '';
        }

        // ── Language ─────────────────────────────────────────────────────────────
        btnLang.addEventListener('click', () => {
            langModal.hidden = false;
            langModal.querySelector('[data-lang]').focus();
        });
        langClose.addEventListener('click',  closeLangModal);
        langModal.addEventListener('click', e => { if (e.target === langModal) closeLangModal(); });
        langModal.addEventListener('keydown', e => { if (e.key === 'Escape') closeLangModal(); });

        function closeLangModal() { langModal.hidden = true; }

        document.querySelectorAll('.lang-list__item').forEach(item => {
            item.addEventListener('click',  () => selectLang(item));
            item.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') selectLang(item); });
        });

        function selectLang(item) {
            const lang = item.dataset.lang;
            const dir  = item.dataset.dir;
            // Persist in cookie (30 days) — PHP reads on next page load
            document.cookie = `lang=${lang}; path=/; max-age=${30 * 86400}; SameSite=Strict`;
            document.documentElement.lang = lang;
            document.documentElement.dir  = dir;
            closeLangModal();
            // Reload to apply server-side i18n
            window.location.reload();
        }

        // ── Password visibility toggle ───────────────────────────────────────────
        togglePwd.addEventListener('click', () => {
            const show = pwdInput.type === 'password';
            pwdInput.type = show ? 'text' : 'password';
            togglePwd.querySelector('.eye-show').style.display = show ? 'none' : '';
            togglePwd.querySelector('.eye-hide').style.display = show ? ''     : 'none';
            pwdInput.focus();
        });

        // ── Client-side validation ───────────────────────────────────────────────
        function clearErrors() {
            document.querySelectorAll('.form-error').forEach(el => { el.textContent = ''; });
            document.querySelectorAll('.form-group').forEach(el => el.classList.remove('form-group--error'));
            globalError.hidden = true;
        }

        function setFieldError(fieldId, msg) {
            const group = document.getElementById('group-' + fieldId);
            const errEl = document.getElementById(fieldId + '-err');
            if (group) group.classList.add('form-group--error');
            if (errEl) errEl.textContent = msg;
        }

        function showGlobalError(msg) {
            globalErrTxt.textContent = msg;
            globalError.hidden = false;
            globalError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function validateForm() {
            let ok = true;
            const id  = idInput.value.trim();
            const pwd = pwdInput.value;

            if (!id) {
                setFieldError('identifier', 'This field is required.');
                ok = false;
            } else if (id.length < 2) {
                setFieldError('identifier', 'Too short.');
                ok = false;
            }

            if (!pwd) {
                setFieldError('password', 'This field is required.');
                ok = false;
            }
            return ok;
        }

        // ── Loading state ────────────────────────────────────────────────────────
        function setLoading(on) {
            btnLogin.setAttribute('aria-busy', on ? 'true' : 'false');
            btnLogin.disabled = on;
            btnLogin.querySelector('.btn__text').hidden    = on;
            btnLogin.querySelector('.btn__spinner').hidden = !on;
        }

        // ── Submit ───────────────────────────────────────────────────────────────
        form.addEventListener('submit', async e => {
            e.preventDefault();
            clearErrors();
            if (!validateForm()) return;

            setLoading(true);

            const payload = {
                identifier: idInput.value.trim(),
                password:   pwdInput.value,
            };

            try {
                const res  = await fetch(`${API}/auth/login`, {
                    method:      'POST',
                    headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'include',   // send/receive HttpOnly cookies
                    body:        JSON.stringify(payload),
                });

                const data = await res.json();

                if (!res.ok || !data.success) {
                    handleErrors(res.status, data);
                    return;
                }

                // ── Success ───────────────────────────────────────────────────────
                // Store access token (short-lived) in sessionStorage/localStorage
                const remember = document.getElementById('remember').checked;
                const storage  = remember ? STORE : window.sessionStorage;
                storage.setItem('namak_token',   data.access_token);
                storage.setItem('namak_expires', String(Date.now() + data.expires_in * 1000));
                storage.setItem('namak_user',    JSON.stringify(data.user));

                // If server returned a private key during first login (unlikely on
                // normal login, but handle re-issue from /auth/regenerate-keys):
                if (data.private_key) {
                    await storePrivateKey(data.user.id, data.private_key);
                }

                // Redirect to chat
                window.location.href = '/app/chat';

            } catch (err) {
                showGlobalError('Network error. Please check your connection.');
                console.error('[Login]', err);
            } finally {
                setLoading(false);
            }
        });

        // ── Error handler ────────────────────────────────────────────────────────
        function handleErrors(status, data) {
            if (status === 429) {
                const wait = data.retry_after ? ` Please wait ${Math.ceil(data.retry_after / 60)} minute(s).` : '';
                showGlobalError((data.message || 'Too many attempts.') + wait);
                return;
            }
            if (status === 401 || status === 403) {
                showGlobalError(data.message || 'Invalid credentials.');
                setFieldError('identifier', ' ');
                setFieldError('password',   ' ');
                return;
            }
            if (data.errors) {
                Object.entries(data.errors).forEach(([field, msg]) => {
                    const key = field.replace('_', '-');
                    setFieldError(key, Array.isArray(msg) ? msg[0] : msg);
                });
                return;
            }
            showGlobalError(data.message || 'Something went wrong. Please try again.');
        }

        // ── IndexedDB: store E2E private key securely ────────────────────────────
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

        // ── Real-time field feedback (clear error on type) ────────────────────────
        [idInput, pwdInput].forEach(input => {
            input.addEventListener('input', () => {
                const fieldId = input.id === 'identifier' ? 'identifier' : 'password';
                const group   = document.getElementById('group-' + fieldId);
                const errEl   = document.getElementById(fieldId + '-err');
                if (group) group.classList.remove('form-group--error');
                if (errEl) errEl.textContent = '';
                if (globalError && !globalError.hidden) globalError.hidden = true;
            });
        });

        // ── Auto-detect identifier type (show hint) ───────────────────────────────
        idInput.addEventListener('input', () => {
            const v = idInput.value.trim();
            let hint = '';
            if (v.includes('@') && v.includes('.')) hint = 'Signing in with email';
            else if (/^\+?[0-9]{5,}$/.test(v))      hint = 'Signing in with phone';
            else if (v.length >= 2)                  hint = 'Signing in with username';
            idInput.placeholder = hint || 'Enter username, email or phone';
        });

        // ── PWA install prompt (deferred) ─────────────────────────────────────────
        let deferredInstall = null;
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            deferredInstall = e;
        });

        // ── Service Worker registration ───────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/assets/js/service-worker.js', { scope: '/' })
                .catch(err => console.warn('[SW]', err));
        }

    })();
</script>

</body>
</html>

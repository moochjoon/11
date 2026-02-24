<?php

declare(strict_types=1);

namespace Namak\Controllers;

use Namak\Core\Request;
use Namak\Core\Response;
use Namak\Services\Auth;
use Namak\Services\Security;
use Namak\Services\Validation;
use Namak\Services\Cache;
use Namak\Repositories\UserRepository;

/**
 * AuthController
 *
 * Handles all authentication-related HTTP actions:
 * register, login, logout, token refresh, password change, etc.
 *
 * All methods return JSON responses via the Response class.
 */
class AuthController
{
    private Auth           $auth;
    private Security       $security;
    private Validation     $validation;
    private Cache          $cache;
    private UserRepository $userRepository;

    public function __construct(
        Auth           $auth,
        Security       $security,
        Validation     $validation,
        Cache          $cache,
        UserRepository $userRepository
    ) {
        $this->auth           = $auth;
        $this->security       = $security;
        $this->validation     = $validation;
        $this->cache          = $cache;
        $this->userRepository = $userRepository;
    }

    // =========================================================================
    // PUBLIC ENDPOINTS
    // =========================================================================

    /**
     * POST /api/v1/auth/register
     * Register a new user account.
     */
    public function register(Request $request, Response $response): void
    {
        $data = $request->getJson();

        // ── Validation ────────────────────────────────────────────────────────
        $rules = [
            'username'   => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'email'      => ['required', 'email', 'max:255'],
            'phone'      => ['required', 'phone'],
            'password'   => ['required', 'string', 'min:8', 'max:128'],
            'first_name' => ['required', 'string', 'min:1', 'max:64'],
            'last_name'  => ['nullable', 'string', 'max:64'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        // ── Uniqueness checks ─────────────────────────────────────────────────
        if ($this->userRepository->existsByUsername($data['username'])) {
            $response->json([
                'success' => false,
                'message' => 'Username already taken.',
                'field'   => 'username',
            ], 409);
            return;
        }

        if ($this->userRepository->existsByEmail($data['email'])) {
            $response->json([
                'success' => false,
                'message' => 'Email already registered.',
                'field'   => 'email',
            ], 409);
            return;
        }

        if ($this->userRepository->existsByPhone($data['phone'])) {
            $response->json([
                'success' => false,
                'message' => 'Phone number already registered.',
                'field'   => 'phone',
            ], 409);
            return;
        }

        // ── Rate limit: max 5 registrations per IP per hour ───────────────────
        $ipKey = 'register_ip_' . md5($request->getIp());
        if ((int) $this->cache->get($ipKey) >= 5) {
            $response->json([
                'success' => false,
                'message' => 'Too many registration attempts. Please try again later.',
            ], 429);
            return;
        }
        $this->cache->increment($ipKey, 1, 3600);

        // ── Create user ───────────────────────────────────────────────────────
        $passwordHash = $this->security->hashPassword($data['password']);

        // Generate E2E key pair (used for secret chats / E2E encryption)
        $keyPair = $this->security->generateKeyPair();

        $userId = $this->userRepository->create([
            'username'        => strtolower(trim($data['username'])),
            'email'           => strtolower(trim($data['email'])),
            'phone'           => $this->security->sanitizePhone($data['phone']),
            'password_hash'   => $passwordHash,
            'first_name'      => trim($data['first_name']),
            'last_name'       => isset($data['last_name']) ? trim($data['last_name']) : null,
            'public_key'      => $keyPair['public'],   // stored on server
            // private key MUST be stored client-side only; we return it once
            'avatar'          => null,
            'bio'             => null,
            'is_active'       => 1,
            'privacy_search'  => 'username',           // default: searchable only by username
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        if (!$userId) {
            $response->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
            return;
        }

        // ── Issue tokens ──────────────────────────────────────────────────────
        $tokens = $this->auth->issueTokens((int) $userId);

        $response->json([
            'success'      => true,
            'message'      => 'Account created successfully.',
            'user'         => $this->userRepository->getPublicProfile((int) $userId),
            'private_key'  => $keyPair['private'],  // ⚠ shown ONCE — client must persist locally
            'access_token' => $tokens['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $tokens['expires_in'],
        ], 201);

        // Store refresh token in HttpOnly cookie
        $this->auth->setRefreshTokenCookie($tokens['refresh_token']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/auth/login
     * Authenticate an existing user.
     */
    public function login(Request $request, Response $response): void
    {
        $data = $request->getJson();

        // ── Validation ────────────────────────────────────────────────────────
        $rules = [
            'identifier' => ['required', 'string', 'max:255'], // username | email | phone
            'password'   => ['required', 'string', 'min:1', 'max:128'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        // ── Brute-force protection ─────────────────────────────────────────────
        $attemptKey = 'login_fail_' . md5($request->getIp() . '|' . strtolower($data['identifier']));
        $attempts   = (int) $this->cache->get($attemptKey);

        if ($attempts >= 10) {
            $response->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please wait 15 minutes.',
                'retry_after' => 900,
            ], 429);
            return;
        }

        // ── Find user by identifier ────────────────────────────────────────────
        $identifier = trim($data['identifier']);
        $user = null;

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = $this->userRepository->findByEmail(strtolower($identifier));
        } elseif (preg_match('/^\+?[0-9]{7,15}$/', $identifier)) {
            $user = $this->userRepository->findByPhone($identifier);
        } else {
            $user = $this->userRepository->findByUsername(strtolower($identifier));
        }

        // ── Verify password ───────────────────────────────────────────────────
        $validCredentials = $user &&
            $this->security->verifyPassword($data['password'], $user['password_hash']);

        if (!$validCredentials) {
            $this->cache->increment($attemptKey, 1, 900); // 15-min window
            $response->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
            return;
        }

        // ── Account status check ──────────────────────────────────────────────
        if (!$user['is_active']) {
            $response->json([
                'success' => false,
                'message' => 'Your account has been suspended.',
            ], 403);
            return;
        }

        // ── Reset fail counter ────────────────────────────────────────────────
        $this->cache->delete($attemptKey);

        // ── Update last seen ──────────────────────────────────────────────────
        $this->userRepository->updateLastSeen((int) $user['id']);

        // ── Issue tokens ──────────────────────────────────────────────────────
        $tokens = $this->auth->issueTokens((int) $user['id']);

        $response->json([
            'success'      => true,
            'message'      => 'Logged in successfully.',
            'user'         => $this->userRepository->getPublicProfile((int) $user['id']),
            'access_token' => $tokens['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $tokens['expires_in'],
        ]);

        $this->auth->setRefreshTokenCookie($tokens['refresh_token']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/auth/logout
     * Revoke current session tokens.
     */
    public function logout(Request $request, Response $response): void
    {
        $userId = $request->getUserId(); // set by AuthMiddleware

        if (!$userId) {
            $response->json(['success' => false, 'message' => 'Not authenticated.'], 401);
            return;
        }

        // Revoke access token
        $accessToken = $request->getBearerToken();
        if ($accessToken) {
            $this->auth->revokeAccessToken($accessToken);
        }

        // Revoke refresh token from cookie
        $refreshToken = $request->getCookie('refresh_token');
        if ($refreshToken) {
            $this->auth->revokeRefreshToken($refreshToken);
        }

        // Clear HttpOnly cookie
        $this->auth->clearRefreshTokenCookie();

        // Update last seen timestamp
        $this->userRepository->updateLastSeen((int) $userId);

        $response->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/auth/refresh
     * Exchange a valid refresh token for a new access token.
     */
    public function refresh(Request $request, Response $response): void
    {
        $refreshToken = $request->getCookie('refresh_token')
            ?? ($request->getJson()['refresh_token'] ?? null);

        if (!$refreshToken) {
            $response->json([
                'success' => false,
                'message' => 'Refresh token not provided.',
            ], 401);
            return;
        }

        try {
            $result = $this->auth->refreshAccessToken($refreshToken);
        } catch (\RuntimeException $e) {
            // Clear the invalid/expired cookie
            $this->auth->clearRefreshTokenCookie();
            $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
            return;
        }

        $response->json([
            'success'      => true,
            'access_token' => $result['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $result['expires_in'],
        ]);

        // Rotate refresh token
        $this->auth->setRefreshTokenCookie($result['refresh_token']);
    }

    // =========================================================================
    // AUTHENTICATED ENDPOINTS  (require valid JWT — enforced by AuthMiddleware)
    // =========================================================================

    /**
     * POST /api/v1/auth/change-password
     * Change password for the currently authenticated user.
     */
    public function changePassword(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'max:128'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $user = $this->userRepository->findById((int) $userId);
        if (!$user) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        if (!$this->security->verifyPassword($data['current_password'], $user['password_hash'])) {
            $response->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
                'field'   => 'current_password',
            ], 422);
            return;
        }

        if ($data['current_password'] === $data['new_password']) {
            $response->json([
                'success' => false,
                'message' => 'New password must differ from the current password.',
                'field'   => 'new_password',
            ], 422);
            return;
        }

        $newHash = $this->security->hashPassword($data['new_password']);
        $this->userRepository->updatePassword((int) $userId, $newHash);

        // Revoke all existing sessions so other devices must re-login
        $this->auth->revokeAllUserTokens((int) $userId);
        $this->auth->clearRefreshTokenCookie();

        $response->json([
            'success' => true,
            'message' => 'Password changed. Please log in again.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/auth/me
     * Return the currently authenticated user's profile.
     */
    public function me(Request $request, Response $response): void
    {
        $userId = $request->getUserId();

        $user = $this->userRepository->getPublicProfile((int) $userId);
        if (!$user) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        $response->json([
            'success' => true,
            'user'    => $user,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/auth/regenerate-keys
     * Re-generate the user's E2E key pair (e.g. on new device login).
     * The new private key is returned ONCE — client MUST save it locally.
     */
    public function regenerateKeys(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        // Require password confirmation for security
        $user = $this->userRepository->findById((int) $userId);
        if (!$user || !$this->security->verifyPassword(
                $data['password'] ?? '',
                $user['password_hash']
            )) {
            $response->json([
                'success' => false,
                'message' => 'Password confirmation required.',
            ], 401);
            return;
        }

        $keyPair = $this->security->generateKeyPair();

        $this->userRepository->updatePublicKey((int) $userId, $keyPair['public']);

        $response->json([
            'success'     => true,
            'message'     => 'Key pair regenerated. Save your private key now — it will not be shown again.',
            'public_key'  => $keyPair['public'],
            'private_key' => $keyPair['private'], // ⚠ returned ONCE
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Builds a sanitized public user array (no sensitive fields).
     * Used internally if repository method unavailable.
     */
    private function buildPublicUser(array $user): array
    {
        return [
            'id'             => (int)    $user['id'],
            'username'       => (string) $user['username'],
            'first_name'     => (string) $user['first_name'],
            'last_name'      => $user['last_name'] ?? null,
            'avatar'         => $user['avatar'] ?? null,
            'bio'            => $user['bio'] ?? null,
            'public_key'     => (string) $user['public_key'],
            'privacy_search' => (string) ($user['privacy_search'] ?? 'username'),
            'created_at'     => (string) $user['created_at'],
        ];
    }
}

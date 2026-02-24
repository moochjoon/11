<?php

declare(strict_types=1);

namespace Namak\Controllers;

use Namak\Core\Request;
use Namak\Core\Response;
use Namak\Services\Cache;
use Namak\Services\Security;
use Namak\Services\Validation;
use Namak\Repositories\UserRepository;
use Namak\Repositories\ChatRepository;

/**
 * UserController
 *
 * Handles all user-related operations:
 * profile view/update, avatar, privacy settings,
 * contacts management, user search, block/unblock, online status.
 *
 * Privacy rule (enforced everywhere):
 *   Users are searchable ONLY by username / phone / email — NOT by name.
 */
class UserController
{
    private UserRepository $userRepo;
    private ChatRepository $chatRepo;
    private Validation     $validation;
    private Security       $security;
    private Cache          $cache;

    // Avatar constraints
    private const AVATAR_MAX_BYTES  = 5 * 1024 * 1024; // 5 MB
    private const AVATAR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        UserRepository $userRepo,
        ChatRepository $chatRepo,
        Validation     $validation,
        Security       $security,
        Cache          $cache
    ) {
        $this->userRepo   = $userRepo;
        $this->chatRepo   = $chatRepo;
        $this->validation = $validation;
        $this->security   = $security;
        $this->cache      = $cache;
    }

    // =========================================================================
    // PROFILE  —  GET /api/v1/users/profile
    // =========================================================================

    /**
     * Get the authenticated user's own full profile.
     */
    public function myProfile(Request $request, Response $response): void
    {
        $userId = $request->getUserId();

        $user = $this->userRepo->findById((int) $userId);
        if (!$user) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        $response->json([
            'success' => true,
            'user'    => $this->formatOwnProfile($user),
        ]);
    }

    /**
     * GET /api/v1/users/profile/{id}  or  /api/v1/users/profile?username=xxx
     * View another user's public profile.
     */
    public function profile(Request $request, Response $response): void
    {
        $myId  = $request->getUserId();
        $query = $request->getQuery();
        $route = $request->getRouteParams();

        // Resolve target user
        $target = null;
        if (!empty($route['id'])) {
            $target = $this->userRepo->findById((int) $route['id']);
        } elseif (!empty($query['username'])) {
            $target = $this->userRepo->findByUsername(strtolower(trim($query['username'])));
        } elseif (!empty($query['phone'])) {
            $target = $this->userRepo->findByPhone(trim($query['phone']));
        } elseif (!empty($query['email'])) {
            $target = $this->userRepo->findByEmail(strtolower(trim($query['email'])));
        }

        if (!$target || !$target['is_active']) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        // Check block status
        if ($this->userRepo->isBlocked((int) $target['id'], (int) $myId)) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        $isContact  = $this->userRepo->isContact((int) $myId, (int) $target['id']);
        $isBlocking = $this->userRepo->isBlocked((int) $myId, (int) $target['id']);

        $response->json([
            'success'      => true,
            'user'         => $this->formatPublicProfile($target, (int) $myId),
            'is_contact'   => $isContact,
            'is_blocking'  => $isBlocking,
        ]);
    }

    // =========================================================================
    // UPDATE PROFILE  —  PATCH /api/v1/users/update
    // =========================================================================

    /**
     * Update own profile fields: first_name, last_name, bio, username.
     */
    public function update(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'first_name' => ['nullable', 'string', 'min:1', 'max:64'],
            'last_name'  => ['nullable', 'string', 'max:64'],
            'bio'        => ['nullable', 'string', 'max:255'],
            'username'   => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'phone'      => ['nullable', 'phone'],
            'email'      => ['nullable', 'email', 'max:255'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $current = $this->userRepo->findById((int) $userId);
        if (!$current) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        $updates = [];

        // ── Username ──────────────────────────────────────────────────────────
        if (isset($data['username'])) {
            $newUsername = strtolower(trim($data['username']));
            if ($newUsername !== $current['username']) {
                if ($this->userRepo->existsByUsername($newUsername)) {
                    $response->json([
                        'success' => false,
                        'message' => 'Username already taken.',
                        'field'   => 'username',
                    ], 409);
                    return;
                }
                // Rate limit: username can only change once per 14 days
                $limitKey = "username_change_{$userId}";
                if ($this->cache->get($limitKey)) {
                    $response->json([
                        'success' => false,
                        'message' => 'Username can only be changed once every 14 days.',
                        'field'   => 'username',
                    ], 429);
                    return;
                }
                $updates['username'] = $newUsername;
                $this->cache->set($limitKey, 1, 1209600); // 14 days
            }
        }

        // ── Email ─────────────────────────────────────────────────────────────
        if (isset($data['email'])) {
            $newEmail = strtolower(trim($data['email']));
            if ($newEmail !== $current['email']) {
                if ($this->userRepo->existsByEmail($newEmail)) {
                    $response->json([
                        'success' => false,
                        'message' => 'Email already in use.',
                        'field'   => 'email',
                    ], 409);
                    return;
                }
                $updates['email'] = $newEmail;
            }
        }

        // ── Phone ─────────────────────────────────────────────────────────────
        if (isset($data['phone'])) {
            $newPhone = $this->security->sanitizePhone($data['phone']);
            if ($newPhone !== $current['phone']) {
                if ($this->userRepo->existsByPhone($newPhone)) {
                    $response->json([
                        'success' => false,
                        'message' => 'Phone number already in use.',
                        'field'   => 'phone',
                    ], 409);
                    return;
                }
                $updates['phone'] = $newPhone;
            }
        }

        // ── Text fields ───────────────────────────────────────────────────────
        if (isset($data['first_name'])) $updates['first_name'] = trim($data['first_name']);
        if (array_key_exists('last_name', $data)) $updates['last_name'] = $data['last_name'] !== null ? trim($data['last_name']) : null;
        if (array_key_exists('bio', $data))       $updates['bio']       = $data['bio']       !== null ? trim($data['bio'])       : null;

        if (empty($updates)) {
            $response->json(['success' => false, 'message' => 'No changes provided.'], 422);
            return;
        }

        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->userRepo->update((int) $userId, $updates);

        // Bust profile cache
        $this->cache->delete("user_profile_{$userId}");

        $updated = $this->userRepo->findById((int) $userId);
        $response->json([
            'success' => true,
            'message' => 'Profile updated.',
            'user'    => $this->formatOwnProfile($updated),
        ]);
    }

    // =========================================================================
    // AVATAR  —  POST /api/v1/users/avatar
    // =========================================================================

    /**
     * Upload or remove profile avatar.
     * File is stored server-side (avatars are small and server-managed).
     * Body: multipart/form-data  OR  JSON { "remove": true }
     */
    public function updateAvatar(Request $request, Response $response): void
    {
        $userId = $request->getUserId();

        // ── Remove avatar ─────────────────────────────────────────────────────
        $data = $request->getJson();
        if (!empty($data['remove'])) {
            $user = $this->userRepo->findById((int) $userId);
            if ($user && $user['avatar']) {
                $oldPath = BASE_PATH . '/storage/avatars/' . $user['avatar'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $this->userRepo->update((int) $userId, ['avatar' => null, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $this->cache->delete("user_profile_{$userId}");
            $response->json(['success' => true, 'message' => 'Avatar removed.', 'avatar' => null]);
            return;
        }

        // ── Upload avatar ─────────────────────────────────────────────────────
        $file = $request->getFile('avatar');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $response->json(['success' => false, 'message' => 'No file uploaded or upload error.'], 422);
            return;
        }

        if ($file['size'] > self::AVATAR_MAX_BYTES) {
            $response->json(['success' => false, 'message' => 'Avatar file too large (max 5 MB).'], 422);
            return;
        }

        // Validate MIME by reading magic bytes (not trusting $_FILES['type'])
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::AVATAR_MIME_TYPES, true)) {
            $response->json(['success' => false, 'message' => 'Invalid image type. Allowed: JPEG, PNG, WebP, GIF.'], 422);
            return;
        }

        // Resize & convert to JPEG (max 512×512) using GD
        $resized = $this->resizeAvatar($file['tmp_name'], $mime);
        if (!$resized) {
            $response->json(['success' => false, 'message' => 'Image processing failed.'], 500);
            return;
        }

        // Save to /storage/avatars/{userId}_{hash}.jpg
        $avatarDir = BASE_PATH . '/storage/avatars';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0755, true);
        }

        $filename = $userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
        $destPath = $avatarDir . '/' . $filename;

        if (!imagejpeg($resized, $destPath, 85)) {
            imagedestroy($resized);
            $response->json(['success' => false, 'message' => 'Failed to save avatar.'], 500);
            return;
        }
        imagedestroy($resized);

        // Delete old avatar file
        $user = $this->userRepo->findById((int) $userId);
        if ($user && $user['avatar']) {
            $oldPath = $avatarDir . '/' . $user['avatar'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $this->userRepo->update((int) $userId, [
            'avatar'     => $filename,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->cache->delete("user_profile_{$userId}");

        $response->json([
            'success' => true,
            'message' => 'Avatar updated.',
            'avatar'  => $filename,
        ]);
    }

    // =========================================================================
    // PRIVACY SETTINGS  —  PATCH /api/v1/users/privacy
    // =========================================================================

    /**
     * Control who can find the user, see last seen, avatar, bio, phone.
     *
     * privacy_search: 'username' | 'phone' | 'email'  (AND logic)
     * last_seen:      'everyone' | 'contacts' | 'nobody'
     * avatar:         'everyone' | 'contacts' | 'nobody'
     * phone:          'everyone' | 'contacts' | 'nobody'
     */
    public function updatePrivacy(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'privacy_search'   => ['nullable', 'in:username,phone,email'],
            'privacy_last_seen'=> ['nullable', 'in:everyone,contacts,nobody'],
            'privacy_avatar'   => ['nullable', 'in:everyone,contacts,nobody'],
            'privacy_phone'    => ['nullable', 'in:everyone,contacts,nobody'],
            'privacy_bio'      => ['nullable', 'in:everyone,contacts,nobody'],
            'privacy_groups'   => ['nullable', 'in:everyone,contacts,nobody'], // who can add to groups
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $fields = [
            'privacy_search', 'privacy_last_seen', 'privacy_avatar',
            'privacy_phone', 'privacy_bio', 'privacy_groups',
        ];

        $updates = [];
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $updates[$f] = $data[$f];
            }
        }

        if (empty($updates)) {
            $response->json(['success' => false, 'message' => 'No changes provided.'], 422);
            return;
        }

        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->userRepo->update((int) $userId, $updates);
        $this->cache->delete("user_profile_{$userId}");

        $response->json([
            'success'  => true,
            'message'  => 'Privacy settings updated.',
            'privacy'  => $this->getPrivacySnapshot((int) $userId),
        ]);
    }

    // =========================================================================
    // CONTACTS  —  GET /api/v1/users/contacts
    // =========================================================================

    /**
     * List all contacts of the authenticated user.
     */
    public function contacts(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $page    = max(1, (int) ($query['page']    ?? 1));
        $perPage = min(100, max(10, (int) ($query['per_page'] ?? 50)));
        $search  = trim($query['search'] ?? '');

        $result = $this->userRepo->getContacts((int) $userId, [
            'page'     => $page,
            'per_page' => $perPage,
            'search'   => $search,  // search by username/phone/email only
        ]);

        $items = array_map(function ($c) use ($userId) {
            return [
                'user_id'    => (int)    $c['contact_id'],
                'username'   => (string) $c['username'],
                'first_name' => (string) $c['first_name'],
                'last_name'  => $c['last_name']  ?? null,
                'avatar'     => $c['avatar']     ?? null,
                'phone'      => $c['phone']      ?? null,
                'online'     => $this->getOnlineStatus((int) $c['contact_id']),
                'added_at'   => (string) $c['added_at'],
            ];
        }, $result['items']);

        $response->json([
            'success'    => true,
            'contacts'   => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/v1/users/contacts/add
     * Add a user to contacts by username, phone, or email.
     * NOT by name (privacy rule).
     */
    public function addContact(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        // Must provide at least one identifier
        if (empty($data['username']) && empty($data['phone']) && empty($data['email'])) {
            $response->json([
                'success' => false,
                'message' => 'Provide username, phone, or email to add a contact.',
            ], 422);
            return;
        }

        // Find target user
        $target = null;
        if (!empty($data['username'])) {
            $target = $this->userRepo->findByUsername(strtolower(trim($data['username'])));
        } elseif (!empty($data['phone'])) {
            $target = $this->userRepo->findByPhone(trim($data['phone']));
        } elseif (!empty($data['email'])) {
            $target = $this->userRepo->findByEmail(strtolower(trim($data['email'])));
        }

        if (!$target || !$target['is_active']) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        $targetId = (int) $target['id'];

        if ($targetId === (int) $userId) {
            $response->json(['success' => false, 'message' => 'You cannot add yourself.'], 422);
            return;
        }

        // Check if blocked
        if ($this->userRepo->isBlocked((int) $userId, $targetId) ||
            $this->userRepo->isBlocked($targetId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        if ($this->userRepo->isContact((int) $userId, $targetId)) {
            $response->json(['success' => false, 'message' => 'Already in your contacts.'], 409);
            return;
        }

        $this->userRepo->addContact((int) $userId, $targetId);

        $response->json([
            'success' => true,
            'message' => 'Contact added.',
            'user'    => $this->formatPublicProfile($target, (int) $userId),
        ]);
    }

    /**
     * DELETE /api/v1/users/contacts/{id}
     * Remove a contact.
     */
    public function removeContact(Request $request, Response $response, int $contactId): void
    {
        $userId = $request->getUserId();

        if (!$this->userRepo->isContact((int) $userId, $contactId)) {
            $response->json(['success' => false, 'message' => 'Contact not found.'], 404);
            return;
        }

        $this->userRepo->removeContact((int) $userId, $contactId);

        $response->json(['success' => true, 'message' => 'Contact removed.']);
    }

    // =========================================================================
    // SEARCH USERS  —  GET /api/v1/users/search
    // =========================================================================

    /**
     * Search for users by username, phone, or email ONLY.
     * Name-based search is explicitly forbidden (privacy policy).
     */
    public function search(Request $request, Response $response): void
    {
        $myId  = $request->getUserId();
        $query = $request->getQuery();

        $rules = ['q' => ['required', 'string', 'min:2', 'max:64']];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $q       = trim($query['q']);
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(30, max(5, (int) ($query['per_page'] ?? 20)));

        // Detect search mode: phone / email / username
        $mode = 'username';
        if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
            $mode = 'email';
        } elseif (preg_match('/^\+?[0-9]{7,15}$/', $q)) {
            $mode = 'phone';
        }

        $result = $this->userRepo->search($q, $mode, [
            'page'       => $page,
            'per_page'   => $perPage,
            'exclude_id' => (int) $myId,
        ]);

        // Filter out users who have blocked the searcher
        $users = array_filter($result['items'], function ($u) use ($myId) {
            return !$this->userRepo->isBlocked((int) $u['id'], (int) $myId);
        });

        $response->json([
            'success' => true,
            'mode'    => $mode,
            'users'   => array_values(array_map(
                fn($u) => $this->formatPublicProfile($u, (int) $myId),
                $users
            )),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // BLOCK / UNBLOCK  —  POST /api/v1/users/block | unblock
    // =========================================================================

    /**
     * Block a user: they can no longer message you or see your profile.
     */
    public function block(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = ['user_id' => ['required', 'integer', 'min:1']];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $targetId = (int) $data['user_id'];

        if ($targetId === (int) $userId) {
            $response->json(['success' => false, 'message' => 'You cannot block yourself.'], 422);
            return;
        }

        if ($this->userRepo->isBlocked((int) $userId, $targetId)) {
            $response->json(['success' => false, 'message' => 'User is already blocked.'], 409);
            return;
        }

        $this->userRepo->blockUser((int) $userId, $targetId);

        // Auto-remove from contacts (both directions)
        $this->userRepo->removeContact((int) $userId, $targetId);
        $this->userRepo->removeContact($targetId, (int) $userId);

        $response->json(['success' => true, 'message' => 'User blocked.']);
    }

    /**
     * Unblock a previously blocked user.
     */
    public function unblock(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = ['user_id' => ['required', 'integer', 'min:1']];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $targetId = (int) $data['user_id'];

        if (!$this->userRepo->isBlocked((int) $userId, $targetId)) {
            $response->json(['success' => false, 'message' => 'User is not blocked.'], 404);
            return;
        }

        $this->userRepo->unblockUser((int) $userId, $targetId);

        $response->json(['success' => true, 'message' => 'User unblocked.']);
    }

    /**
     * GET /api/v1/users/blocked
     * List all users blocked by the authenticated user.
     */
    public function blockedList(Request $request, Response $response): void
    {
        $userId = $request->getUserId();

        $list = $this->userRepo->getBlockedList((int) $userId);

        $response->json([
            'success' => true,
            'blocked' => array_map(fn($u) => [
                'user_id'    => (int)    $u['blocked_id'],
                'username'   => (string) $u['username'],
                'first_name' => (string) $u['first_name'],
                'last_name'  => $u['last_name'] ?? null,
                'avatar'     => $u['avatar']    ?? null,
                'blocked_at' => (string) $u['blocked_at'],
            ], $list),
        ]);
    }

    // =========================================================================
    // ONLINE STATUS  —  POST /api/v1/users/heartbeat
    // =========================================================================

    /**
     * Client calls this every ~30s to indicate it is still active.
     * Updates last_seen + writes online flag to cache (60s TTL).
     */
    public function heartbeat(Request $request, Response $response): void
    {
        $userId = $request->getUserId();

        // Update last_seen in DB (throttled: once per 60s via cache)
        $throttleKey = "heartbeat_db_{$userId}";
        if (!$this->cache->get($throttleKey)) {
            $this->userRepo->updateLastSeen((int) $userId);
            $this->cache->set($throttleKey, 1, 60);
        }

        // Mark online in cache (60s TTL — client must re-heartbeat within 60s)
        $this->cache->set("online_{$userId}", gmdate('Y-m-d H:i:s'), 60);

        $response->json(['success' => true, 'ts' => time()]);
    }

    /**
     * GET /api/v1/users/online?ids[]=1&ids[]=2&ids[]=3
     * Batch-check online status for a list of user IDs.
     */
    public function onlineStatus(Request $request, Response $response): void
    {
        $myId  = $request->getUserId();
        $query = $request->getQuery();

        $ids = array_unique(array_map('intval', (array) ($query['ids'] ?? [])));
        if (empty($ids) || count($ids) > 200) {
            $response->json(['success' => false, 'message' => 'Provide between 1 and 200 user IDs.'], 422);
            return;
        }

        $statuses = [];
        foreach ($ids as $uid) {
            $statuses[$uid] = $this->getOnlineStatus($uid);
        }

        $response->json(['success' => true, 'statuses' => $statuses]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Build the authenticated user's own full profile (includes private fields).
     */
    private function formatOwnProfile(array $user): array
    {
        return [
            'id'               => (int)    $user['id'],
            'username'         => (string) $user['username'],
            'first_name'       => (string) $user['first_name'],
            'last_name'        => $user['last_name'] ?? null,
            'bio'              => $user['bio']        ?? null,
            'avatar'           => $user['avatar']     ?? null,
            'email'            => (string) $user['email'],
            'phone'            => (string) $user['phone'],
            'public_key'       => (string) $user['public_key'],
            'privacy_search'   => $user['privacy_search']    ?? 'username',
            'privacy_last_seen'=> $user['privacy_last_seen'] ?? 'everyone',
            'privacy_avatar'   => $user['privacy_avatar']    ?? 'everyone',
            'privacy_phone'    => $user['privacy_phone']     ?? 'contacts',
            'privacy_bio'      => $user['privacy_bio']       ?? 'everyone',
            'privacy_groups'   => $user['privacy_groups']    ?? 'everyone',
            'is_active'        => (bool) $user['is_active'],
            'last_seen'        => $user['last_seen'] ?? null,
            'created_at'       => (string) $user['created_at'],
        ];
    }

    /**
     * Build another user's public profile (respects their privacy settings).
     */
    private function formatPublicProfile(array $user, int $viewerId): array
    {
        $isContact = $this->userRepo->isContact($viewerId, (int) $user['id']);

        // Last seen: respect privacy
        $lastSeen = null;
        $privLs   = $user['privacy_last_seen'] ?? 'everyone';
        if ($privLs === 'everyone' ||
            ($privLs === 'contacts' && $isContact)
        ) {
            // Check online cache first
            $onlineTs = $this->cache->get("online_{$user['id']}");
            $lastSeen = $onlineTs ?: ($user['last_seen'] ?? null);
        }

        // Phone: respect privacy
        $phone   = null;
        $privPh  = $user['privacy_phone'] ?? 'contacts';
        if ($privPh === 'everyone' || ($privPh === 'contacts' && $isContact)) {
            $phone = $user['phone'] ?? null;
        }

        // Bio: respect privacy
        $bio     = null;
        $privBio = $user['privacy_bio'] ?? 'everyone';
        if ($privBio === 'everyone' || ($privBio === 'contacts' && $isContact)) {
            $bio = $user['bio'] ?? null;
        }

        // Avatar: respect privacy
        $avatar   = null;
        $privAv   = $user['privacy_avatar'] ?? 'everyone';
        if ($privAv === 'everyone' || ($privAv === 'contacts' && $isContact)) {
            $avatar = $user['avatar'] ?? null;
        }

        return [
            'id'         => (int)    $user['id'],
            'username'   => (string) $user['username'],
            'first_name' => (string) $user['first_name'],
            'last_name'  => $user['last_name'] ?? null,
            'bio'        => $bio,
            'avatar'     => $avatar,
            'phone'      => $phone,
            'public_key' => (string) ($user['public_key'] ?? ''),
            'online'     => $this->getOnlineStatus((int) $user['id']),
            'last_seen'  => $lastSeen,
            'is_contact' => $isContact,
        ];
    }

    /**
     * Return online status: true = online now, false|string = last_seen timestamp.
     */
    private function getOnlineStatus(int $userId): bool|string
    {
        $ts = $this->cache->get("online_{$userId}");
        if ($ts) {
            return true; // actively online (heartbeat within 60s)
        }
        $user = $this->userRepo->findById($userId);
        return $user['last_seen'] ?? false;
    }

    /**
     * Return current privacy snapshot for a user.
     */
    private function getPrivacySnapshot(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        return [
            'privacy_search'    => $user['privacy_search']    ?? 'username',
            'privacy_last_seen' => $user['privacy_last_seen'] ?? 'everyone',
            'privacy_avatar'    => $user['privacy_avatar']    ?? 'everyone',
            'privacy_phone'     => $user['privacy_phone']     ?? 'contacts',
            'privacy_bio'       => $user['privacy_bio']       ?? 'everyone',
            'privacy_groups'    => $user['privacy_groups']    ?? 'everyone',
        ];
    }

    /**
     * Resize an uploaded image to max 512×512 using GD.
     * Returns a GD image resource or false on failure.
     */
    private function resizeAvatar(string $tmpPath, string $mime): \GdImage|false
    {
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            'image/gif'  => @imagecreatefromgif($tmpPath),
            default      => false,
        };
        if (!$src) return false;

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $max  = 512;

        if ($srcW <= $max && $srcH <= $max) {
            // No resize needed — just return as-is converted to GD
            $out = imagecreatetruecolor($srcW, $srcH);
            imagecopy($out, $src, 0, 0, 0, 0, $srcW, $srcH);
            imagedestroy($src);
            return $out;
        }

        // Scale proportionally
        $ratio  = min($max / $srcW, $max / $srcH);
        $dstW   = (int) round($srcW * $ratio);
        $dstH   = (int) round($srcH * $ratio);

        $dst = imagecreatetruecolor($dstW, $dstH);

        // Preserve transparency for PNG/WebP
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        return $dst;
    }
}

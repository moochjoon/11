<?php

declare(strict_types=1);

namespace Namak\Controllers;

use Namak\Core\Request;
use Namak\Core\Response;
use Namak\Services\Auth;
use Namak\Services\Cache;
use Namak\Services\Validation;
use Namak\Repositories\ChatRepository;
use Namak\Repositories\UserRepository;
use Namak\Repositories\MessageRepository;

/**
 * ChatController
 *
 * Handles all chat-related operations:
 * private chats, groups, channels, archive, search, pin, mute, etc.
 *
 * Chat types supported:
 *   - private   : 1-to-1 conversation
 *   - group     : up to 200,000 members
 *   - channel   : broadcast only, unlimited subscribers
 *   - secret    : E2E encrypted, ephemeral, device-bound
 */
class ChatController
{
    private ChatRepository    $chatRepo;
    private UserRepository    $userRepo;
    private MessageRepository $messageRepo;
    private Validation        $validation;
    private Cache             $cache;

    public function __construct(
        ChatRepository    $chatRepo,
        UserRepository    $userRepo,
        MessageRepository $messageRepo,
        Validation        $validation,
        Cache             $cache
    ) {
        $this->chatRepo    = $chatRepo;
        $this->userRepo    = $userRepo;
        $this->messageRepo = $messageRepo;
        $this->validation  = $validation;
        $this->cache       = $cache;
    }

    // =========================================================================
    // LIST  —  GET /api/v1/chats/list
    // =========================================================================

    /**
     * Return all chats for the authenticated user, sorted by last activity.
     * Includes unread count, last message preview, and peer info.
     */
    public function list(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $page    = max(1, (int) ($query['page']    ?? 1));
        $perPage = min(50, max(10, (int) ($query['per_page'] ?? 20)));
        $type    = $query['type']   ?? null;   // private|group|channel|secret|archived
        $search  = trim($query['search'] ?? '');

        // Cache key per user (invalidated on new message)
        $cacheKey = "chat_list_{$userId}_p{$page}_t{$type}";
        if (empty($search)) {
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                $response->json(json_decode($cached, true));
                return;
            }
        }

        $result = $this->chatRepo->listForUser((int) $userId, [
            'page'     => $page,
            'per_page' => $perPage,
            'type'     => $type,
            'search'   => $search,
            'archived' => ($type === 'archived'),
        ]);

        $payload = [
            'success' => true,
            'chats'   => array_map(
                fn($chat) => $this->formatChatItem($chat, (int) $userId),
                $result['items']
            ),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ];

        if (empty($search)) {
            $this->cache->set($cacheKey, json_encode($payload), 30); // 30-second cache
        }

        $response->json($payload);
    }

    // =========================================================================
    // CREATE  —  POST /api/v1/chats/create
    // =========================================================================

    /**
     * Create a new chat (private, group, channel, or secret).
     */
    public function create(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'type'        => ['required', 'in:private,group,channel,secret'],
            'title'       => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'username'    => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'members'     => ['nullable', 'array'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $type = $data['type'];

        // ── Private / Secret chat ─────────────────────────────────────────────
        if (in_array($type, ['private', 'secret'], true)) {
            $members = $data['members'] ?? [];
            if (count($members) !== 1) {
                $response->json([
                    'success' => false,
                    'message' => 'Private/secret chat requires exactly one recipient.',
                ], 422);
                return;
            }

            $peerId = (int) $members[0];
            if ($peerId === (int) $userId) {
                $response->json([
                    'success' => false,
                    'message' => 'You cannot start a chat with yourself.',
                ], 422);
                return;
            }

            $peer = $this->userRepo->findById($peerId);
            if (!$peer || !$peer['is_active']) {
                $response->json(['success' => false, 'message' => 'User not found.'], 404);
                return;
            }

            // Check if private chat already exists
            if ($type === 'private') {
                $existing = $this->chatRepo->findPrivateChat((int) $userId, $peerId);
                if ($existing) {
                    $response->json([
                        'success' => true,
                        'chat'    => $this->formatChatItem($existing, (int) $userId),
                        'existed' => true,
                    ]);
                    return;
                }
            }

            $chatId = $this->chatRepo->create([
                'type'        => $type,
                'title'       => null,
                'description' => null,
                'username'    => null,
                'avatar'      => null,
                'created_by'  => (int) $userId,
                'is_ephemeral'=> ($type === 'secret') ? 1 : 0, // secret = ephemeral E2E
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);

            // Add both members
            $this->chatRepo->addMember($chatId, (int) $userId, 'member');
            $this->chatRepo->addMember($chatId, $peerId, 'member');

            $this->invalidateChatListCache((int) $userId);
            $this->invalidateChatListCache($peerId);

            $chat = $this->chatRepo->findById($chatId, (int) $userId);
            $response->json([
                'success' => true,
                'chat'    => $this->formatChatItem($chat, (int) $userId),
            ], 201);
            return;
        }

        // ── Group / Channel ───────────────────────────────────────────────────
        if (!isset($data['title']) || trim($data['title']) === '') {
            $response->json([
                'success' => false,
                'message' => 'Title is required for groups and channels.',
                'field'   => 'title',
            ], 422);
            return;
        }

        // Username uniqueness for public groups/channels
        if (!empty($data['username'])) {
            if ($this->chatRepo->usernameExists($data['username'])) {
                $response->json([
                    'success' => false,
                    'message' => 'This username is already taken.',
                    'field'   => 'username',
                ], 409);
                return;
            }
        }

        $chatId = $this->chatRepo->create([
            'type'        => $type,
            'title'       => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'username'    => !empty($data['username']) ? strtolower(trim($data['username'])) : null,
            'avatar'      => null,
            'created_by'  => (int) $userId,
            'is_ephemeral'=> 0,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Creator = owner/admin
        $creatorRole = ($type === 'channel') ? 'owner' : 'admin';
        $this->chatRepo->addMember($chatId, (int) $userId, $creatorRole);

        // Add initial members
        $members = array_unique(array_map('intval', $data['members'] ?? []));
        foreach ($members as $memberId) {
            if ($memberId === (int) $userId) continue;
            $memberUser = $this->userRepo->findById($memberId);
            if ($memberUser && $memberUser['is_active']) {
                $this->chatRepo->addMember($chatId, $memberId, 'member');
                $this->invalidateChatListCache($memberId);
            }
        }

        $this->invalidateChatListCache((int) $userId);

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        $response->json([
            'success' => true,
            'chat'    => $this->formatChatItem($chat, (int) $userId),
        ], 201);
    }

    // =========================================================================
    // GET SINGLE  —  GET /api/v1/chats/{id}
    // =========================================================================

    public function show(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        if (!$chat) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $response->json([
            'success' => true,
            'chat'    => $this->formatChatItem($chat, (int) $userId),
        ]);
    }

    // =========================================================================
    // UPDATE  —  PATCH /api/v1/chats/{id}
    // =========================================================================

    /**
     * Update group/channel info (title, description, username, avatar).
     * Only admins/owners can update.
     */
    public function update(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        if (!$chat) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        if (in_array($chat['type'], ['private', 'secret'], true)) {
            $response->json(['success' => false, 'message' => 'Cannot edit private chats.'], 403);
            return;
        }

        $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
        if (!in_array($role, ['admin', 'owner'], true)) {
            $response->json(['success' => false, 'message' => 'Permission denied.'], 403);
            return;
        }

        $rules = [
            'title'       => ['nullable', 'string', 'min:1', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'username'    => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $updates = [];
        if (isset($data['title']))       $updates['title']       = trim($data['title']);
        if (isset($data['description'])) $updates['description'] = trim($data['description']);
        if (isset($data['username'])) {
            $newUsername = strtolower(trim($data['username']));
            if ($newUsername !== $chat['username'] && $this->chatRepo->usernameExists($newUsername)) {
                $response->json([
                    'success' => false,
                    'message' => 'Username already taken.',
                    'field'   => 'username',
                ], 409);
                return;
            }
            $updates['username'] = $newUsername;
        }

        if (empty($updates)) {
            $response->json(['success' => false, 'message' => 'No changes provided.'], 422);
            return;
        }

        $this->chatRepo->update($chatId, $updates);
        $this->invalidateChatListCache((int) $userId);

        $updated = $this->chatRepo->findById($chatId, (int) $userId);
        $response->json([
            'success' => true,
            'message' => 'Chat updated.',
            'chat'    => $this->formatChatItem($updated, (int) $userId),
        ]);
    }

    // =========================================================================
    // DELETE  —  DELETE /api/v1/chats/{id}
    // =========================================================================

    /**
     * Leave a group/channel, or delete a private chat for current user.
     * Owners can fully delete groups/channels.
     */
    public function delete(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        if (!$chat || !$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $deleteForAll = ($data['delete_for_all'] ?? false) === true;

        if ($deleteForAll) {
            $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
            if ($role !== 'owner' && $chat['created_by'] !== (int) $userId) {
                $response->json(['success' => false, 'message' => 'Only the owner can delete this chat.'], 403);
                return;
            }
            $this->chatRepo->deleteChat($chatId);
        } else {
            // Just remove this user from the chat
            $this->chatRepo->removeMember($chatId, (int) $userId);
        }

        $this->invalidateChatListCache((int) $userId);

        $response->json([
            'success' => true,
            'message' => $deleteForAll ? 'Chat deleted for everyone.' : 'You left the chat.',
        ]);
    }

    // =========================================================================
    // ARCHIVE  —  POST /api/v1/chats/archive
    // =========================================================================

    /**
     * Archive or un-archive a chat for the current user.
     */
    public function archive(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'chat_id'  => ['required', 'integer', 'min:1'],
            'archived' => ['required', 'boolean'],
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId = (int) $data['chat_id'];

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $archived = (bool) $data['archived'];
        $this->chatRepo->setArchived($chatId, (int) $userId, $archived);
        $this->invalidateChatListCache((int) $userId);

        $response->json([
            'success'  => true,
            'message'  => $archived ? 'Chat archived.' : 'Chat unarchived.',
            'chat_id'  => $chatId,
            'archived' => $archived,
        ]);
    }

    // =========================================================================
    // SEARCH  —  GET /api/v1/chats/search
    // =========================================================================

    /**
     * Search public groups/channels by username or title.
     * Privacy: private users NOT searchable by name — only username/phone/email.
     */
    public function search(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $rules = ['q' => ['required', 'string', 'min:2', 'max:64']];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $q       = trim($query['q']);
        $type    = $query['type'] ?? null; // group|channel|null=all
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(30, max(5, (int) ($query['per_page'] ?? 20)));

        $results = $this->chatRepo->search($q, [
            'type'     => $type,
            'page'     => $page,
            'per_page' => $perPage,
            'user_id'  => (int) $userId,
        ]);

        $response->json([
            'success' => true,
            'results' => array_map(
                fn($chat) => $this->formatChatItem($chat, (int) $userId),
                $results['items']
            ),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $results['total'],
                'total_pages' => (int) ceil($results['total'] / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // MEMBERS  —  Group/Channel member management
    // =========================================================================

    /**
     * GET /api/v1/chats/{id}/members
     * List members of a group or channel.
     */
    public function members(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $query   = $request->getQuery();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(20, (int) ($query['per_page'] ?? 50)));

        $result  = $this->chatRepo->getMembers($chatId, $page, $perPage);

        $members = array_map(function ($m) {
            return [
                'user_id'    => (int) $m['user_id'],
                'username'   => $m['username'],
                'first_name' => $m['first_name'],
                'last_name'  => $m['last_name'] ?? null,
                'avatar'     => $m['avatar'] ?? null,
                'role'       => $m['role'],      // owner|admin|member
                'joined_at'  => $m['joined_at'],
            ];
        }, $result['items']);

        $response->json([
            'success' => true,
            'members' => $members,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/v1/chats/{id}/members/add
     * Add a member to a group (admin only).
     */
    public function addMember(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        if (!$chat || in_array($chat['type'], ['private', 'secret'], true)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
        if (!in_array($role, ['admin', 'owner'], true)) {
            $response->json(['success' => false, 'message' => 'Permission denied.'], 403);
            return;
        }

        $rules = ['user_id' => ['required', 'integer', 'min:1']];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $targetId = (int) $data['user_id'];
        $target   = $this->userRepo->findById($targetId);
        if (!$target || !$target['is_active']) {
            $response->json(['success' => false, 'message' => 'User not found.'], 404);
            return;
        }

        if ($this->chatRepo->isMember($chatId, $targetId)) {
            $response->json(['success' => false, 'message' => 'User is already a member.'], 409);
            return;
        }

        $this->chatRepo->addMember($chatId, $targetId, 'member');
        $this->invalidateChatListCache($targetId);

        $response->json(['success' => true, 'message' => 'Member added.']);
    }

    /**
     * DELETE /api/v1/chats/{id}/members/{memberId}
     * Remove a member from a group/channel (admin only).
     */
    public function removeMember(Request $request, Response $response, int $chatId, int $memberId): void
    {
        $userId = $request->getUserId();

        $chat = $this->chatRepo->findById($chatId, (int) $userId);
        if (!$chat || in_array($chat['type'], ['private', 'secret'], true)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        // Self-leave is always allowed
        if ($memberId !== (int) $userId) {
            $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
            if (!in_array($role, ['admin', 'owner'], true)) {
                $response->json(['success' => false, 'message' => 'Permission denied.'], 403);
                return;
            }

            // Cannot remove the owner
            $targetRole = $this->chatRepo->getMemberRole($chatId, $memberId);
            if ($targetRole === 'owner') {
                $response->json(['success' => false, 'message' => 'Cannot remove the owner.'], 403);
                return;
            }
        }

        $this->chatRepo->removeMember($chatId, $memberId);
        $this->invalidateChatListCache($memberId);
        $this->invalidateChatListCache((int) $userId);

        $response->json([
            'success' => true,
            'message' => ($memberId === (int) $userId) ? 'You left the chat.' : 'Member removed.',
        ]);
    }

    /**
     * PATCH /api/v1/chats/{id}/members/{memberId}/role
     * Promote/demote a member (owner only).
     */
    public function updateMemberRole(Request $request, Response $response, int $chatId, int $memberId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $myRole = $this->chatRepo->getMemberRole($chatId, (int) $userId);
        if ($myRole !== 'owner') {
            $response->json(['success' => false, 'message' => 'Only the owner can change roles.'], 403);
            return;
        }

        $rules = ['role' => ['required', 'in:admin,member']];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        if (!$this->chatRepo->isMember($chatId, $memberId)) {
            $response->json(['success' => false, 'message' => 'Member not found.'], 404);
            return;
        }

        $this->chatRepo->updateMemberRole($chatId, $memberId, $data['role']);

        $response->json([
            'success' => true,
            'message' => 'Role updated.',
            'user_id' => $memberId,
            'role'    => $data['role'],
        ]);
    }

    // =========================================================================
    // MUTE  —  POST /api/v1/chats/{id}/mute
    // =========================================================================

    /**
     * Mute or unmute notifications for a chat.
     * Duration: seconds (0 = unmute, -1 = forever).
     */
    public function mute(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $rules = ['duration' => ['required', 'integer', 'min:-1']];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $duration  = (int) $data['duration'];
        $muteUntil = null;

        if ($duration > 0) {
            $muteUntil = gmdate('Y-m-d H:i:s', time() + $duration);
        } elseif ($duration === -1) {
            $muteUntil = '9999-12-31 23:59:59'; // forever
        }
        // duration === 0 → unmute → muteUntil stays null

        $this->chatRepo->setMute($chatId, (int) $userId, $muteUntil);

        $response->json([
            'success'    => true,
            'muted'      => $duration !== 0,
            'mute_until' => $muteUntil,
        ]);
    }

    // =========================================================================
    // PIN MESSAGE  —  POST /api/v1/chats/{id}/pin
    // =========================================================================

    public function pinMessage(Request $request, Response $response, int $chatId): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
        $chat = $this->chatRepo->findById($chatId, (int) $userId);

        // In private chats, any member can pin; in groups/channels only admins
        if (!in_array($chat['type'], ['private', 'secret'], true)
            && !in_array($role, ['admin', 'owner'], true)
        ) {
            $response->json(['success' => false, 'message' => 'Permission denied.'], 403);
            return;
        }

        $rules = [
            'message_id' => ['required', 'integer', 'min:1'],
            'unpin'      => ['nullable', 'boolean'],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $messageId = (int) $data['message_id'];
        $unpin     = (bool) ($data['unpin'] ?? false);

        // Verify message belongs to this chat
        $message = $this->messageRepo->findById($messageId);
        if (!$message || (int) $message['chat_id'] !== $chatId) {
            $response->json(['success' => false, 'message' => 'Message not found.'], 404);
            return;
        }

        $this->chatRepo->setPinnedMessage($chatId, $unpin ? null : $messageId);

        $response->json([
            'success'    => true,
            'message'    => $unpin ? 'Message unpinned.' : 'Message pinned.',
            'pinned_msg' => $unpin ? null : $messageId,
        ]);
    }

    // =========================================================================
    // POLLING ENDPOINT  —  GET /api/v1/chats/poll
    // =========================================================================

    /**
     * Long-polling endpoint for real-time updates.
     * Returns new messages, typing indicators, online status, etc.
     * Client calls this every ~2 seconds.
     *
     * @see lib/services/Cache.php for state storage
     */
    public function poll(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $since      = (int) ($query['since'] ?? 0);        // last known message ID
        $chatId     = isset($query['chat_id']) ? (int) $query['chat_id'] : null;
        $maxWait    = min(25, (int) ($query['wait'] ?? 2)); // seconds to long-poll

        $deadline  = time() + $maxWait;
        $updates   = [];

        do {
            $updates = $this->collectPollingUpdates((int) $userId, $since, $chatId);
            if (!empty($updates)) break;
            if (time() >= $deadline) break;
            sleep(1); // lightweight polling interval
        } while (true);

        $response->json([
            'success' => true,
            'updates' => $updates,
            'ts'      => time(),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Collect pending updates for polling response.
     */
    private function collectPollingUpdates(int $userId, int $since, ?int $chatId): array
    {
        $updates = [];

        // New messages
        $newMessages = $this->messageRepo->getNewMessages($userId, $since, $chatId);
        foreach ($newMessages as $msg) {
            $updates[] = [
                'type'    => 'new_message',
                'payload' => $msg,
            ];
        }

        // Typing indicators (stored in cache with 5s TTL)
        $typingKey = "typing_{$userId}";
        $typing    = $this->cache->get($typingKey);
        if ($typing) {
            $updates[] = ['type' => 'typing', 'payload' => json_decode($typing, true)];
        }

        // Online status changes
        $onlineKey  = "online_changes_{$userId}";
        $onlineData = $this->cache->get($onlineKey);
        if ($onlineData) {
            $updates[] = ['type' => 'online_status', 'payload' => json_decode($onlineData, true)];
            $this->cache->delete($onlineKey);
        }

        return $updates;
    }

    /**
     * POST /api/v1/chats/{id}/typing
     * Broadcast a typing indicator for the current user.
     */
    public function typing(Request $request, Response $response, int $chatId): void
    {
        $userId  = $request->getUserId();

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false], 403);
            return;
        }

        // Get all chat members except sender
        $members = $this->chatRepo->getMemberIds($chatId);
        $user    = $this->userRepo->getPublicProfile((int) $userId);

        foreach ($members as $memberId) {
            if ((int) $memberId === (int) $userId) continue;
            $key = "typing_{$memberId}";
            $this->cache->set($key, json_encode([
                'chat_id'    => $chatId,
                'user_id'    => (int) $userId,
                'username'   => $user['username'],
                'first_name' => $user['first_name'],
            ]), 5); // 5-second TTL
        }

        $response->json(['success' => true]);
    }

    /**
     * Format a raw chat DB row into a consistent API response shape.
     */
    private function formatChatItem(array $chat, int $myUserId): array
    {
        $isPrivate = in_array($chat['type'], ['private', 'secret'], true);

        // For private/secret chats, resolve the peer's info
        $peerInfo = null;
        if ($isPrivate) {
            $peerId   = (int) ($chat['peer_id'] ?? 0);
            $peerInfo = $peerId ? $this->userRepo->getPublicProfile($peerId) : null;
        }

        $lastMsg = null;
        if (!empty($chat['last_message'])) {
            $lm      = is_array($chat['last_message'])
                ? $chat['last_message']
                : json_decode($chat['last_message'], true);
            $lastMsg = [
                'id'         => (int)    ($lm['id']         ?? 0),
                'text'       => (string) ($lm['text']       ?? ''),
                'type'       => (string) ($lm['type']       ?? 'text'),
                'sender_id'  => (int)    ($lm['sender_id']  ?? 0),
                'created_at' => (string) ($lm['created_at'] ?? ''),
                'is_mine'    => ((int) ($lm['sender_id'] ?? 0)) === $myUserId,
            ];
        }

        return [
            'id'            => (int)    $chat['id'],
            'type'          => (string) $chat['type'],
            'title'         => $isPrivate
                ? (($peerInfo['first_name'] ?? '') . ' ' . ($peerInfo['last_name'] ?? ''))
                : (string) ($chat['title'] ?? ''),
            'username'      => $isPrivate
                ? ($peerInfo['username'] ?? null)
                : ($chat['username'] ?? null),
            'avatar'        => $isPrivate
                ? ($peerInfo['avatar'] ?? null)
                : ($chat['avatar'] ?? null),
            'description'   => $chat['description'] ?? null,
            'peer'          => $peerInfo,
            'last_message'  => $lastMsg,
            'unread_count'  => (int) ($chat['unread_count']  ?? 0),
            'is_muted'      => !empty($chat['mute_until'])
                && strtotime($chat['mute_until']) > time(),
            'mute_until'    => $chat['mute_until'] ?? null,
            'is_archived'   => (bool) ($chat['is_archived']  ?? false),
            'is_pinned'     => (bool) ($chat['is_pinned']    ?? false),
            'pinned_msg_id' => isset($chat['pinned_message_id'])
                ? (int) $chat['pinned_message_id']
                : null,
            'members_count' => (int) ($chat['members_count'] ?? 0),
            'my_role'       => $chat['my_role'] ?? 'member',
            'is_ephemeral'  => (bool) ($chat['is_ephemeral'] ?? false),
            'created_at'    => (string) ($chat['created_at'] ?? ''),
        ];
    }

    /**
     * Bust per-user chat list cache (called after any mutation).
     */
    private function invalidateChatListCache(int $userId): void
    {
        // Wildcard-style: bust all pages
        foreach (['null', 'private', 'group', 'channel', 'secret', 'archived'] as $t) {
            for ($p = 1; $p <= 5; $p++) {
                $this->cache->delete("chat_list_{$userId}_p{$p}_t{$t}");
            }
        }
    }
}

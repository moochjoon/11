<?php

declare(strict_types=1);

namespace Namak\Controllers;

use Namak\Core\Request;
use Namak\Core\Response;
use Namak\Services\Cache;
use Namak\Services\Security;
use Namak\Services\Validation;
use Namak\Repositories\ChatRepository;
use Namak\Repositories\MessageRepository;
use Namak\Repositories\UserRepository;

/**
 * MessageController
 *
 * Handles all message operations:
 * send, get, edit, delete, pin, forward, react, read receipts.
 *
 * Ephemeral messages: rows older than 24h are purged from DB automatically.
 * Secret chat messages: E2E encrypted payload, server stores ciphertext only.
 */
class MessageController
{
    private MessageRepository $messageRepo;
    private ChatRepository    $chatRepo;
    private UserRepository    $userRepo;
    private Validation        $validation;
    private Security          $security;
    private Cache             $cache;

    // Supported message types
    private const ALLOWED_TYPES = [
        'text', 'image', 'video', 'audio', 'voice',
        'file', 'sticker', 'gif', 'location', 'contact',
    ];

    // Max text length
    private const MAX_TEXT_LENGTH = 4096;

    public function __construct(
        MessageRepository $messageRepo,
        ChatRepository    $chatRepo,
        UserRepository    $userRepo,
        Validation        $validation,
        Security          $security,
        Cache             $cache
    ) {
        $this->messageRepo = $messageRepo;
        $this->chatRepo    = $chatRepo;
        $this->userRepo    = $userRepo;
        $this->validation  = $validation;
        $this->security    = $security;
        $this->cache       = $cache;
    }

    // =========================================================================
    // SEND  —  POST /api/v1/messages/send
    // =========================================================================

    /**
     * Send a new message to a chat.
     *
     * For secret chats the client sends an E2E-encrypted ciphertext;
     * the server stores it opaquely and relays it to the peer.
     * The server NEVER decrypts secret-chat messages.
     */
    public function send(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        // ── Validation ────────────────────────────────────────────────────────
        $rules = [
            'chat_id'      => ['required', 'integer', 'min:1'],
            'type'         => ['required', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'text'         => ['nullable', 'string', 'max:' . self::MAX_TEXT_LENGTH],
            'media_id'     => ['nullable', 'integer', 'min:1'],
            'reply_to_id'  => ['nullable', 'integer', 'min:1'],
            'forward_from' => ['nullable', 'integer', 'min:1'],  // original message ID
            'is_encrypted' => ['nullable', 'boolean'],            // true for secret chats
            'cipher_text'  => ['nullable', 'string'],             // E2E payload
            'caption'      => ['nullable', 'string', 'max:1024'],
            'duration'     => ['nullable', 'integer', 'min:0'],   // audio/video seconds
            'location'     => ['nullable', 'array'],               // {lat, lng}
        ];

        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId = (int) $data['chat_id'];

        // ── Membership check ──────────────────────────────────────────────────
        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $chat = $this->chatRepo->findById($chatId, (int) $userId);

        // Channels: only admins/owners can post
        if ($chat['type'] === 'channel') {
            $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
            if (!in_array($role, ['admin', 'owner'], true)) {
                $response->json(['success' => false, 'message' => 'Only admins can post in channels.'], 403);
                return;
            }
        }

        // ── Secret chat: require cipher_text ──────────────────────────────────
        $isEncrypted = (bool) ($data['is_encrypted'] ?? false);
        if ($isEncrypted && empty($data['cipher_text'])) {
            $response->json([
                'success' => false,
                'message' => 'cipher_text is required for encrypted messages.',
                'field'   => 'cipher_text',
            ], 422);
            return;
        }

        // ── Content check (non-encrypted) ─────────────────────────────────────
        if (!$isEncrypted) {
            $hasContent = !empty($data['text'])
                || !empty($data['media_id'])
                || !empty($data['location'])
                || in_array($data['type'], ['sticker', 'gif', 'contact'], true);

            if (!$hasContent) {
                $response->json([
                    'success' => false,
                    'message' => 'Message must have text, media, or location.',
                ], 422);
                return;
            }
        }

        // ── Validate reply_to belongs to same chat ────────────────────────────
        $replyToId = null;
        if (!empty($data['reply_to_id'])) {
            $replied = $this->messageRepo->findById((int) $data['reply_to_id']);
            if ($replied && (int) $replied['chat_id'] === $chatId) {
                $replyToId = (int) $data['reply_to_id'];
            }
        }

        // ── Rate limit: 30 messages per minute per user ───────────────────────
        $rateKey = "msg_rate_{$userId}";
        if ((int) $this->cache->get($rateKey) >= 30) {
            $response->json([
                'success'     => false,
                'message'     => 'Slow down! Too many messages.',
                'retry_after' => 60,
            ], 429);
            return;
        }
        $this->cache->increment($rateKey, 1, 60);

        // ── Location payload ──────────────────────────────────────────────────
        $locationJson = null;
        if (!empty($data['location']) && is_array($data['location'])) {
            $lat = filter_var($data['location']['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lng = filter_var($data['location']['lng'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat !== false && $lng !== false) {
                $locationJson = json_encode(['lat' => $lat, 'lng' => $lng]);
            }
        }

        // ── Ephemeral TTL ─────────────────────────────────────────────────────
        // Secret chats: always ephemeral (24h)
        // Normal chats: ephemeral only if global setting says so
        $expiresAt = null;
        if ($chat['is_ephemeral'] || $isEncrypted) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400); // +24h
        }

        // ── Build message row ─────────────────────────────────────────────────
        $now = gmdate('Y-m-d H:i:s');
        $messageId = $this->messageRepo->create([
            'chat_id'      => $chatId,
            'sender_id'    => (int) $userId,
            'type'         => $data['type'],
            'text'         => $isEncrypted ? null : ($data['text'] ?? null),
            'cipher_text'  => $isEncrypted ? $data['cipher_text'] : null,
            'is_encrypted' => $isEncrypted ? 1 : 0,
            'media_id'     => !empty($data['media_id']) ? (int) $data['media_id'] : null,
            'caption'      => $data['caption'] ?? null,
            'duration'     => !empty($data['duration']) ? (int) $data['duration'] : null,
            'location'     => $locationJson,
            'reply_to_id'  => $replyToId,
            'forward_from' => !empty($data['forward_from']) ? (int) $data['forward_from'] : null,
            'is_pinned'    => 0,
            'is_edited'    => 0,
            'expires_at'   => $expiresAt,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        if (!$messageId) {
            $response->json(['success' => false, 'message' => 'Failed to send message.'], 500);
            return;
        }

        // ── Update chat's last_message and last_activity ──────────────────────
        $this->chatRepo->updateLastMessage($chatId, $messageId, $now);

        // ── Bust chat list cache for all members ──────────────────────────────
        $memberIds = $this->chatRepo->getMemberIds($chatId);
        foreach ($memberIds as $mid) {
            $this->invalidateChatListCache((int) $mid);
        }

        // ── Return the saved message ──────────────────────────────────────────
        $saved = $this->messageRepo->findById($messageId);

        $response->json([
            'success' => true,
            'message' => $this->formatMessage($saved, (int) $userId),
        ], 201);
    }

    // =========================================================================
    // GET  —  GET /api/v1/messages/get
    // =========================================================================

    /**
     * Retrieve paginated message history for a chat.
     * Supports cursor-based pagination (before_id / after_id).
     */
    public function get(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $rules = [
            'chat_id'   => ['required', 'integer', 'min:1'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id'  => ['nullable', 'integer', 'min:1'],
        ];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId   = (int) $query['chat_id'];
        $limit    = min(100, max(1, (int) ($query['limit']    ?? 50)));
        $beforeId = !empty($query['before_id']) ? (int) $query['before_id'] : null;
        $afterId  = !empty($query['after_id'])  ? (int) $query['after_id']  : null;

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $messages = $this->messageRepo->getHistory($chatId, [
            'limit'     => $limit,
            'before_id' => $beforeId,
            'after_id'  => $afterId,
            'user_id'   => (int) $userId,  // for read-receipt marking
        ]);

        // Mark messages as read (bulk update)
        $unreadIds = array_column(
            array_filter($messages, fn($m) => (int) $m['sender_id'] !== (int) $userId && !$m['is_read']),
            'id'
        );
        if (!empty($unreadIds)) {
            $this->messageRepo->markAsRead($unreadIds, (int) $userId);
            // Notify senders via cache (polling will pick it up)
            foreach ($messages as $msg) {
                if (in_array($msg['id'], $unreadIds, false)) {
                    $sKey = "read_receipt_{$msg['sender_id']}";
                    $existing = $this->cache->get($sKey);
                    $receipts = $existing ? json_decode($existing, true) : [];
                    $receipts[] = ['msg_id' => $msg['id'], 'reader_id' => (int) $userId];
                    $this->cache->set($sKey, json_encode($receipts), 60);
                }
            }
        }

        $formatted = array_map(
            fn($msg) => $this->formatMessage($msg, (int) $userId),
            $messages
        );

        $response->json([
            'success'  => true,
            'messages' => $formatted,
            'has_more' => count($messages) === $limit,
        ]);
    }

    // =========================================================================
    // EDIT  —  PATCH /api/v1/messages/edit
    // =========================================================================

    /**
     * Edit the text of a sent message (only sender, within 48h, non-encrypted).
     */
    public function edit(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'message_id' => ['required', 'integer', 'min:1'],
            'text'       => ['required', 'string', 'min:1', 'max:' . self::MAX_TEXT_LENGTH],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $messageId = (int) $data['message_id'];
        $message   = $this->messageRepo->findById($messageId);

        if (!$message) {
            $response->json(['success' => false, 'message' => 'Message not found.'], 404);
            return;
        }

        // Only the original sender can edit
        if ((int) $message['sender_id'] !== (int) $userId) {
            $response->json(['success' => false, 'message' => 'You can only edit your own messages.'], 403);
            return;
        }

        // Encrypted messages cannot be edited server-side
        if ($message['is_encrypted']) {
            $response->json(['success' => false, 'message' => 'Encrypted messages cannot be edited.'], 403);
            return;
        }

        // 48-hour edit window
        $createdTs = strtotime($message['created_at']);
        if (time() - $createdTs > 172800) {
            $response->json(['success' => false, 'message' => 'Edit window (48h) has expired.'], 403);
            return;
        }

        // Must be a text-containing message
        if (!in_array($message['type'], ['text', 'image', 'video', 'audio', 'file', 'voice'], true)) {
            $response->json(['success' => false, 'message' => 'This message type cannot be edited.'], 422);
            return;
        }

        $this->messageRepo->update($messageId, [
            'text'       => trim($data['text']),
            'is_edited'  => 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Bust chat list cache (last message preview may change)
        $memberIds = $this->chatRepo->getMemberIds((int) $message['chat_id']);
        foreach ($memberIds as $mid) {
            $this->invalidateChatListCache((int) $mid);
        }

        $updated = $this->messageRepo->findById($messageId);

        $response->json([
            'success' => true,
            'message' => $this->formatMessage($updated, (int) $userId),
        ]);
    }

    // =========================================================================
    // DELETE  —  DELETE /api/v1/messages/delete
    // =========================================================================

    /**
     * Delete one or more messages.
     * - Sender can always delete their own messages.
     * - Admins/owners can delete any message in their chat.
     * - delete_for_all = true removes from DB for everyone.
     * - delete_for_all = false soft-deletes only for the caller.
     */
    public function delete(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'message_ids'   => ['required', 'array', 'min:1'],
            'delete_for_all'=> ['nullable', 'boolean'],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $messageIds  = array_unique(array_map('intval', $data['message_ids']));
        $deleteForAll = (bool) ($data['delete_for_all'] ?? false);

        if (count($messageIds) > 100) {
            $response->json(['success' => false, 'message' => 'Cannot delete more than 100 messages at once.'], 422);
            return;
        }

        $deleted   = [];
        $forbidden = [];

        foreach ($messageIds as $msgId) {
            $msg = $this->messageRepo->findById($msgId);
            if (!$msg) continue;

            $chatId = (int) $msg['chat_id'];

            // Must be a member of the chat
            if (!$this->chatRepo->isMember($chatId, (int) $userId)) continue;

            $isMine = (int) $msg['sender_id'] === (int) $userId;
            $role   = $this->chatRepo->getMemberRole($chatId, (int) $userId);
            $isAdmin= in_array($role, ['admin', 'owner'], true);

            if (!$isMine && !$isAdmin) {
                $forbidden[] = $msgId;
                continue;
            }

            if ($deleteForAll) {
                $this->messageRepo->delete($msgId);
            } else {
                // Soft-delete: only hide for this user
                $this->messageRepo->softDelete($msgId, (int) $userId);
            }

            $deleted[] = $msgId;

            // Update chat's last_message if this was it
            $this->chatRepo->recalcLastMessage($chatId);

            // Bust cache
            $memberIds = $this->chatRepo->getMemberIds($chatId);
            foreach ($memberIds as $mid) {
                $this->invalidateChatListCache((int) $mid);
            }
        }

        $response->json([
            'success'   => true,
            'deleted'   => $deleted,
            'forbidden' => $forbidden,
        ]);
    }

    // =========================================================================
    // PIN  —  POST /api/v1/messages/pin
    // =========================================================================

    /**
     * Pin or unpin a message in a chat.
     * Group/channel: admins only. Private chat: any member.
     */
    public function pin(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

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

        $msg = $this->messageRepo->findById($messageId);
        if (!$msg) {
            $response->json(['success' => false, 'message' => 'Message not found.'], 404);
            return;
        }

        $chatId = (int) $msg['chat_id'];

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Access denied.'], 403);
            return;
        }

        $chat = $this->chatRepo->findById($chatId, (int) $userId);

        // Groups/channels require admin
        if (!in_array($chat['type'], ['private', 'secret'], true)) {
            $role = $this->chatRepo->getMemberRole($chatId, (int) $userId);
            if (!in_array($role, ['admin', 'owner'], true)) {
                $response->json(['success' => false, 'message' => 'Only admins can pin messages.'], 403);
                return;
            }
        }

        // Update message pin flag
        $this->messageRepo->update($messageId, ['is_pinned' => $unpin ? 0 : 1]);

        // Update chat's pinned_message_id
        $this->chatRepo->setPinnedMessage($chatId, $unpin ? null : $messageId);

        // Bust cache
        $memberIds = $this->chatRepo->getMemberIds($chatId);
        foreach ($memberIds as $mid) {
            $this->invalidateChatListCache((int) $mid);
        }

        $response->json([
            'success'    => true,
            'message'    => $unpin ? 'Message unpinned.' : 'Message pinned.',
            'message_id' => $messageId,
            'is_pinned'  => !$unpin,
        ]);
    }

    // =========================================================================
    // FORWARD  —  POST /api/v1/messages/forward
    // =========================================================================

    /**
     * Forward one or more messages to one or more target chats.
     */
    public function forward(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'message_ids' => ['required', 'array', 'min:1'],
            'to_chat_ids' => ['required', 'array', 'min:1'],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $messageIds = array_unique(array_map('intval', $data['message_ids']));
        $toChatIds  = array_unique(array_map('intval', $data['to_chat_ids']));

        if (count($messageIds) > 50 || count($toChatIds) > 10) {
            $response->json(['success' => false, 'message' => 'Too many messages or targets.'], 422);
            return;
        }

        $forwarded = [];
        $now       = gmdate('Y-m-d H:i:s');

        foreach ($toChatIds as $targetChatId) {
            if (!$this->chatRepo->isMember($targetChatId, (int) $userId)) continue;

            foreach ($messageIds as $originalId) {
                $original = $this->messageRepo->findById($originalId);
                if (!$original || $original['is_encrypted']) continue; // skip E2E messages

                $newId = $this->messageRepo->create([
                    'chat_id'      => $targetChatId,
                    'sender_id'    => (int) $userId,
                    'type'         => $original['type'],
                    'text'         => $original['text'],
                    'cipher_text'  => null,
                    'is_encrypted' => 0,
                    'media_id'     => $original['media_id']  ?? null,
                    'caption'      => $original['caption']   ?? null,
                    'duration'     => $original['duration']  ?? null,
                    'location'     => $original['location']  ?? null,
                    'reply_to_id'  => null,
                    'forward_from' => $originalId,
                    'is_pinned'    => 0,
                    'is_edited'    => 0,
                    'expires_at'   => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);

                $this->chatRepo->updateLastMessage($targetChatId, $newId, $now);
                $forwarded[] = ['original_id' => $originalId, 'new_id' => $newId, 'chat_id' => $targetChatId];

                // Bust cache for target members
                $mids = $this->chatRepo->getMemberIds($targetChatId);
                foreach ($mids as $mid) {
                    $this->invalidateChatListCache((int) $mid);
                }
            }
        }

        $response->json([
            'success'   => true,
            'forwarded' => $forwarded,
        ]);
    }

    // =========================================================================
    // REACT  —  POST /api/v1/messages/react
    // =========================================================================

    /**
     * Add or remove an emoji reaction on a message.
     * Calling with the same emoji twice = toggle (remove).
     */
    public function react(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'message_id' => ['required', 'integer', 'min:1'],
            'emoji'      => ['required', 'string', 'max:8'],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $messageId = (int) $data['message_id'];
        $emoji     = mb_substr(trim($data['emoji']), 0, 8);

        $msg = $this->messageRepo->findById($messageId);
        if (!$msg) {
            $response->json(['success' => false, 'message' => 'Message not found.'], 404);
            return;
        }

        if (!$this->chatRepo->isMember((int) $msg['chat_id'], (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Access denied.'], 403);
            return;
        }

        // Toggle: if already reacted with same emoji, remove it
        $existing = $this->messageRepo->getReaction($messageId, (int) $userId, $emoji);
        if ($existing) {
            $this->messageRepo->removeReaction($messageId, (int) $userId, $emoji);
            $action = 'removed';
        } else {
            $this->messageRepo->addReaction($messageId, (int) $userId, $emoji);
            $action = 'added';
        }

        $reactions = $this->messageRepo->getReactions($messageId);

        $response->json([
            'success'    => true,
            'action'     => $action,
            'reactions'  => $reactions,
            'message_id' => $messageId,
        ]);
    }

    // =========================================================================
    // READ RECEIPT  —  POST /api/v1/messages/read
    // =========================================================================

    /**
     * Mark messages as read (bulk).
     * Sends read receipts back to senders via polling cache.
     */
    public function markRead(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $data   = $request->getJson();

        $rules = [
            'chat_id'     => ['required', 'integer', 'min:1'],
            'last_msg_id' => ['required', 'integer', 'min:1'],
        ];
        $errors = $this->validation->validate($data, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId    = (int) $data['chat_id'];
        $lastMsgId = (int) $data['last_msg_id'];

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        // Mark all unread messages up to lastMsgId as read for this user
        $readIds = $this->messageRepo->markReadUpTo($chatId, $lastMsgId, (int) $userId);

        // Push read receipts into sender caches for polling pickup
        foreach ($readIds as $item) {
            $sKey     = "read_receipt_{$item['sender_id']}";
            $existing = $this->cache->get($sKey);
            $receipts = $existing ? json_decode($existing, true) : [];
            $receipts[] = [
                'msg_id'    => $item['msg_id'],
                'reader_id' => (int) $userId,
                'chat_id'   => $chatId,
            ];
            $this->cache->set($sKey, json_encode($receipts), 60);
        }

        $response->json(['success' => true, 'marked_count' => count($readIds)]);
    }

    // =========================================================================
    // SEARCH IN CHAT  —  GET /api/v1/messages/search
    // =========================================================================

    /**
     * Full-text search within a specific chat's message history.
     * Encrypted messages are excluded (server cannot read ciphertext).
     */
    public function search(Request $request, Response $response): void
    {
        $userId = $request->getUserId();
        $query  = $request->getQuery();

        $rules = [
            'chat_id' => ['required', 'integer', 'min:1'],
            'q'       => ['required', 'string', 'min:2', 'max:128'],
            'page'    => ['nullable', 'integer', 'min:1'],
        ];
        $errors = $this->validation->validate($query, $rules);
        if (!empty($errors)) {
            $response->json(['success' => false, 'errors' => $errors], 422);
            return;
        }

        $chatId  = (int) $query['chat_id'];
        $q       = trim($query['q']);
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = 20;

        if (!$this->chatRepo->isMember($chatId, (int) $userId)) {
            $response->json(['success' => false, 'message' => 'Chat not found.'], 404);
            return;
        }

        $result = $this->messageRepo->searchInChat($chatId, $q, $page, $perPage);

        $response->json([
            'success'  => true,
            'messages' => array_map(fn($m) => $this->formatMessage($m, (int) $userId), $result['items']),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    // =========================================================================
    // PURGE EPHEMERAL  —  (called internally by a cron or scheduler)
    // =========================================================================

    /**
     * DELETE expired ephemeral messages (expires_at < NOW()).
     * Call this from a scheduled task / cron every hour.
     * Route: POST /api/v1/messages/purge-expired  (admin-only / internal token)
     */
    public function purgeExpired(Request $request, Response $response): void
    {
        // Verify internal secret header
        $secret = $request->getHeader('X-Internal-Token');
        if ($secret !== ($_ENV['INTERNAL_TOKEN'] ?? '')) {
            $response->json(['success' => false, 'message' => 'Unauthorized.'], 401);
            return;
        }

        $count = $this->messageRepo->deleteExpired();

        $response->json([
            'success'       => true,
            'purged_count'  => $count,
            'purged_at'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Format a raw message DB row into a consistent API shape.
     * Never exposes cipher_text to non-recipient parties.
     */
    private function formatMessage(array $msg, int $myUserId): array
    {
        $isEncrypted = (bool) ($msg['is_encrypted'] ?? false);
        $isMine      = (int) $msg['sender_id'] === $myUserId;

        // Sender info (lightweight)
        $sender = null;
        if (isset($msg['sender_username'])) {
            $sender = [
                'id'         => (int)    $msg['sender_id'],
                'username'   => (string) $msg['sender_username'],
                'first_name' => (string) ($msg['sender_first_name'] ?? ''),
                'last_name'  => $msg['sender_last_name'] ?? null,
                'avatar'     => $msg['sender_avatar']    ?? null,
            ];
        }

        // Reactions map  {emoji: count}
        $reactions = [];
        if (!empty($msg['reactions'])) {
            $raw = is_array($msg['reactions'])
                ? $msg['reactions']
                : json_decode($msg['reactions'], true);
            $reactions = $raw ?? [];
        }

        // Reply preview
        $replyPreview = null;
        if (!empty($msg['reply_to_id'])) {
            $replied = $this->messageRepo->findById((int) $msg['reply_to_id']);
            if ($replied) {
                $replyPreview = [
                    'id'         => (int) $replied['id'],
                    'sender_id'  => (int) $replied['sender_id'],
                    'text'       => $replied['is_encrypted'] ? '[Encrypted]' : ($replied['text'] ?? ''),
                    'type'       => $replied['type'],
                ];
            }
        }

        // Location decode
        $location = null;
        if (!empty($msg['location'])) {
            $location = is_array($msg['location'])
                ? $msg['location']
                : json_decode($msg['location'], true);
        }

        return [
            'id'           => (int)    $msg['id'],
            'chat_id'      => (int)    $msg['chat_id'],
            'sender_id'    => (int)    $msg['sender_id'],
            'sender'       => $sender,
            'type'         => (string) $msg['type'],

            // Text: show ciphertext only to the two parties of the secret chat
            'text'         => $isEncrypted ? null              : ($msg['text'] ?? null),
            'cipher_text'  => $isEncrypted ? ($msg['cipher_text'] ?? null) : null,
            'is_encrypted' => $isEncrypted,

            'media_id'     => isset($msg['media_id'])    ? (int) $msg['media_id']  : null,
            'caption'      => $msg['caption']  ?? null,
            'duration'     => isset($msg['duration'])    ? (int) $msg['duration']  : null,
            'location'     => $location,

            'reply_to_id'  => isset($msg['reply_to_id']) ? (int) $msg['reply_to_id'] : null,
            'reply'        => $replyPreview,
            'forward_from' => isset($msg['forward_from'])? (int) $msg['forward_from']: null,

            'is_mine'      => $isMine,
            'is_pinned'    => (bool) ($msg['is_pinned'] ?? false),
            'is_edited'    => (bool) ($msg['is_edited'] ?? false),
            'is_read'      => (bool) ($msg['is_read']   ?? false),

            'reactions'    => $reactions,
            'expires_at'   => $msg['expires_at'] ?? null,
            'created_at'   => (string) $msg['created_at'],
            'updated_at'   => (string) $msg['updated_at'],
        ];
    }

    /**
     * Bust per-user chat list cache after any message mutation.
     */
    private function invalidateChatListCache(int $userId): void
    {
        foreach (['null', 'private', 'group', 'channel', 'secret', 'archived'] as $t) {
            for ($p = 1; $p <= 5; $p++) {
                $this->cache->delete("chat_list_{$userId}_p{$p}_t{$t}");
            }
        }
    }
}

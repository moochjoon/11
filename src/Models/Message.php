<?php
/**
 * Message Model
 * Message data model and operations
 */

namespace App\Models;

use Namak\Database;

class Message
{
    protected $table = 'messages';
    protected $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
    }

    /**
     * Get message by ID
     */
    public function getById($id)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * Create message
     */
    public function create($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->insert($this->table, $data);
    }

    /**
     * Update message
     */
    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Delete message
     */
    public function delete($id)
    {
        return $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Get chat messages
     */
    public function getChatMessages($chat_id, $limit = 50, $offset = 0)
    {
        return $this->db->get(
            "SELECT m.*, u.username, u.avatar FROM {$this->table} m
             INNER JOIN nm_users u ON m.from_user_id = u.id
             WHERE m.chat_id = ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            [$chat_id, $limit, $offset]
        );
    }

    /**
     * Get messages after timestamp
     */
    public function getMessagesAfter($chat_id, $timestamp, $limit = 50)
    {
        return $this->db->get(
            "SELECT m.*, u.username, u.avatar FROM {$this->table} m
             INNER JOIN nm_users u ON m.from_user_id = u.id
             WHERE m.chat_id = ? AND m.created_at > ?
             ORDER BY m.created_at ASC
             LIMIT ?",
            [$chat_id, $timestamp, $limit]
        );
    }

    /**
     * Get unread messages for user
     */
    public function getUnreadMessages($user_id, $chat_id = null)
    {
        $query = "SELECT m.*, u.username, u.avatar FROM {$this->table} m
                 INNER JOIN nm_users u ON m.from_user_id = u.id
                 WHERE m.to_user_id = ? AND m.read_at IS NULL";
        $params = [$user_id];

        if ($chat_id) {
            $query .= " AND m.chat_id = ?";
            $params[] = $chat_id;
        }

        $query .= " ORDER BY m.created_at DESC";

        return $this->db->get($query, $params);
    }

    /**
     * Mark message as read
     */
    public function markAsRead($message_id)
    {
        return $this->update($message_id, [
            'read_at' => date('Y-m-d H:i:s'),
            'status' => 'read',
        ]);
    }

    /**
     * Mark chat messages as read
     */
    public function markChatAsRead($chat_id, $user_id)
    {
        return $this->db->update($this->table, [
            'read_at' => date('Y-m-d H:i:s'),
            'status' => 'read',
        ], [
            'chat_id' => $chat_id,
            'to_user_id' => $user_id,
        ]);
    }

    /**
     * Get message count
     */
    public function count()
    {
        return $this->db->count($this->table);
    }

    /**
     * Get chat message count
     */
    public function getChatMessageCount($chat_id)
    {
        return $this->db->count($this->table, ['chat_id' => $chat_id]);
    }

    /**
     * Search messages
     */
    public function searchMessages($chat_id, $query, $limit = 20)
    {
        $search_term = '%' . $query . '%';

        return $this->db->get(
            "SELECT m.*, u.username, u.avatar FROM {$this->table} m
             INNER JOIN nm_users u ON m.from_user_id = u.id
             WHERE m.chat_id = ? AND m.content LIKE ?
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$chat_id, $search_term, $limit]
        );
    }

    /**
     * Pin message
     */
    public function pinMessage($message_id)
    {
        return $this->update($message_id, [
            'pinned' => 1,
            'pinned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Unpin message
     */
    public function unpinMessage($message_id)
    {
        return $this->update($message_id, [
            'pinned' => 0,
            'pinned_at' => null,
        ]);
    }

    /**
     * Get pinned messages
     */
    public function getPinnedMessages($chat_id)
    {
        return $this->db->get(
            "SELECT m.*, u.username, u.avatar FROM {$this->table} m
             INNER JOIN nm_users u ON m.from_user_id = u.id
             WHERE m.chat_id = ? AND m.pinned = 1
             ORDER BY m.pinned_at DESC",
            [$chat_id]
        );
    }

    /**
     * Delete ephemeral messages
     */
    public function deleteEphemeralMessages()
    {
        $ttl = (int)($_ENV['EPHEMERAL_TTL'] ?? 86400);
        $expiry_time = date('Y-m-d H:i:s', time() - $ttl);

        return $this->db->delete($this->table, [
            ['created_at', '<', $expiry_time],
            ['ephemeral', '=', 1],
        ]);
    }

    /**
     * Get message reactions
     */
    public function getReactions($message_id)
    {
        return $this->db->get(
            "SELECT u.username, u.avatar, mr.emoji FROM nm_message_reactions mr
             INNER JOIN nm_users u ON mr.user_id = u.id
             WHERE mr.message_id = ?
             ORDER BY mr.created_at ASC",
            [$message_id]
        );
    }

    /**
     * Add reaction
     */
    public function addReaction($message_id, $user_id, $emoji)
    {
        // Check if already exists
        $existing = $this->db->first(
            "SELECT id FROM nm_message_reactions 
             WHERE message_id = ? AND user_id = ? AND emoji = ? LIMIT 1",
            [$message_id, $user_id, $emoji]
        );

        if ($existing) {
            return false;
        }

        return $this->db->insert('message_reactions', [
            'message_id' => $message_id,
            'user_id' => $user_id,
            'emoji' => $emoji,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove reaction
     */
    public function removeReaction($message_id, $user_id, $emoji)
    {
        return $this->db->delete('message_reactions', [
            'message_id' => $message_id,
            'user_id' => $user_id,
            'emoji' => $emoji,
        ]);
    }

    /**
     * Get unread count for chat
     */
    public function getUnreadCount($chat_id, $user_id)
    {
        return $this->db->count($this->table, [
            'chat_id' => $chat_id,
            'to_user_id' => $user_id,
            'status' => 'delivered',
        ]);
    }
}
?>

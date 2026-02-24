<?php
/**
 * Message Repository
 * Data access layer for message operations
 */

namespace App\Repositories;

use Namak\Database;
use App\Models\Message;

class MessageRepository
{
    private $db;
    private $model;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
        $this->model = new Message();
    }

    /**
     * Get message by ID
     */
    public function getById($id)
    {
        return $this->model->getById($id);
    }

    /**
     * Create message
     */
    public function create($data)
    {
        return $this->model->create($data);
    }

    /**
     * Update message
     */
    public function update($id, $data)
    {
        return $this->model->update($id, $data);
    }

    /**
     * Delete message
     */
    public function delete($id)
    {
        return $this->model->delete($id);
    }

    /**
     * Get chat messages
     */
    public function getChatMessages($chat_id, $limit = 50, $offset = 0)
    {
        return $this->model->getChatMessages($chat_id, $limit, $offset);
    }

    /**
     * Get messages after timestamp (for polling)
     */
    public function getMessagesAfter($chat_id, $timestamp, $limit = 50)
    {
        return $this->model->getMessagesAfter($chat_id, $timestamp, $limit);
    }

    /**
     * Get unread messages
     */
    public function getUnreadMessages($user_id, $chat_id = null)
    {
        return $this->model->getUnreadMessages($user_id, $chat_id);
    }

    /**
     * Mark message as read
     */
    public function markAsRead($message_id)
    {
        return $this->model->markAsRead($message_id);
    }

    /**
     * Mark chat messages as read
     */
    public function markChatAsRead($chat_id, $user_id)
    {
        return $this->model->markChatAsRead($chat_id, $user_id);
    }

    /**
     * Get message count
     */
    public function count()
    {
        return $this->model->count();
    }

    /**
     * Get chat message count
     */
    public function getChatMessageCount($chat_id)
    {
        return $this->model->getChatMessageCount($chat_id);
    }

    /**
     * Search messages
     */
    public function searchMessages($chat_id, $query, $limit = 20)
    {
        return $this->model->searchMessages($chat_id, $query, $limit);
    }

    /**
     * Pin message
     */
    public function pinMessage($message_id)
    {
        return $this->model->pinMessage($message_id);
    }

    /**
     * Unpin message
     */
    public function unpinMessage($message_id)
    {
        return $this->model->unpinMessage($message_id);
    }

    /**
     * Get pinned messages
     */
    public function getPinnedMessages($chat_id)
    {
        return $this->model->getPinnedMessages($chat_id);
    }

    /**
     * Delete ephemeral messages
     */
    public function deleteEphemeralMessages()
    {
        return $this->model->deleteEphemeralMessages();
    }

    /**
     * Get message reactions
     */
    public function getReactions($message_id)
    {
        return $this->model->getReactions($message_id);
    }

    /**
     * Add reaction
     */
    public function addReaction($message_id, $user_id, $emoji)
    {
        return $this->model->addReaction($message_id, $user_id, $emoji);
    }

    /**
     * Remove reaction
     */
    public function removeReaction($message_id, $user_id, $emoji)
    {
        return $this->model->removeReaction($message_id, $user_id, $emoji);
    }

    /**
     * Get unread count for chat
     */
    public function getUnreadCount($chat_id, $user_id)
    {
        return $this->model->getUnreadCount($chat_id, $user_id);
    }

    /**
     * Get total unread count
     */
    public function getTotalUnreadCount($user_id)
    {
        $result = $this->db->first(
            "SELECT COUNT(*) as count FROM nm_messages 
             WHERE to_user_id = ? AND status = 'delivered'",
            [$user_id]
        );

        return $result['count'] ?? 0;
    }

    /**
     * Get recent messages
     */
    public function getRecentMessages($limit = 50)
    {
        return $this->db->get(
            "SELECT m.*, u.username, u.avatar FROM nm_messages m
             INNER JOIN nm_users u ON m.from_user_id = u.id
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Update message status
     */
    public function updateStatus($message_id, $status)
    {
        return $this->update($message_id, ['status' => $status]);
    }

    /**
     * Cleanup old messages (for archiving)
     */
    public function archiveOldMessages($days = 90)
    {
        $cutoff_date = date('Y-m-d H:i:s', time() - ($days * 24 * 60 * 60));

        return $this->db->update('messages', [
            'archived' => 1,
        ], [
            ['created_at', '<', $cutoff_date],
        ]);
    }
}
?>

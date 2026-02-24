<?php
/**
 * Chat Repository
 * Data access layer for chat operations
 */

namespace App\Repositories;

use Namak\Database;
use App\Models\Chat;

class ChatRepository
{
    private $db;
    private $model;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
        $this->model = new Chat();
    }

    /**
     * Get chat by ID
     */
    public function getById($id)
    {
        return $this->model->getById($id);
    }

    /**
     * Create chat
     */
    public function create($data)
    {
        return $this->model->create($data);
    }

    /**
     * Update chat
     */
    public function update($id, $data)
    {
        return $this->model->update($id, $data);
    }

    /**
     * Delete chat
     */
    public function delete($id)
    {
        return $this->model->delete($id);
    }

    /**
     * Add member to chat
     */
    public function addMember($chat_id, $user_id, $role = 'member')
    {
        return $this->model->addMember($chat_id, $user_id, $role);
    }

    /**
     * Remove member from chat
     */
    public function removeMember($chat_id, $user_id)
    {
        return $this->model->removeMember($chat_id, $user_id);
    }

    /**
     * Get chat members
     */
    public function getMembers($chat_id)
    {
        return $this->model->getMembers($chat_id);
    }

    /**
     * Check if user is member
     */
    public function isMember($chat_id, $user_id)
    {
        return $this->model->isMember($chat_id, $user_id);
    }

    /**
     * Get user chats
     */
    public function getUserChats($user_id, $limit = 20, $offset = 0, $include_archived = false, $sort_by = 'recent')
    {
        $query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM nm_messages WHERE chat_id = c.id AND status = 'delivered') as unread_count,
                  (SELECT COUNT(*) FROM nm_chat_members WHERE chat_id = c.id) as member_count,
                  (SELECT content FROM nm_messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                  (SELECT created_at FROM nm_messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time
                 FROM nm_chats c
                 INNER JOIN nm_chat_members cm ON c.id = cm.chat_id
                 WHERE cm.user_id = ?";

        $params = [$user_id];

        if (!$include_archived) {
            $query .= " AND cm.archived = 0";
        }

        if ($sort_by === 'recent') {
            $query .= " ORDER BY last_message_time DESC";
        } elseif ($sort_by === 'name') {
            $query .= " ORDER BY c.name ASC";
        } elseif ($sort_by === 'unread') {
            $query .= " ORDER BY unread_count DESC, last_message_time DESC";
        }

        $query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->get($query, $params);
    }

    /**
     * Get user chats count
     */
    public function getUserChatsCount($user_id, $include_archived = false)
    {
        $query = "SELECT COUNT(DISTINCT c.id) as count FROM nm_chats c
                 INNER JOIN nm_chat_members cm ON c.id = cm.chat_id
                 WHERE cm.user_id = ?";

        $params = [$user_id];

        if (!$include_archived) {
            $query .= " AND cm.archived = 0";
        }

        $result = $this->db->first($query, $params);
        return $result['count'] ?? 0;
    }

    /**
     * Get direct chat
     */
    public function getDirectChat($user_id_1, $user_id_2)
    {
        return $this->model->getDirectChat($user_id_1, $user_id_2);
    }

    /**
     * Search user chats
     */
    public function searchUserChats($user_id, $query, $type = 'all', $limit = 20)
    {
        return $this->model->searchUserChats($user_id, $query, $type, $limit);
    }

    /**
     * Get chat count
     */
    public function count()
    {
        return $this->model->count();
    }

    /**
     * Set chat archived
     */
    public function setArchived($chat_id, $user_id, $archived = true)
    {
        return $this->model->setArchived($chat_id, $user_id, $archived);
    }

    /**
     * Update chat last message
     */
    public function updateLastMessage($chat_id, $message_id)
    {
        return $this->model->updateLastMessage($chat_id, $message_id);
    }

    /**
     * Get chat name (for direct chats, get other user's name)
     */
    public function getChatName($chat_id, $current_user_id)
    {
        $chat = $this->getById($chat_id);

        if ($chat['type'] === 'group') {
            return $chat['name'];
        }

        // For direct chats, get other user's name
        $members = $this->getMembers($chat_id);
        foreach ($members as $member) {
            if ($member['user_id'] != $current_user_id) {
                return $member['username'];
            }
        }

        return 'Unknown';
    }

    /**
     * Get unread chats count
     */
    public function getUnreadCount($user_id)
    {
        $result = $this->db->first(
            "SELECT COUNT(DISTINCT c.id) as count FROM nm_chats c
             INNER JOIN nm_chat_members cm ON c.id = cm.chat_id
             INNER JOIN nm_messages m ON c.id = m.chat_id
             WHERE cm.user_id = ? AND m.status = 'delivered' AND m.to_user_id = ?",
            [$user_id, $user_id]
        );

        return $result['count'] ?? 0;
    }
}
?>

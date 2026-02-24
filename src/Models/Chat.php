<?php
/**
 * Chat Model
 * Chat data model and operations
 */

namespace App\Models;

use Namak\Database;

class Chat
{
    protected $table = 'chats';
    protected $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
    }

    /**
     * Get chat by ID
     */
    public function getById($id)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * Create chat
     */
    public function create($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->insert($this->table, $data);
    }

    /**
     * Update chat
     */
    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Delete chat
     */
    public function delete($id)
    {
        return $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Add member to chat
     */
    public function addMember($chat_id, $user_id, $role = 'member')
    {
        return $this->db->insert('chat_members', [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'role' => $role,
            'joined_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove member from chat
     */
    public function removeMember($chat_id, $user_id)
    {
        return $this->db->delete('chat_members', [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
        ]);
    }

    /**
     * Get chat members
     */
    public function getMembers($chat_id)
    {
        return $this->db->get(
            "SELECT u.*, cm.role, cm.joined_at FROM nm_users u
             INNER JOIN nm_chat_members cm ON u.id = cm.user_id
             WHERE cm.chat_id = ?",
            [$chat_id]
        );
    }

    /**
     * Check if user is member
     */
    public function isMember($chat_id, $user_id)
    {
        $member = $this->db->first(
            "SELECT id FROM nm_chat_members 
             WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chat_id, $user_id]
        );

        return $member !== null;
    }

    /**
     * Get user chats
     */
    public function getUserChats($user_id, $limit = 20, $offset = 0)
    {
        return $this->db->get(
            "SELECT c.*, COUNT(m.id) as message_count, 
                    MAX(m.created_at) as last_message_time
             FROM {$this->table} c
             INNER JOIN nm_chat_members cm ON c.id = cm.chat_id
             LEFT JOIN nm_messages m ON c.id = m.chat_id
             WHERE cm.user_id = ?
             GROUP BY c.id
             ORDER BY last_message_time DESC
             LIMIT ? OFFSET ?",
            [$user_id, $limit, $offset]
        );
    }

    /**
     * Get direct chat
     */
    public function getDirectChat($user_id_1, $user_id_2)
    {
        return $this->db->first(
            "SELECT c.* FROM {$this->table} c
             INNER JOIN nm_chat_members cm1 ON c.id = cm1.chat_id AND cm1.user_id = ?
             INNER JOIN nm_chat_members cm2 ON c.id = cm2.chat_id AND cm2.user_id = ?
             WHERE c.type = 'direct' LIMIT 1",
            [$user_id_1, $user_id_2]
        );
    }

    /**
     * Search user chats
     */
    public function searchUserChats($user_id, $query, $type = 'all', $limit = 20)
    {
        $search_term = '%' . $query . '%';

        $where = "cm.user_id = ?";
        $params = [$user_id];

        if ($type !== 'all') {
            $where .= " AND c.type = ?";
            $params[] = $type;
        }

        $where .= " AND (c.name LIKE ? OR c.description LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;

        $query = "SELECT c.* FROM {$this->table} c
                 INNER JOIN nm_chat_members cm ON c.id = cm.chat_id
                 WHERE {$where}
                 LIMIT ?";

        $params[] = $limit;

        return $this->db->get($query, $params);
    }

    /**
     * Get chat count
     */
    public function count()
    {
        return $this->db->count($this->table);
    }

    /**
     * Archive chat for user
     */
    public function setArchived($chat_id, $user_id, $archived = true)
    {
        return $this->db->update('chat_members', [
            'archived' => $archived ? 1 : 0,
        ], [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
        ]);
    }

    /**
     * Update chat last message
     */
    public function updateLastMessage($chat_id, $message_id)
    {
        return $this->update($chat_id, [
            'last_message_id' => $message_id,
        ]);
    }
}
?>

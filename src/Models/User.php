<?php
/**
 * User Model
 * User data model and operations
 */

namespace App\Models;

use Namak\Database;

class User
{
    protected $table = 'users';
    protected $db;
    protected $data = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
    }

    /**
     * Get user by ID
     */
    public function getById($id)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * Get user by username
     */
    public function getByUsername($username)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE username = ? LIMIT 1",
            [$username]
        );
    }

    /**
     * Get user by email
     */
    public function getByEmail($email)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE email = ? LIMIT 1",
            [$email]
        );
    }

    /**
     * Get user by phone
     */
    public function getByPhone($phone)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE phone = ? LIMIT 1",
            [$phone]
        );
    }

    /**
     * Create user
     */
    public function create($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->insert($this->table, $data);
    }

    /**
     * Update user
     */
    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Delete user
     */
    public function delete($id)
    {
        return $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Get all users
     */
    public function getAll($limit = null, $offset = 0)
    {
        $query = "SELECT * FROM {$this->table}";

        if ($limit) {
            $query .= " LIMIT {$limit} OFFSET {$offset}";
        }

        return $this->db->get($query);
    }

    /**
     * Search users
     */
    public function search($query, $limit = 20)
    {
        $search_term = '%' . $query . '%';

        return $this->db->get(
            "SELECT * FROM {$this->table} 
             WHERE username LIKE ? OR email LIKE ? OR phone LIKE ?
             LIMIT ?",
            [$search_term, $search_term, $search_term, $limit]
        );
    }

    /**
     * Check if user exists by username
     */
    public function existsByUsername($username)
    {
        $user = $this->getByUsername($username);
        return $user !== null;
    }

    /**
     * Check if user exists by email
     */
    public function existsByEmail($email)
    {
        $user = $this->getByEmail($email);
        return $user !== null;
    }

    /**
     * Check if user exists by phone
     */
    public function existsByPhone($phone)
    {
        $user = $this->getByPhone($phone);
        return $user !== null;
    }

    /**
     * Get user count
     */
    public function count()
    {
        return $this->db->count($this->table);
    }

    /**
     * Update last seen
     */
    public function updateLastSeen($id)
    {
        return $this->update($id, [
            'last_seen' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update last login
     */
    public function updateLastLogin($id)
    {
        return $this->update($id, [
            'last_login' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update status
     */
    public function updateStatus($id, $status)
    {
        return $this->update($id, [
            'status' => $status,
        ]);
    }

    /**
     * Update avatar
     */
    public function updateAvatar($id, $avatar_path)
    {
        return $this->update($id, [
            'avatar' => $avatar_path,
        ]);
    }

    /**
     * Get active users
     */
    public function getActiveUsers($limit = 20)
    {
        return $this->db->get(
            "SELECT * FROM {$this->table} 
             WHERE status = 'active' 
             ORDER BY last_seen DESC 
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Get online users
     */
    public function getOnlineUsers($minutes = 5)
    {
        $time_limit = date('Y-m-d H:i:s', time() - ($minutes * 60));

        return $this->db->get(
            "SELECT * FROM {$this->table} 
             WHERE last_seen >= ? 
             ORDER BY last_seen DESC",
            [$time_limit]
        );
    }

    /**
     * Get blocked users for user
     */
    public function getBlockedUsers($user_id)
    {
        return $this->db->get(
            "SELECT u.* FROM {$this->table} u
             INNER JOIN nm_blocked_users bu ON u.id = bu.blocked_user_id
             WHERE bu.user_id = ?",
            [$user_id]
        );
    }

    /**
     * Check if user is blocked
     */
    public function isBlocked($user_id, $blocked_user_id)
    {
        $blocked = $this->db->first(
            "SELECT id FROM nm_blocked_users 
             WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$user_id, $blocked_user_id]
        );

        return $blocked !== null;
    }

    /**
     * Block user
     */
    public function blockUser($user_id, $blocked_user_id)
    {
        if ($this->isBlocked($user_id, $blocked_user_id)) {
            return false;
        }

        return $this->db->insert('blocked_users', [
            'user_id' => $user_id,
            'blocked_user_id' => $blocked_user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Unblock user
     */
    public function unblockUser($user_id, $blocked_user_id)
    {
        return $this->db->delete('blocked_users', [
            'user_id' => $user_id,
            'blocked_user_id' => $blocked_user_id,
        ]);
    }

    /**
     * Get user with stats
     */
    public function getUserWithStats($id)
    {
        $user = $this->getById($id);

        if (!$user) {
            return null;
        }

        // Get message count
        $user['message_count'] = $this->db->count('messages', ['from_user_id' => $id]);

        // Get chat count
        $user['chat_count'] = $this->db->count('chat_members', ['user_id' => $id]);

        // Get contacts count
        $user['contact_count'] = $this->db->count('contacts', ['user_id' => $id]);

        return $user;
    }
}
?>

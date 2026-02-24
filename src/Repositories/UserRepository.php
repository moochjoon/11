<?php
/**
 * User Repository
 * Data access layer for user operations
 */

namespace App\Repositories;

use Namak\Database;
use App\Models\User;

class UserRepository
{
    private $db;
    private $model;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
        $this->model = new User();
    }

    /**
     * Get user by ID
     */
    public function getById($id)
    {
        return $this->model->getById($id);
    }

    /**
     * Get user by username
     */
    public function getByUsername($username)
    {
        return $this->model->getByUsername($username);
    }

    /**
     * Get user by email
     */
    public function getByEmail($email)
    {
        return $this->model->getByEmail($email);
    }

    /**
     * Get user by phone
     */
    public function getByPhone($phone)
    {
        return $this->model->getByPhone($phone);
    }

    /**
     * Create user
     */
    public function create($data)
    {
        return $this->model->create($data);
    }

    /**
     * Update user
     */
    public function update($id, $data)
    {
        return $this->model->update($id, $data);
    }

    /**
     * Delete user
     */
    public function delete($id)
    {
        return $this->model->delete($id);
    }

    /**
     * Get all users
     */
    public function getAll($limit = null, $offset = 0)
    {
        return $this->model->getAll($limit, $offset);
    }

    /**
     * Search users
     */
    public function search($query, $limit = 20)
    {
        return $this->model->search($query, $limit);
    }

    /**
     * Check if username exists
     */
    public function existsByUsername($username)
    {
        return $this->model->existsByUsername($username);
    }

    /**
     * Check if email exists
     */
    public function existsByEmail($email)
    {
        return $this->model->existsByEmail($email);
    }

    /**
     * Check if phone exists
     */
    public function existsByPhone($phone)
    {
        return $this->model->existsByPhone($phone);
    }

    /**
     * Get user count
     */
    public function count()
    {
        return $this->model->count();
    }

    /**
     * Update last seen
     */
    public function updateLastSeen($id)
    {
        return $this->model->updateLastSeen($id);
    }

    /**
     * Update last login
     */
    public function updateLastLogin($id)
    {
        return $this->model->updateLastLogin($id);
    }

    /**
     * Get online users
     */
    public function getOnlineUsers($minutes = 5)
    {
        return $this->model->getOnlineUsers($minutes);
    }

    /**
     * Get active users
     */
    public function getActiveUsers($limit = 20)
    {
        return $this->model->getActiveUsers($limit);
    }

    /**
     * Get blocked users
     */
    public function getBlockedUsers($user_id)
    {
        return $this->model->getBlockedUsers($user_id);
    }

    /**
     * Check if user is blocked
     */
    public function isBlocked($user_id, $blocked_user_id)
    {
        return $this->model->isBlocked($user_id, $blocked_user_id);
    }

    /**
     * Block user
     */
    public function blockUser($user_id, $blocked_user_id)
    {
        return $this->model->blockUser($user_id, $blocked_user_id);
    }

    /**
     * Unblock user
     */
    public function unblockUser($user_id, $blocked_user_id)
    {
        return $this->model->unblockUser($user_id, $blocked_user_id);
    }

    /**
     * Get user with stats
     */
    public function getUserWithStats($id)
    {
        return $this->model->getUserWithStats($id);
    }

    /**
     * Get user contacts
     */
    public function getContacts($user_id, $limit = 50, $offset = 0)
    {
        return $this->db->get(
            "SELECT u.* FROM nm_users u
             INNER JOIN nm_contacts c ON u.id = c.contact_user_id
             WHERE c.user_id = ?
             ORDER BY u.username ASC
             LIMIT ? OFFSET ?",
            [$user_id, $limit, $offset]
        );
    }

    /**
     * Add contact
     */
    public function addContact($user_id, $contact_user_id)
    {
        $existing = $this->db->first(
            "SELECT id FROM nm_contacts WHERE user_id = ? AND contact_user_id = ? LIMIT 1",
            [$user_id, $contact_user_id]
        );

        if ($existing) {
            return false;
        }

        return $this->db->insert('contacts', [
            'user_id' => $user_id,
            'contact_user_id' => $contact_user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove contact
     */
    public function removeContact($user_id, $contact_user_id)
    {
        return $this->db->delete('contacts', [
            'user_id' => $user_id,
            'contact_user_id' => $contact_user_id,
        ]);
    }

    /**
     * Check if user is contact
     */
    public function isContact($user_id, $contact_user_id)
    {
        $contact = $this->db->first(
            "SELECT id FROM nm_contacts WHERE user_id = ? AND contact_user_id = ? LIMIT 1",
            [$user_id, $contact_user_id]
        );

        return $contact !== null;
    }
}
?>

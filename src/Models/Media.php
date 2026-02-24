<?php
/**
 * Media Model
 * Media/File data model and operations
 */

namespace App\Models;

use Namak\Database;

class Media
{
    protected $table = 'media';
    protected $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
    }

    /**
     * Get media by ID
     */
    public function getById($id)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * Create media
     */
    public function create($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->insert($this->table, $data);
    }

    /**
     * Update media
     */
    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update($this->table, $data, ['id' => $id]);
    }

    /**
     * Delete media
     */
    public function delete($id)
    {
        $media = $this->getById($id);

        if ($media && file_exists($media['file_path'])) {
            @unlink($media['file_path']);
        }

        return $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Get message media
     */
    public function getMessageMedia($message_id)
    {
        return $this->db->get(
            "SELECT * FROM {$this->table} WHERE message_id = ?",
            [$message_id]
        );
    }

    /**
     * Get user media
     */
    public function getUserMedia($user_id, $type = null, $limit = 50, $offset = 0)
    {
        $query = "SELECT * FROM {$this->table} WHERE uploaded_by = ?";
        $params = [$user_id];

        if ($type) {
            $query .= " AND type = ?";
            $params[] = $type;
        }

        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->get($query, $params);
    }

    /**
     * Get chat media
     */
    public function getChatMedia($chat_id, $type = null, $limit = 50, $offset = 0)
    {
        $query = "SELECT m.* FROM {$this->table} m
                 INNER JOIN nm_messages msg ON m.message_id = msg.id
                 WHERE msg.chat_id = ?";
        $params = [$chat_id];

        if ($type) {
            $query .= " AND m.type = ?";
            $params[] = $type;
        }

        $query .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->get($query, $params);
    }

    /**
     * Get media count
     */
    public function count()
    {
        return $this->db->count($this->table);
    }

    /**
     * Get user media count
     */
    public function getUserMediaCount($user_id, $type = null)
    {
        if ($type) {
            return $this->db->count($this->table, [
                'uploaded_by' => $user_id,
                'type' => $type,
            ]);
        }

        return $this->db->count($this->table, ['uploaded_by' => $user_id]);
    }

    /**
     * Update download count
     */
    public function incrementDownloadCount($id)
    {
        $media = $this->getById($id);

        if ($media) {
            return $this->update($id, [
                'download_count' => $media['download_count'] + 1,
            ]);
        }

        return false;
    }

    /**
     * Search media
     */
    public function searchMedia($query, $limit = 20)
    {
        $search_term = '%' . $query . '%';

        return $this->db->get(
            "SELECT * FROM {$this->table} 
             WHERE original_name LIKE ? OR file_name LIKE ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$search_term, $search_term, $limit]
        );
    }

    /**
     * Get expired media (for cleanup)
     */
    public function getExpiredMedia($days = 30)
    {
        $expiry_date = date('Y-m-d H:i:s', time() - ($days * 24 * 60 * 60));

        return $this->db->get(
            "SELECT * FROM {$this->table} 
             WHERE created_at < ? AND temporary = 1",
            [$expiry_date]
        );
    }

    /**
     * Delete expired media
     */
    public function deleteExpiredMedia($days = 30)
    {
        $expired_media = $this->getExpiredMedia($days);
        $deleted_count = 0;

        foreach ($expired_media as $media) {
            if ($this->delete($media['id'])) {
                $deleted_count++;
            }
        }

        return $deleted_count;
    }

    /**
     * Get media by hash
     */
    public function getByHash($file_hash)
    {
        return $this->db->first(
            "SELECT * FROM {$this->table} WHERE file_hash = ? LIMIT 1",
            [$file_hash]
        );
    }

    /**
     * Get total storage used by user
     */
    public function getUserStorageUsed($user_id)
    {
        $result = $this->db->first(
            "SELECT SUM(file_size) as total FROM {$this->table} WHERE uploaded_by = ?",
            [$user_id]
        );

        return $result['total'] ?? 0;
    }
}
?>

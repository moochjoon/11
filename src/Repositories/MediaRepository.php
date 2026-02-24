<?php
/**
 * Media Repository
 * Data access layer for media operations
 */

namespace App\Repositories;

use Namak\Database;
use App\Models\Media;

class MediaRepository
{
    private $db;
    private $model;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
        $this->model = new Media();
    }

    /**
     * Get media by ID
     */
    public function getById($id)
    {
        return $this->model->getById($id);
    }

    /**
     * Create media
     */
    public function create($data)
    {
        return $this->model->create($data);
    }

    /**
     * Update media
     */
    public function update($id, $data)
    {
        return $this->model->update($id, $data);
    }

    /**
     * Delete media
     */
    public function delete($id)
    {
        return $this->model->delete($id);
    }

    /**
     * Get message media
     */
    public function getMessageMedia($message_id)
    {
        return $this->model->getMessageMedia($message_id);
    }

    /**
     * Get user media
     */
    public function getUserMedia($user_id, $type = null, $limit = 50, $offset = 0)
    {
        return $this->model->getUserMedia($user_id, $type, $limit, $offset);
    }

    /**
     * Get chat media
     */
    public function getChatMedia($chat_id, $type = null, $limit = 50, $offset = 0)
    {
        return $this->model->getChatMedia($chat_id, $type, $limit, $offset);
    }

    /**
     * Get media count
     */
    public function count()
    {
        return $this->model->count();
    }

    /**
     * Get user media count
     */
    public function getUserMediaCount($user_id, $type = null)
    {
        return $this->model->getUserMediaCount($user_id, $type);
    }

    /**
     * Increment download count
     */
    public function incrementDownloadCount($id)
    {
        return $this->model->incrementDownloadCount($id);
    }

    /**
     * Search media
     */
    public function searchMedia($query, $limit = 20)
    {
        return $this->model->searchMedia($query, $limit);
    }

    /**
     * Get expired media
     */
    public function getExpiredMedia($days = 30)
    {
        return $this->model->getExpiredMedia($days);
    }

    /**
     * Delete expired media
     */
    public function deleteExpiredMedia($days = 30)
    {
        return $this->model->deleteExpiredMedia($days);
    }

    /**
     * Get media by hash
     */
    public function getByHash($file_hash)
    {
        return $this->model->getByHash($file_hash);
    }

    /**
     * Get user storage used
     */
    public function getUserStorageUsed($user_id)
    {
        return $this->model->getUserStorageUsed($user_id);
    }

    /**
     * Get total storage used
     */
    public function getTotalStorageUsed()
    {
        $result = $this->db->first(
            "SELECT SUM(file_size) as total FROM nm_media"
        );

        return $result['total'] ?? 0;
    }

    /**
     * Get media by type
     */
    public function getByType($type, $limit = 50, $offset = 0)
    {
        return $this->db->get(
            "SELECT * FROM nm_media 
             WHERE type = ? 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?",
            [$type, $limit, $offset]
        );
    }

    /**
     * Get most downloaded media
     */
    public function getMostDownloaded($limit = 20)
    {
        return $this->db->get(
            "SELECT * FROM nm_media 
             ORDER BY download_count DESC 
             LIMIT ?",
            [$limit]
        );
    }
}
?>

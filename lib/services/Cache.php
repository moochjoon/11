<?php
/**
 * Cache Service
 * File-based caching system
 */

namespace App\Services;

class Cache
{
    private $cache_path;
    private $prefix;
    private $default_ttl;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->cache_path = CACHE_PATH;
        $this->prefix = $_ENV['CACHE_PREFIX'] ?? 'namak_';
        $this->default_ttl = (int)($_ENV['CACHE_TTL'] ?? 3600);

        // Create cache directory if not exists
        if (!is_dir($this->cache_path)) {
            @mkdir($this->cache_path, 0755, true);
        }
    }

    /**
     * Get cached value
     */
    public function get($key, $default = null)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = @file_get_contents($file);

        if ($data === false) {
            return $default;
        }

        $cached = json_decode($data, true);

        // Check if expired
        if ($cached['expires_at'] && $cached['expires_at'] < time()) {
            @unlink($file);
            return $default;
        }

        return $cached['value'] ?? $default;
    }

    /**
     * Set cached value
     */
    public function set($key, $value, $ttl = null)
    {
        if ($ttl === null) {
            $ttl = $this->default_ttl;
        }

        $file = $this->getFilePath($key);
        $expires_at = $ttl > 0 ? time() + $ttl : 0;

        $data = json_encode([
            'key' => $key,
            'value' => $value,
            'expires_at' => $expires_at,
            'created_at' => time(),
        ]);

        @file_put_contents($file, $data);
        return true;
    }

    /**
     * Check if key exists
     */
    public function has($key)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = @file_get_contents($file);
        $cached = json_decode($data, true);

        // Check if expired
        if ($cached['expires_at'] && $cached['expires_at'] < time()) {
            @unlink($file);
            return false;
        }

        return true;
    }

    /**
     * Delete cached value
     */
    public function delete($key)
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            @unlink($file);
            return true;
        }

        return false;
    }

    /**
     * Delete all cached values
     */
    public function flush()
    {
        $files = glob($this->cache_path . '/' . $this->prefix . '*');

        foreach ($files as $file) {
            @unlink($file);
        }

        return true;
    }

    /**
     * Increment value
     */
    public function increment($key, $increment = 1)
    {
        $value = (int)$this->get($key, 0);
        $value += $increment;
        $this->set($key, $value);
        return $value;
    }

    /**
     * Decrement value
     */
    public function decrement($key, $decrement = 1)
    {
        $value = (int)$this->get($key, 0);
        $value -= $decrement;
        $this->set($key, $value);
        return $value;
    }

    /**
     * Get and delete
     */
    public function pull($key, $default = null)
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * Remember (get or set)
     */
    public function remember($key, $ttl, $callback)
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Remember forever
     */
    public function rememberForever($key, $callback)
    {
        return $this->remember($key, 0, $callback);
    }

    /**
     * Get multiple values
     */
    public function getMany($keys)
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Set multiple values
     */
    public function setMany($items, $ttl = null)
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * Delete multiple values
     */
    public function deleteMany($keys)
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * Get cache file path
     */
    private function getFilePath($key)
    {
        $filename = md5($this->prefix . $key) . '.cache';
        return $this->cache_path . '/' . $filename;
    }

    /**
     * Clear expired cache
     */
    public function clearExpired()
    {
        $files = glob($this->cache_path . '/' . $this->prefix . '*');
        $cleared = 0;

        foreach ($files as $file) {
            $data = @file_get_contents($file);
            $cached = json_decode($data, true);

            if ($cached['expires_at'] && $cached['expires_at'] < time()) {
                @unlink($file);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Get cache info
     */
    public function info()
    {
        $files = glob($this->cache_path . '/' . $this->prefix . '*');
        $total_size = 0;
        $items = 0;
        $expired = 0;

        foreach ($files as $file) {
            $total_size += filesize($file);
            $items++;

            $data = @file_get_contents($file);
            $cached = json_decode($data, true);

            if ($cached['expires_at'] && $cached['expires_at'] < time()) {
                $expired++;
            }
        }

        return [
            'total_items' => $items,
            'expired_items' => $expired,
            'total_size' => $total_size,
            'total_size_formatted' => $this->formatBytes($total_size),
            'cache_path' => $this->cache_path,
        ];
    }

    /**
     * Format bytes
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>

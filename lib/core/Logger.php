<?php
/**
 * Logger Class
 * Application logging system
 */

namespace Namak;

class Logger
{
    private static $instance = null;
    private $log_path;
    private $current_level = 'info';
    private $levels = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4,
    ];

    private function __construct()
    {
        $this->log_path = LOG_PATH;

        // Create log directory if not exists
        if (!is_dir($this->log_path)) {
            @mkdir($this->log_path, 0755, true);
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log debug message
     */
    public function debug($message, $context = [])
    {
        if ($this->levels['debug'] >= $this->levels[$this->current_level]) {
            $this->write('DEBUG', $message, $context);
        }
    }

    /**
     * Log info message
     */
    public function info($message, $context = [])
    {
        if ($this->levels['info'] >= $this->levels[$this->current_level]) {
            $this->write('INFO', $message, $context);
        }
    }

    /**
     * Log warning message
     */
    public function warning($message, $context = [])
    {
        if ($this->levels['warning'] >= $this->levels[$this->current_level]) {
            $this->write('WARNING', $message, $context);
        }
    }

    /**
     * Log error message
     */
    public function error($message, $context = [])
    {
        if ($this->levels['error'] >= $this->levels[$this->current_level]) {
            $this->write('ERROR', $message, $context);
        }
    }

    /**
     * Log critical message
     */
    public function critical($message, $context = [])
    {
        if ($this->levels['critical'] >= $this->levels[$this->current_level]) {
            $this->write('CRITICAL', $message, $context);
        }
    }

    /**
     * Write log message
     */
    private function write($level, $message, $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $request_id = $this->getRequestId();
        $user_id = $this->getUserId();

        // Format message with context
        $formatted_message = $message;
        if (!empty($context)) {
            foreach ($context as $key => $value) {
                $formatted_message = str_replace('{' . $key . '}', $value, $formatted_message);
            }
        }

        // Build log entry
        $log_entry = "[{$timestamp}] [{$level}] [ID: {$request_id}] [User: {$user_id}] {$formatted_message}";

        // Add context as JSON if provided
        if (!empty($context)) {
            $log_entry .= " | Context: " . json_encode($context);
        }

        $log_entry .= "\n";

        // Write to appropriate log file
        $log_file = $this->log_path . '/' . strtolower($level) . '.log';
        @file_put_contents($log_file, $log_entry, FILE_APPEND);

        // Also write to main app.log
        $app_log = $this->log_path . '/app.log';
        @file_put_contents($app_log, $log_entry, FILE_APPEND);

        // Rotate logs if too large
        $this->rotateLogIfNeeded($log_file);
        $this->rotateLogIfNeeded($app_log);
    }

    /**
     * Rotate log file if needed
     */
    private function rotateLogIfNeeded($log_file)
    {
        if (!file_exists($log_file)) {
            return;
        }

        $max_size = 10 * 1024 * 1024; // 10MB
        $file_size = filesize($log_file);

        if ($file_size > $max_size) {
            $backup_file = $log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename($log_file, $backup_file);

            // Delete old backups (keep last 10)
            $dir = dirname($log_file);
            $base_name = basename($log_file);
            $files = glob($dir . '/' . $base_name . '.*.bak');

            if (count($files) > 10) {
                usort($files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });

                for ($i = 0; $i < count($files) - 10; $i++) {
                    @unlink($files[$i]);
                }
            }
        }
    }

    /**
     * Get request ID for tracking
     */
    private function getRequestId()
    {
        if (!isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $_SERVER['HTTP_X_REQUEST_ID'] = uniqid('req_', true);
        }
        return $_SERVER['HTTP_X_REQUEST_ID'];
    }

    /**
     * Get current user ID
     */
    private function getUserId()
    {
        if (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return 'guest';
    }

    /**
     * Set log level
     */
    public function setLevel($level)
    {
        if (isset($this->levels[$level])) {
            $this->current_level = $level;
        }
    }

    /**
     * Get log level
     */
    public function getLevel()
    {
        return $this->current_level;
    }

    /**
     * Log HTTP request
     */
    public function logRequest($method, $path, $status_code = null)
    {
        $context = [
            'method' => $method,
            'path' => $path,
            'status' => $status_code,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];

        $this->info("HTTP {$method} {$path}", $context);
    }

    /**
     * Log database query
     */
    public function logQuery($query, $execution_time = 0)
    {
        $context = [
            'query' => $query,
            'time' => $execution_time . 'ms',
        ];

        if ($execution_time > 1000) {
            $this->warning("Slow query detected", $context);
        } else {
            $this->debug("Database query executed", $context);
        }
    }

    /**
     * Log exception
     */
    public function logException(\Exception $e)
    {
        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->error($e->getMessage(), $context);
    }

    /**
     * Clear logs
     */
    public function clearLogs($days = 7)
    {
        $files = glob($this->log_path . '/*.log');
        $time_limit = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $time_limit) {
                @unlink($file);
            }
        }
    }

    /**
     * Get log file contents
     */
    public function getLogContents($level = 'app', $lines = 100)
    {
        $log_file = $this->log_path . '/' . $level . '.log';

        if (!file_exists($log_file)) {
            return '';
        }

        $file = new \SplFileObject($log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();
        $start_line = max(0, $last_line - $lines);

        $file->seek($start_line);
        $contents = '';

        while (!$file->eof()) {
            $contents .= $file->fgets();
        }

        return $contents;
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserializing
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize Logger");
    }
}
?>

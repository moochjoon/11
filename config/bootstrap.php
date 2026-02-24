<?php
/**
 * Application Bootstrap
 * Initialize core application components
 */

// Error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/error.log');

// Set default timezone
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'UTC');

// Load configuration files
$config = require_once BASE_PATH . '/config/app.php';
$db_config = require_once BASE_PATH . '/config/database.php';
$security_config = require_once BASE_PATH . '/config/security.php';

// Session configuration
session_start();
ini_set('session.cookie_secure', $config['session']['cookie_secure'] ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', $config['session']['cookie_samesite']);
session_name($config['session']['cookie_name']);

// Define global constants
define('APP_NAME', $config['name']);
define('APP_DEBUG', $config['debug']);
define('APP_TIMEZONE', $config['timezone']);
define('APP_LOCALE', $config['locale']);
define('DB_HOST', $db_config['host']);
define('DB_PORT', $db_config['port']);
define('DB_NAME', $db_config['database']);
define('DB_USER', $db_config['username']);
define('DB_PASS', $db_config['password']);
define('STORAGE_PATH', $config['storage']['path']);
define('CACHE_PATH', $config['cache']['path']);
define('LOG_PATH', $config['logging']['path']);

// Create storage directories if they don't exist
$dirs = [
    STORAGE_PATH,
    STORAGE_PATH . '/logs',
    STORAGE_PATH . '/cache',
    STORAGE_PATH . '/session',
    STORAGE_PATH . '/uploads',
    STORAGE_PATH . '/uploads/avatars',
    STORAGE_PATH . '/uploads/files',
    STORAGE_PATH . '/uploads/images',
    STORAGE_PATH . '/uploads/videos',
    STORAGE_PATH . '/uploads/voice',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Autoload classes
spl_autoload_register(function($class) {
    // PSR-4 autoloading
    $prefix = 'App\\';
    if (strpos($class, $prefix) === 0) {
        $relative_class = substr($class, strlen($prefix));
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }

    // Lib autoloading
    $prefix = 'Namak\\';
    if (strpos($class, $prefix) === 0) {
        $relative_class = substr($class, strlen($prefix));
        $file = BASE_PATH . '/lib/' . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// Load helper functions
if (file_exists(BASE_PATH . '/lib/helpers.php')) {
    require_once BASE_PATH . '/lib/helpers.php';
}

// Load translation files
$locale = $config['locale'];
$translation_file = BASE_PATH . '/lib/i18n/' . $locale . '.php';
if (file_exists($translation_file)) {
    $GLOBALS['translations'] = require_once $translation_file;
} else {
    $GLOBALS['translations'] = require_once BASE_PATH . '/lib/i18n/en.php';
}

// Set session language if not set
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $config['locale'];
}

// CORS headers
header('Access-Control-Allow-Origin: ' . (in_array('*', $config['cors']['origins']) ? '*' : implode(', ', $config['cors']['origins'])));
header('Access-Control-Allow-Methods: ' . implode(', ', $config['cors']['allowed_methods']));
header('Access-Control-Allow-Headers: ' . implode(', ', $config['cors']['allowed_headers']));
header('Access-Control-Allow-Credentials: ' . ($config['cors']['allow_credentials'] ? 'true' : 'false'));
header('Access-Control-Max-Age: ' . $config['cors']['max_age']);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// CSP Header
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");

// Gzip compression
if (extension_loaded('zlib') && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    ob_start('ob_gzhandler');
}

// Initialize logging
class Logger {
    private static $instance = null;
    private $log_file;

    private function __construct() {
        $this->log_file = LOG_PATH . '/app.log';
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] {$message}\n";
        @file_put_contents($this->log_file, $log_message, FILE_APPEND);
    }

    public function info($message) { $this->log($message, 'INFO'); }
    public function error($message) { $this->log($message, 'ERROR'); }
    public function warning($message) { $this->log($message, 'WARNING'); }
    public function debug($message) { if (APP_DEBUG) $this->log($message, 'DEBUG'); }
}

// Global logger instance
$GLOBALS['logger'] = Logger::getInstance();

// Handle errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $GLOBALS['logger']->log("Error in {$errfile}({$errline}): {$errstr}", 'ERROR');
    return true;
});

// Handle exceptions
set_exception_handler(function($exception) {
    $GLOBALS['logger']->log("Exception: " . $exception->getMessage(), 'ERROR');

    if (APP_DEBUG) {
        echo "<h1>Exception</h1>";
        echo "<pre>" . $exception . "</pre>";
    } else {
        http_response_code(500);
        echo "An error occurred. Please try again later.";
    }
});

// Application initialized
$GLOBALS['app_initialized'] = true;
?>

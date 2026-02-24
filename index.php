<?php
/**
 * Namak - Telegram-like Messaging Application
 * Main Entry Point / Router
 */

// Define base constants
define('BASE_PATH', __DIR__);
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true' ? true : false);

// Load environment variables
if (file_exists(BASE_PATH . '/.env')) {
    $env_file = file_get_contents(BASE_PATH . '/.env');
    $lines = explode("\n", $env_file);
    foreach ($lines as $line) {
        if (trim($line) && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Bootstrap application
require_once BASE_PATH . '/config/bootstrap.php';

// Get request path and method
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove base path if needed
$base = dirname($_SERVER['SCRIPT_NAME']);
if ($base !== '/' && strpos($request_path, $base) === 0) {
    $request_path = substr($request_path, strlen($base));
}

// Normalize path
$request_path = rtrim($request_path, '/') ?: '/';

// Handle API routes
if (strpos($request_path, '/api/') === 0) {
    require_once BASE_PATH . '/api/router.php';
    exit;
}

// Handle page routes
switch ($request_path) {
    // Auth routes
    case '/':
    case '/login':
        require_once BASE_PATH . '/pages/auth/login.php';
        break;

    case '/register':
        require_once BASE_PATH . '/pages/auth/register.php';
        break;

    // App routes
    case '/chat':
    case '/app':
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        require_once BASE_PATH . '/pages/app/chat.php';
        break;

    case '/contacts':
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        require_once BASE_PATH . '/pages/app/contacts.php';
        break;

    case '/profile':
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        require_once BASE_PATH . '/pages/app/profile.php';
        break;

    case '/settings':
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        require_once BASE_PATH . '/pages/app/settings.php';
        break;

    case '/install':
        require_once BASE_PATH . '/install/index.php';
        break;

    // 404 Not Found
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Route not found: ' . $request_path,
            'timestamp' => time()
        ]);
        exit;
}
?>

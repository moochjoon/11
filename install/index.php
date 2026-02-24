<?php
/**
 * Installation Script
 * Database setup and initialization
 */

define('BASE_PATH', dirname(dirname(__FILE__)));

// Load environment
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

// Check if already installed
$lock_file = BASE_PATH . '/storage/internal/install.lock';
if (file_exists($lock_file)) {
    die('Installation already completed. Remove ' . $lock_file . ' to reinstall.');
}

// Get database config
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_port = $_ENV['DB_PORT'] ?? 3306;
$db_name = $_ENV['DB_NAME'] ?? 'namak';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'setup') {
        $db_host = $_POST['db_host'] ?? $db_host;
        $db_port = $_POST['db_port'] ?? $db_port;
        $db_name = $_POST['db_name'] ?? $db_name;
        $db_user = $_POST['db_user'] ?? $db_user;
        $db_pass = $_POST['db_pass'] ?? $db_pass;

        try {
            // Connect to MySQL
            $pdo = new PDO(
                "mysql:host={$db_host}:{$db_port}",
                $db_user,
                $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // Select database
            $pdo->exec("USE `{$db_name}`");

            // Load schema
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            $statements = array_filter(array_map('trim', explode(';', $schema)), fn($s) => !empty($s));

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            // Create lock file
            @mkdir(dirname($lock_file), 0755, true);
            file_put_contents($lock_file, date('Y-m-d H:i:s'));

            // Update .env file
            $env_content = file_get_contents(BASE_PATH . '/.env');
            $env_content = preg_replace('/DB_HOST=.*/', 'DB_HOST=' . $db_host, $env_content);
            $env_content = preg_replace('/DB_PORT=.*/', 'DB_PORT=' . $db_port, $env_content);
            $env_content = preg_replace('/DB_NAME=.*/', 'DB_NAME=' . $db_name, $env_content);
            $env_content = preg_replace('/DB_USER=.*/', 'DB_USER=' . $db_user, $env_content);
            $env_content = preg_replace('/DB_PASS=.*/', 'DB_PASS=' . $db_pass, $env_content);
            file_put_contents(BASE_PATH . '/.env', $env_content);

            $success = 'Installation completed successfully!';
        } catch (Exception $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Namak - Installation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .button:hover {
            transform: translateY(-2px);
        }

        .button:active {
            transform: translateY(0);
        }

        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-size: 13px;
            color: #555;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔧 Namak Installation</h1>
        <p>Setup your Namak messaging server</p>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success">✓ <?php echo $success; ?></div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-error">✗ <?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="setup">

        <div class="form-group">
            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($db_host); ?>" required>
        </div>

        <div class="grid">
            <div class="form-group">
                <label for="db_port">Port</label>
                <input type="number" id="db_port" name="db_port" value="<?php echo htmlspecialchars($db_port); ?>" required>
            </div>

            <div class="form-group">
                <label for="db_name">Database Name</label>
                <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($db_name); ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="db_user">Username</label>
            <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($db_user); ?>" required>
        </div>

        <div class="form-group">
            <label for="db_pass">Password</label>
            <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>">
        </div>

        <button type="submit" class="button">Install Database</button>

        <div class="info">
            <strong>ℹ️ Note:</strong> This will create a new database and tables. Make sure the database user has sufficient permissions.
        </div>
    </form>
</div>
</body>
</html>

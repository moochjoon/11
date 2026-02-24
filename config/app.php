<?php
/**
 * Application Configuration
 * Core settings for Namak application
 */

return [
    // Application Info
    'name' => $_ENV['APP_NAME'] ?? 'Namak',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => $_ENV['APP_DEBUG'] === 'true' ? true : false,
    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
    'key' => $_ENV['APP_KEY'] ?? '',

    // Timezone & Locale
    'timezone' => $_ENV['TIMEZONE'] ?? 'UTC',
    'locale' => $_ENV['DEFAULT_LANGUAGE'] ?? 'en',
    'fallback_locale' => 'en',
    'rtl_enabled' => $_ENV['RTL_ENABLED'] === 'true' ? true : false,
    'rtl_languages' => ['fa', 'ar', 'ur', 'he'],

    // Database
    'database' => [
        'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'database' => $_ENV['DB_NAME'] ?? 'namak',
        'username' => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => 'nm_',
    ],

    // Cache
    'cache' => [
        'driver' => $_ENV['CACHE_DRIVER'] ?? 'file',
        'prefix' => $_ENV['CACHE_PREFIX'] ?? 'namak_',
        'ttl' => (int)($_ENV['CACHE_TTL'] ?? 3600),
        'path' => BASE_PATH . '/storage/cache',
    ],

    // Session
    'session' => [
        'driver' => 'file',
        'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 604800),
        'cookie_name' => 'NAMAK_SESS',
        'cookie_secure' => $_ENV['SESSION_COOKIE_SECURE'] === 'true' ? true : false,
        'cookie_httponly' => $_ENV['SESSION_COOKIE_HTTPONLY'] === 'true' ? true : false,
        'cookie_samesite' => $_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Strict',
        'path' => BASE_PATH . '/storage/session',
    ],

    // Storage
    'storage' => [
        'path' => BASE_PATH . $_ENV['STORAGE_PATH'] ?? '/storage',
        'max_file_size' => (int)($_ENV['MAX_FILE_SIZE'] ?? 52428800), // 50MB
        'max_avatar_size' => (int)($_ENV['MAX_AVATAR_SIZE'] ?? 5242880), // 5MB
        'max_video_size' => (int)($_ENV['MAX_VIDEO_SIZE'] ?? 104857600), // 100MB
        'upload_path' => $_ENV['UPLOAD_PATH'] ?? 'uploads',
        'allowed_extensions' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'video' => ['mp4', 'webm', 'ogg'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'],
        ],
    ],

    // Message Settings
    'messages' => [
        'ephemeral_ttl' => (int)($_ENV['EPHEMERAL_TTL'] ?? 86400), // 24 hours
        'batch_size' => (int)($_ENV['MESSAGE_BATCH_SIZE'] ?? 50),
        'max_length' => 4096,
    ],

    // Polling Settings
    'polling' => [
        'interval' => (int)($_ENV['POLLING_INTERVAL'] ?? 2000), // 2 seconds
        'timeout' => (int)($_ENV['POLLING_TIMEOUT'] ?? 30000), // 30 seconds
    ],

    // Admin Panel
    'admin' => [
        'path' => $_ENV['ADMIN_PATH'] ?? '/admin-secret-12345',
        'enabled' => $_ENV['ADMIN_ENABLED'] === 'true' ? true : false,
    ],

    // CORS Configuration
    'cors' => [
        'origins' => explode(',', $_ENV['CORS_ORIGINS'] ?? '*'),
        'allow_credentials' => $_ENV['CORS_ALLOW_CREDENTIALS'] === 'true' ? true : false,
        'max_age' => (int)($_ENV['CORS_MAX_AGE'] ?? 3600),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ],

    // Rate Limiting
    'rate_limit' => [
        'enabled' => $_ENV['RATE_LIMIT_ENABLED'] === 'true' ? true : false,
        'requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 60),
        'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
    ],

    // Email Configuration
    'mail' => [
        'driver' => $_ENV['MAIL_DRIVER'] ?? 'smtp',
        'host' => $_ENV['MAIL_HOST'] ?? '',
        'port' => $_ENV['MAIL_PORT'] ?? 587,
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'from' => [
            'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@namak.local',
            'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Namak',
        ],
    ],

    // Logging
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
        'path' => BASE_PATH . ($_ENV['LOG_PATH'] ?? '/storage/logs'),
        'max_size' => 10485760, // 10MB
    ],

    // Security
    'security' => [
        'hash_algorithm' => $_ENV['HASH_ALGORITHM'] ?? 'argon2id',
        'encryption_cipher' => $_ENV['ENCRYPTION_CIPHER'] ?? 'AES-256-GCM',
    ],

    // Features
    'features' => [
        'end_to_end_encryption' => true,
        'ephemeral_messages' => true,
        'message_reactions' => true,
        'message_editing' => true,
        'message_deletion' => true,
        'group_chats' => true,
        'voice_messages' => true,
        'video_calls' => false, // Requires additional setup
        'voice_calls' => false, // Requires additional setup
    ],
];
?>

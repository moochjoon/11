<?php
/**
 * Security Configuration
 * Encryption, JWT, hashing, and security settings
 */

return [
    // Encryption settings
    'encryption' => [
        'algorithm' => $_ENV['ENCRYPTION_CIPHER'] ?? 'AES-256-GCM',
        'key' => $_ENV['ENCRYPTION_KEY'] ?? '',
        'key_size' => 32, // 256 bits
        'iv_size' => 12, // 96 bits for GCM
    ],

    // JWT (JSON Web Token) settings
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? '',
        'algorithm' => 'HS256',
        'ttl' => 86400, // 24 hours
        'refresh_ttl' => 604800, // 7 days
        'issuer' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
        'audience' => $_ENV['APP_NAME'] ?? 'Namak',
    ],

    // Password hashing
    'hashing' => [
        'algorithm' => $_ENV['HASH_ALGORITHM'] ?? 'argon2id',
        'argon2' => [
            'memory' => 65536, // 64MB
            'time' => 4,
            'threads' => 1,
        ],
        'bcrypt' => [
            'rounds' => 12,
        ],
    ],

    // CORS (Cross-Origin Resource Sharing)
    'cors' => [
        'allowed_origins' => explode(',', $_ENV['CORS_ORIGINS'] ?? '*'),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
        'exposed_headers' => ['X-Total-Count', 'X-Page-Count'],
        'allow_credentials' => $_ENV['CORS_ALLOW_CREDENTIALS'] === 'true' ? true : false,
        'max_age' => 3600,
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled' => $_ENV['RATE_LIMIT_ENABLED'] === 'true' ? true : false,
        'requests_per_minute' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 60),
        'requests_per_hour' => 1000,
        'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
        'storage' => 'cache', // 'cache' or 'database'
    ],

    // CSRF Protection
    'csrf' => [
        'enabled' => true,
        'token_name' => '_token',
        'header_name' => 'X-CSRF-Token',
        'cookie_name' => 'XSRF-TOKEN',
        'excluded_methods' => ['GET', 'HEAD', 'OPTIONS'],
    ],

    // Session security
    'session' => [
        'regenerate_on_login' => true,
        'regenerate_on_logout' => true,
        'timeout' => (int)($_ENV['SESSION_LIFETIME'] ?? 604800),
        'idle_timeout' => 1800, // 30 minutes
    ],

    // Password policy
    'password_policy' => [
        'min_length' => 6,
        'max_length' => 128,
        'require_uppercase' => false,
        'require_lowercase' => false,
        'require_numbers' => false,
        'require_special_chars' => false,
        'history' => 0, // number of previous passwords to check
    ],

    // Two-factor authentication
    '2fa' => [
        'enabled' => false,
        'methods' => ['totp', 'email', 'sms'],
        'required_for_admin' => false,
    ],

    // API security
    'api' => [
        'require_https' => $_ENV['APP_ENV'] === 'production' ? true : false,
        'api_key_required' => false,
        'api_secret' => $_ENV['API_SECRET'] ?? '',
        'token_storage' => 'header', // 'header', 'cookie', or 'both'
        'token_prefix' => 'Bearer',
    ],

    // File upload security
    'upload' => [
        'max_file_size' => (int)($_ENV['MAX_FILE_SIZE'] ?? 52428880), // 50MB
        'max_avatar_size' => (int)($_ENV['MAX_AVATAR_SIZE'] ?? 5242880), // 5MB
        'allowed_extensions' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'video' => ['mp4', 'webm', 'ogg'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip'],
        ],
        'scan_for_virus' => false, // requires ClamAV
        'validate_mime_type' => true,
    ],

    // Content Security Policy
    'csp' => [
        'enabled' => true,
        'default_src' => ["'self'"],
        'script_src' => ["'self'", "'unsafe-inline'"],
        'style_src' => ["'self'", "'unsafe-inline'"],
        'img_src' => ["'self'", 'data:', 'https:'],
        'font_src' => ["'self'", 'data:'],
        'connect_src' => ["'self'"],
        'frame_ancestors' => ["'none'"],
        'base_uri' => ["'self'"],
        'form_action' => ["'self'"],
    ],

    // Security headers
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'no-referrer-when-downgrade',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains', // HSTS
    ],

    // IP whitelisting/blacklisting
    'ip_filtering' => [
        'enabled' => false,
        'whitelist' => [],
        'blacklist' => [],
    ],

    // Login security
    'login' => [
        'max_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
        'require_email_verification' => false,
        'require_phone_verification' => false,
    ],

    // Account security
    'account' => [
        'allow_account_deletion' => true,
        'require_password_on_deletion' => true,
        'data_retention_days' => 30, // days to keep deleted data
    ],

    // Encryption for specific fields
    'encrypted_fields' => [
        'users' => ['phone', 'email'],
        'messages' => ['content'],
        'chats' => [],
    ],

    // API versioning security
    'api_versioning' => [
        'enabled' => true,
        'header' => 'X-API-Version',
        'default_version' => 'v1',
    ],

    // Development security settings
    'development' => [
        'debug_mode' => $_ENV['DEBUG_MODE'] === 'true' ? true : false,
        'display_errors' => $_ENV['DISPLAY_ERRORS'] === 'true' ? true : false,
        'query_logging' => $_ENV['QUERY_LOG'] === 'true' ? true : false,
    ],
];
?>

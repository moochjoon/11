<?php
/**
 * Database Configuration
 * MySQL database connection settings
 */

return [
    // Default database connection
    'default' => $_ENV['DB_DRIVER'] ?? 'mysql',

    // MySQL Connection
    'mysql' => [
        'driver' => 'mysql',
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_NAME'] ?? 'namak',
        'username' => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => 'nm_',
        'strict' => true,
        'engine' => 'InnoDB',
    ],

    // Connection pool settings
    'pool' => [
        'min_connections' => 2,
        'max_connections' => 10,
        'idle_timeout' => 300,
    ],

    // Query settings
    'query' => [
        'timeout' => 30,
        'read_timeout' => 10,
        'write_timeout' => 10,
    ],

    // SSL/TLS settings (if needed)
    'ssl' => [
        'enabled' => false,
        'ca' => null,
        'cert' => null,
        'key' => null,
        'verify' => false,
    ],

    // Replication settings (optional)
    'replicas' => [],

    // Cache query results
    'cache' => [
        'enabled' => false,
        'ttl' => 3600,
    ],

    // Query logging
    'logging' => [
        'enabled' => $_ENV['QUERY_LOG'] === 'true' ? true : false,
        'path' => BASE_PATH . '/storage/logs/queries.log',
        'slow_query_time' => 1.0, // seconds
    ],

    // Migrations
    'migrations' => [
        'table' => 'migrations',
        'path' => BASE_PATH . '/database/migrations',
    ],

    // Seeds
    'seeds' => [
        'path' => BASE_PATH . '/database/seeds',
    ],
];
?>

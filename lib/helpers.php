<?php
/**
 * Global Helper Functions
 * Utility functions used throughout the application
 */

/**
 * Get environment variable
 */
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        return $_ENV[$key] ?? $default;
    }
}

/**
 * Get configuration value
 */
if (!function_exists('config')) {
    function config($key, $default = null)
    {
        $parts = explode('.', $key);
        $config = $GLOBALS['config'] ?? [];

        foreach ($parts as $part) {
            if (isset($config[$part])) {
                $config = $config[$part];
            } else {
                return $default;
            }
        }

        return $config;
    }
}

/**
 * Get translation string
 */
if (!function_exists('trans')) {
    function trans($key, $params = [])
    {
        $translations = $GLOBALS['translations'] ?? [];
        $keys = explode('.', $key);
        $value = $translations;

        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $key;
            }
        }

        // Replace parameters
        foreach ($params as $param_key => $param_value) {
            $value = str_replace('{' . $param_key . '}', $param_value, $value);
        }

        return $value;
    }
}

/**
 * Short translation function
 */
if (!function_exists('__')) {
    function __($key, $params = [])
    {
        return trans($key, $params);
    }
}

/**
 * Translate and echo
 */
if (!function_exists('trans_echo')) {
    function trans_echo($key, $params = [])
    {
        echo trans($key, $params);
    }
}

/**
 * Get logger instance
 */
if (!function_exists('logger')) {
    function logger()
    {
        return $GLOBALS['logger'] ?? null;
    }
}

/**
 * Log message
 */
if (!function_exists('log_info')) {
    function log_info($message, $context = [])
    {
        if ($logger = logger()) {
            $logger->info($message, $context);
        }
    }
}

/**
 * Log error
 */
if (!function_exists('log_error')) {
    function log_error($message, $context = [])
    {
        if ($logger = logger()) {
            $logger->error($message, $context);
        }
    }
}

/**
 * Sanitize string input
 */
if (!function_exists('sanitize')) {
    function sanitize($string)
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Escape HTML
 */
if (!function_exists('escape')) {
    function escape($string)
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Check if string is JSON
 */
if (!function_exists('is_json')) {
    function is_json($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

/**
 * Generate random string
 */
if (!function_exists('random_string')) {
    function random_string($length = 32)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $result;
    }
}

/**
 * Generate UUID
 */
if (!function_exists('uuid')) {
    function uuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * Hash password
 */
if (!function_exists('hash_password')) {
    function hash_password($password)
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
    }
}

/**
 * Verify password
 */
if (!function_exists('verify_password')) {
    function verify_password($password, $hash)
    {
        return password_verify($password, $hash);
    }
}

/**
 * Get current user
 */
if (!function_exists('current_user')) {
    function current_user()
    {
        return $_SESSION['user'] ?? null;
    }
}

/**
 * Get current user ID
 */
if (!function_exists('current_user_id')) {
    function current_user_id()
    {
        return $_SESSION['user_id'] ?? null;
    }
}

/**
 * Check if user is authenticated
 */
if (!function_exists('is_authenticated')) {
    function is_authenticated()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

/**
 * Get current language
 */
if (!function_exists('current_lang')) {
    function current_lang()
    {
        return $_SESSION['lang'] ?? 'en';
    }
}

/**
 * Check if RTL language
 */
if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        $rtl_languages = config('app.rtl_languages', ['fa', 'ar', 'ur', 'he']);
        return in_array(current_lang(), $rtl_languages);
    }
}

/**
 * Format date
 */
if (!function_exists('format_date')) {
    function format_date($date, $format = 'Y-m-d H:i:s')
    {
        if (is_string($date)) {
            $date = strtotime($date);
        }

        return date($format, $date);
    }
}

/**
 * Time ago format
 */
if (!function_exists('time_ago')) {
    function time_ago($time)
    {
        if (is_string($time)) {
            $time = strtotime($time);
        }

        $now = time();
        $diff = $now - $time;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = round($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = round($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = round($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', $time);
        }
    }
}

/**
 * Format file size
 */
if (!function_exists('format_bytes')) {
    function format_bytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

/**
 * Validate email
 */
if (!function_exists('is_valid_email')) {
    function is_valid_email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Validate phone
 */
if (!function_exists('is_valid_phone')) {
    function is_valid_phone($phone)
    {
        return preg_match('/^\+?[0-9]{10,}$/', $phone);
    }
}

/**
 * Validate URL
 */
if (!function_exists('is_valid_url')) {
    function is_valid_url($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

/**
 * Strip tags safely
 */
if (!function_exists('strip_tags_safe')) {
    function strip_tags_safe($string)
    {
        return strip_tags($string);
    }
}

/**
 * Truncate string
 */
if (!function_exists('truncate')) {
    function truncate($string, $length = 100, $suffix = '...')
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - strlen($suffix)) . $suffix;
    }
}

/**
 * Slug from string
 */
if (!function_exists('slug')) {
    function slug($string)
    {
        $string = strtolower($string);
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        $string = trim($string, '-');
        return $string;
    }
}

/**
 * Check if HTTPS
 */
if (!function_exists('is_https')) {
    function is_https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            $_SERVER['SERVER_PORT'] == 443;
    }
}

/**
 * Get base URL
 */
if (!function_exists('base_url')) {
    function base_url($path = '')
    {
        $protocol = is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base = $protocol . '://' . $host;

        if (!empty($path)) {
            $base .= '/' . ltrim($path, '/');
        }

        return $base;
    }
}

/**
 * Redirect
 */
if (!function_exists('redirect')) {
    function redirect($url, $status_code = 302)
    {
        header('Location: ' . $url, true, $status_code);
        exit;
    }
}

/**
 * Die and dump
 */
if (!function_exists('dd')) {
    function dd($data)
    {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        die;
    }
}

/**
 * Dump
 */
if (!function_exists('dump')) {
    function dump($data)
    {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
    }
}

/**
 * Pretty print JSON
 */
if (!function_exists('pretty_json')) {
    function pretty_json($data)
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Get client IP
 */
if (!function_exists('get_client_ip')) {
    function get_client_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        return trim($ip);
    }
}

/**
 * Send email
 */
if (!function_exists('send_email')) {
    function send_email($to, $subject, $message, $headers = [])
    {
        $default_headers = [
            'From' => config('mail.from.address'),
            'Content-Type' => 'text/html; charset=UTF-8',
        ];

        $headers = array_merge($default_headers, $headers);
        $header_string = '';

        foreach ($headers as $key => $value) {
            $header_string .= $key . ': ' . $value . "\r\n";
        }

        return mail($to, $subject, $message, $header_string);
    }
}

/**
 * Array only
 */
if (!function_exists('array_only')) {
    function array_only($array, $keys)
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

/**
 * Array except
 */
if (!function_exists('array_except')) {
    function array_except($array, $keys)
    {
        return array_diff_key($array, array_flip($keys));
    }
}

/**
 * Array get
 */
if (!function_exists('array_get')) {
    function array_get($array, $key, $default = null)
    {
        return $array[$key] ?? $default;
    }
}

/**
 * Array set
 */
if (!function_exists('array_set')) {
    function array_set(&$array, $key, $value)
    {
        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }
            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;
    }
}
?>

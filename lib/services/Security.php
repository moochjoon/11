<?php
/**
 * Security Service
 * Encryption, decryption, and security utilities
 */

namespace App\Services;

class Security
{
    private $encryption_key;
    private $cipher;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->encryption_key = $_ENV['ENCRYPTION_KEY'] ?? '';
        $this->cipher = $_ENV['ENCRYPTION_CIPHER'] ?? 'AES-256-GCM';

        if (empty($this->encryption_key)) {
            throw new \Exception('ENCRYPTION_KEY not set in environment');
        }
    }

    /**
     * Encrypt data
     */
    public function encrypt($data)
    {
        try {
            // Decode base64 key
            $key = base64_decode($this->encryption_key);

            // Generate IV
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipher));

            // Encrypt
            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            // Get authentication tag for GCM
            $tag = '';
            if (strpos($this->cipher, 'GCM') !== false) {
                openssl_encrypt(
                    '',
                    $this->cipher,
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag
                );
            }

            // Combine IV + encrypted data + tag and encode
            $combined = $iv . $encrypted . $tag;
            return base64_encode($combined);

        } catch (\Exception $e) {
            throw new \Exception('Encryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt data
     */
    public function decrypt($encrypted_data)
    {
        try {
            // Decode base64
            $combined = base64_decode($encrypted_data);

            // Decode base64 key
            $key = base64_decode($this->encryption_key);

            // Get IV length
            $iv_length = openssl_cipher_iv_length($this->cipher);

            // Extract IV
            $iv = substr($combined, 0, $iv_length);

            // Extract tag and encrypted data for GCM
            $tag = '';
            if (strpos($this->cipher, 'GCM') !== false) {
                $tag = substr($combined, -16);
                $encrypted = substr($combined, $iv_length, -16);
            } else {
                $encrypted = substr($combined, $iv_length);
            }

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new \Exception('Decryption failed');
            }

            return $decrypted;

        } catch (\Exception $e) {
            throw new \Exception('Decryption failed: ' . $e->getMessage());
        }
    }

    /**
     * Hash data with HMAC
     */
    public function hash($data, $algorithm = 'sha256')
    {
        $key = base64_decode($this->encryption_key);
        return hash_hmac($algorithm, $data, $key);
    }

    /**
     * Verify HMAC hash
     */
    public function verifyHash($data, $hash, $algorithm = 'sha256')
    {
        $computed = $this->hash($data, $algorithm);
        return hash_equals($computed, $hash);
    }

    /**
     * Generate CSRF token
     */
    public function generateCSRFToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token)
    {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Sanitize input
     */
    public function sanitize($data, $type = 'string')
    {
        switch ($type) {
            case 'string':
                return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');

            case 'email':
                return filter_var($data, FILTER_SANITIZE_EMAIL);

            case 'url':
                return filter_var($data, FILTER_SANITIZE_URL);

            case 'int':
                return (int)filter_var($data, FILTER_SANITIZE_NUMBER_INT);

            case 'float':
                return (float)filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT);

            case 'sql':
                return addslashes($data);

            default:
                return $data;
        }
    }

    /**
     * Validate input
     */
    public function validate($data, $rules)
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $rule_array = explode('|', $rule);

            foreach ($rule_array as $r) {
                $r = trim($r);

                if ($r === 'required' && empty($value)) {
                    $errors[$field] = "{$field} is required";
                }

                if (strpos($r, 'min:') === 0 && strlen($value) < (int)substr($r, 4)) {
                    $min = (int)substr($r, 4);
                    $errors[$field] = "{$field} must be at least {$min} characters";
                }

                if (strpos($r, 'max:') === 0 && strlen($value) > (int)substr($r, 4)) {
                    $max = (int)substr($r, 4);
                    $errors[$field] = "{$field} must not exceed {$max} characters";
                }

                if ($r === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "{$field} must be a valid email";
                }

                if ($r === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$field] = "{$field} must be a valid URL";
                }

                if ($r === 'numeric' && !is_numeric($value)) {
                    $errors[$field] = "{$field} must be numeric";
                }

                if ($r === 'alpha' && !ctype_alpha($value)) {
                    $errors[$field] = "{$field} must contain only letters";
                }

                if ($r === 'alphanumeric' && !ctype_alnum($value)) {
                    $errors[$field] = "{$field} must contain only letters and numbers";
                }
            }
        }

        return $errors;
    }

    /**
     * Generate secure random token
     */
    public function generateToken($length = 32)
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Rate limit check
     */
    public function checkRateLimit($identifier, $limit = 60, $window = 60)
    {
        $cache = new Cache();
        $key = 'rate_limit_' . md5($identifier);
        $attempts = $cache->get($key, 0);

        if ($attempts >= $limit) {
            return false;
        }

        $attempts++;
        $cache->set($key, $attempts, $window);
        return true;
    }

    /**
     * Get rate limit info
     */
    public function getRateLimitInfo($identifier, $limit = 60, $window = 60)
    {
        $cache = new Cache();
        $key = 'rate_limit_' . md5($identifier);
        $attempts = $cache->get($key, 0);

        return [
            'attempts' => $attempts,
            'limit' => $limit,
            'remaining' => max(0, $limit - $attempts),
            'reset' => time() + $window,
        ];
    }

    /**
     * Validate file upload
     */
    public function validateFileUpload($file, $allowed_extensions = [], $max_size = 52428800)
    {
        $errors = [];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['file'] = 'File upload failed';
            return $errors;
        }

        // Check file size
        if ($file['size'] > $max_size) {
            $errors['size'] = 'File size exceeds maximum allowed';
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!empty($allowed_extensions) && !in_array($extension, $allowed_extensions)) {
            $errors['extension'] = 'File type not allowed';
        }

        // Check MIME type
        $mime_type = mime_content_type($file['tmp_name']);
        $allowed_mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'pdf' => 'application/pdf',
        ];

        if (isset($allowed_mimes[$extension]) && $mime_type !== $allowed_mimes[$extension]) {
            $errors['mime'] = 'Invalid file MIME type';
        }

        return $errors;
    }

    /**
     * Generate file hash
     */
    public function hashFile($file_path, $algorithm = 'sha256')
    {
        if (!file_exists($file_path)) {
            return false;
        }

        return hash_file($algorithm, $file_path);
    }

    /**
     * Verify file hash
     */
    public function verifyFileHash($file_path, $hash, $algorithm = 'sha256')
    {
        $computed = $this->hashFile($file_path, $algorithm);
        return hash_equals($computed, $hash);
    }

    /**
     * XSS protection - sanitize HTML
     */
    public function sanitizeHTML($html)
    {
        $allowed_tags = '<b><i><u><br><p><a><img><ul><li><ol>';
        return strip_tags($html, $allowed_tags);
    }

    /**
     * SQL injection protection - prepared statements
     * (Already handled by Database class)
     */

    /**
     * Get security headers
     */
    public function getSecurityHeaders()
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];
    }
}
?>

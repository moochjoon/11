<?php
/**
 * Authentication Service
 * User login, registration, and token management
 */

namespace App\Services;

use Namak\Database;

class Auth
{
    private $db;
    private $jwt_secret;
    private $jwt_ttl;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database(require BASE_PATH . '/config/database.php');
        $this->jwt_secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key';
        $this->jwt_ttl = (int)($_ENV['JWT_TTL'] ?? 86400);
    }

    /**
     * User login
     */
    public function login($username, $password)
    {
        try {
            // Find user by username, email, or phone
            $user = $this->db->first(
                "SELECT * FROM nm_users WHERE username = ? OR email = ? OR phone = ? LIMIT 1",
                [$username, $username, $username]
            );

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }

            // Verify password
            if (!password_verify($password, $user['password'])) {
                // Log failed attempt
                $this->logLoginAttempt($user['id'], false);

                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }

            // Check if user is active
            if ($user['status'] !== 'active') {
                return [
                    'success' => false,
                    'message' => 'Account is inactive'
                ];
            }

            // Update last login
            $this->db->update('users', [
                'last_login' => date('Y-m-d H:i:s'),
                'last_seen' => date('Y-m-d H:i:s'),
            ], ['id' => $user['id']]);

            // Log successful login
            $this->logLoginAttempt($user['id'], true);

            // Return user data
            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'avatar' => $user['avatar'],
                    'status' => $user['status'],
                    'last_seen' => $user['last_seen'],
                    'created_at' => $user['created_at'],
                ]
            ];

        } catch (\Exception $e) {
            \Namak\Logger::getInstance()->error('Login error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred during login'
            ];
        }
    }

    /**
     * User registration
     */
    public function register($username, $password, $email = null, $phone = null)
    {
        try {
            // Validate input
            $errors = [];

            if (empty($username)) {
                $errors['username'] = 'Username is required';
            } elseif (strlen($username) < 3) {
                $errors['username'] = 'Username must be at least 3 characters';
            }

            if (empty($password)) {
                $errors['password'] = 'Password is required';
            } elseif (strlen($password) < 6) {
                $errors['password'] = 'Password must be at least 6 characters';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $errors
                ];
            }

            // Check if user already exists
            $existing = $this->db->first(
                "SELECT id FROM nm_users WHERE username = ? OR email = ? OR phone = ? LIMIT 1",
                [$username, $email, $phone]
            );

            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'User already exists'
                ];
            }

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ]);

            // Create user
            $user_id = $this->db->insert('users', [
                'username' => $username,
                'email' => $email,
                'phone' => $phone,
                'password' => $hashed_password,
                'avatar' => null,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Get created user
            $user = $this->db->first("SELECT * FROM nm_users WHERE id = ?", [$user_id]);

            return [
                'success' => true,
                'message' => 'Registration successful',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'avatar' => $user['avatar'],
                    'status' => $user['status'],
                    'created_at' => $user['created_at'],
                ]
            ];

        } catch (\Exception $e) {
            \Namak\Logger::getInstance()->error('Registration error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred during registration'
            ];
        }
    }

    /**
     * Generate JWT token
     */
    public function generateToken($user)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'sub' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'iat' => time(),
            'exp' => time() + $this->jwt_ttl,
        ]);

        $base64_header = $this->base64UrlEncode($header);
        $base64_payload = $this->base64UrlEncode($payload);

        $signature = hash_hmac(
            'sha256',
            $base64_header . '.' . $base64_payload,
            $this->jwt_secret,
            true
        );

        $base64_signature = $this->base64UrlEncode($signature);

        return $base64_header . '.' . $base64_payload . '.' . $base64_signature;
    }

    /**
     * Verify JWT token
     */
    public function verifyToken($token)
    {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                return null;
            }

            $header = json_decode($this->base64UrlDecode($parts[0]));
            $payload = json_decode($this->base64UrlDecode($parts[1]));
            $signature = $parts[2];

            // Verify signature
            $expected_signature = hash_hmac(
                'sha256',
                $parts[0] . '.' . $parts[1],
                $this->jwt_secret,
                true
            );

            $expected_signature_encoded = $this->base64UrlEncode($expected_signature);

            if ($signature !== $expected_signature_encoded) {
                return null;
            }

            // Check expiration
            if ($payload->exp < time()) {
                return null;
            }

            return $payload;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Refresh token
     */
    public function refreshToken($old_token)
    {
        $payload = $this->verifyToken($old_token);

        if (!$payload) {
            return null;
        }

        // Get user
        $user = $this->db->first("SELECT * FROM nm_users WHERE id = ?", [$payload->sub]);

        if (!$user) {
            return null;
        }

        return $this->generateToken($user);
    }

    /**
     * Logout user
     */
    public function logout($user_id)
    {
        try {
            // Update last seen
            $this->db->update('users', [
                'last_seen' => date('Y-m-d H:i:s'),
            ], ['id' => $user_id]);

            return [
                'success' => true,
                'message' => 'Logged out successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An error occurred during logout'
            ];
        }
    }

    /**
     * Change password
     */
    public function changePassword($user_id, $old_password, $new_password)
    {
        try {
            // Get user
            $user = $this->db->first("SELECT * FROM nm_users WHERE id = ?", [$user_id]);

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found'
                ];
            }

            // Verify old password
            if (!password_verify($old_password, $user['password'])) {
                return [
                    'success' => false,
                    'message' => 'Old password is incorrect'
                ];
            }

            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ]);

            // Update password
            $this->db->update('users', [
                'password' => $hashed_password,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $user_id]);

            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An error occurred'
            ];
        }
    }

    /**
     * Log login attempt
     */
    private function logLoginAttempt($user_id, $success)
    {
        try {
            $this->db->insert('login_attempts', [
                'user_id' => $user_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'success' => $success ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($data)
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
?>

<?php
/**
 * Authentication Middleware
 * Verify user authentication and token validity
 */

namespace App\Middleware;

use Namak\Request;
use Namak\Response;
use App\Services\Auth;

class AuthMiddleware
{
    /**
     * Handle middleware
     */
    public static function handle(Request $request, Response $response)
    {
        // Get authorization header
        $token = $request->getToken();

        if (!$token) {
            return $response->error('Authorization token required', [], 401);
        }

        try {
            // Verify token
            $auth = new Auth();
            $payload = $auth->verifyToken($token);

            if (!$payload) {
                return $response->error('Invalid or expired token', [], 401);
            }

            // Check if token is blacklisted
            $cache = new \App\Services\Cache();
            if ($cache->get('token_blacklist_' . $token)) {
                return $response->error('Token has been revoked', [], 401);
            }

            // Store user info in request for later use
            $request->user_id = $payload->sub;
            $request->user_data = $payload;

            return true;

        } catch (\Exception $e) {
            return $response->error('Authentication failed: ' . $e->getMessage(), [], 401);
        }
    }

    /**
     * Check if request is authenticated
     */
    public static function isAuthenticated(Request $request)
    {
        return isset($request->user_id) && !empty($request->user_id);
    }

    /**
     * Get authenticated user ID
     */
    public static function getUserId(Request $request)
    {
        return $request->user_id ?? null;
    }

    /**
     * Get authenticated user data
     */
    public static function getUserData(Request $request)
    {
        return $request->user_data ?? null;
    }
}
?>

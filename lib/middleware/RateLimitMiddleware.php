<?php
/**
 * Rate Limit Middleware
 * Prevent abuse by limiting requests
 */

namespace App\Middleware;

use Namak\Request;
use Namak\Response;
use App\Services\Cache;
use App\Services\Security;

class RateLimitMiddleware
{
    private static $limit = 60;
    private static $window = 60;

    /**
     * Handle middleware
     */
    public static function handle(Request $request, Response $response)
    {
        // Get rate limit config
        $limit = $_ENV['RATE_LIMIT_REQUESTS'] ?? self::$limit;
        $window = $_ENV['RATE_LIMIT_WINDOW'] ?? self::$window;

        // Get client identifier (IP or user ID)
        $identifier = self::getIdentifier($request);

        // Check rate limit
        $security = new Security();
        if (!$security->checkRateLimit($identifier, $limit, $window)) {
            return $response->error(
                'Too many requests. Please try again later.',
                [],
                429
            )->rateLimit($limit, 0, time() + $window);
        }

        // Get rate limit info
        $rate_info = $security->getRateLimitInfo($identifier, $limit, $window);

        // Add rate limit headers to response
        $response->rateLimit(
            $rate_info['limit'],
            $rate_info['remaining'],
            $rate_info['reset']
        );

        return true;
    }

    /**
     * Get client identifier
     */
    private static function getIdentifier(Request $request)
    {
        // Use user ID if authenticated
        if (isset($_SESSION['user_id'])) {
            return 'user_' . $_SESSION['user_id'];
        }

        // Use IP address
        return 'ip_' . md5($request->getIp());
    }

    /**
     * Set custom limits
     */
    public static function setLimits($limit, $window)
    {
        self::$limit = $limit;
        self::$window = $window;
    }
}
?>

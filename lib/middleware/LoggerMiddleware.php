<?php
/**
 * Logger Middleware
 * Log HTTP requests and responses
 */

namespace App\Middleware;

use Namak\Request;
use Namak\Response;
use Namak\Logger;

class LoggerMiddleware
{
    /**
     * Handle middleware
     */
    public static function handle(Request $request, Response $response)
    {
        $logger = Logger::getInstance();

        // Log request
        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'ip' => $request->getIp(),
            'user_agent' => $request->getUserAgent(),
        ];

        $logger->info("HTTP {$request->getMethod()} {$request->getPath()}", $context);

        return true;
    }

    /**
     * Log response
     */
    public static function logResponse(Request $request, Response $response, $status_code)
    {
        $logger = Logger::getInstance();

        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'status' => $status_code,
            'ip' => $request->getIp(),
        ];

        $level = $status_code >= 500 ? 'error' : 'info';
        $logger->log("HTTP Response {$status_code}", $level, $context);
    }
}
?>

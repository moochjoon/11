<?php
/**
 * Response Class
 * HTTP response handling and output
 */

namespace Namak;

class Response
{
    private $status_code = 200;
    private $headers = [];
    private $body = '';
    private $content_type = 'text/html';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setDefaultHeaders();
    }

    /**
     * Set default headers
     */
    private function setDefaultHeaders()
    {
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->header('X-Content-Type-Options', 'nosniff');
        $this->header('X-Frame-Options', 'SAMEORIGIN');
        $this->header('X-XSS-Protection', '1; mode=block');
    }

    /**
     * Set status code
     */
    public function setStatusCode($code)
    {
        $this->status_code = (int)$code;
        return $this;
    }

    /**
     * Get status code
     */
    public function getStatusCode()
    {
        return $this->status_code;
    }

    /**
     * Set header
     */
    public function header($key, $value)
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Get header
     */
    public function getHeader($key)
    {
        return $this->headers[$key] ?? null;
    }

    /**
     * Get all headers
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Set content type
     */
    public function setContentType($type)
    {
        $this->content_type = $type;
        $this->header('Content-Type', $type . '; charset=utf-8');
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json($data, $status_code = 200)
    {
        $this->setStatusCode($status_code);
        $this->setContentType('application/json');
        $this->body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * Send success response
     */
    public function success($data = [], $message = '', $status_code = 200)
    {
        return $this->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => time()
        ], $status_code);
    }

    /**
     * Send error response
     */
    public function error($message = '', $data = [], $status_code = 400)
    {
        return $this->json([
            'success' => false,
            'data' => $data,
            'message' => $message,
            'timestamp' => time()
        ], $status_code);
    }

    /**
     * Send HTML response
     */
    public function html($content, $status_code = 200)
    {
        $this->setStatusCode($status_code);
        $this->setContentType('text/html');
        $this->body = $content;
        return $this;
    }

    /**
     * Send plain text response
     */
    public function text($content, $status_code = 200)
    {
        $this->setStatusCode($status_code);
        $this->setContentType('text/plain');
        $this->body = $content;
        return $this;
    }

    /**
     * Send XML response
     */
    public function xml($data, $status_code = 200)
    {
        $this->setStatusCode($status_code);
        $this->setContentType('application/xml');
        $this->body = $this->arrayToXml($data);
        return $this;
    }

    /**
     * Send file download
     */
    public function download($file_path, $file_name = null)
    {
        if (!file_exists($file_path)) {
            return $this->error('File not found', [], 404);
        }

        $file_name = $file_name ?? basename($file_path);
        $file_size = filesize($file_path);
        $file_type = mime_content_type($file_path);

        $this->header('Content-Type', $file_type);
        $this->header('Content-Disposition', "attachment; filename=\"{$file_name}\"");
        $this->header('Content-Length', $file_size);
        $this->header('Pragma', 'public');
        $this->header('Cache-Control', 'public, must-revalidate');

        $this->body = file_get_contents($file_path);
        return $this;
    }

    /**
     * Send file display
     */
    public function file($file_path, $file_name = null)
    {
        if (!file_exists($file_path)) {
            return $this->error('File not found', [], 404);
        }

        $file_name = $file_name ?? basename($file_path);
        $file_size = filesize($file_path);
        $file_type = mime_content_type($file_path);

        $this->header('Content-Type', $file_type);
        $this->header('Content-Length', $file_size);
        $this->header('Cache-Control', 'public');

        $this->body = file_get_contents($file_path);
        return $this;
    }

    /**
     * Send redirect
     */
    public function redirect($url, $status_code = 302)
    {
        $this->setStatusCode($status_code);
        $this->header('Location', $url);
        return $this;
    }

    /**
     * Set body
     */
    public function setBody($content)
    {
        $this->body = $content;
        return $this;
    }

    /**
     * Get body
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Append to body
     */
    public function appendBody($content)
    {
        $this->body .= $content;
        return $this;
    }

    /**
     * Send response
     */
    public function send()
    {
        // Send status code
        http_response_code($this->status_code);

        // Send headers
        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }

        // Send body
        echo $this->body;

        // Exit
        exit;
    }

    /**
     * Send headers only (no body)
     */
    public function sendHeaders()
    {
        http_response_code($this->status_code);

        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }

        return $this;
    }

    /**
     * Convert array to XML
     */
    private function arrayToXml($data, $root = 'root')
    {
        $xml = new \SimpleXMLElement('<' . $root . '/>');

        $this->arrayToXmlRecursive($data, $xml);

        return $xml->asXML();
    }

    /**
     * Recursively convert array to XML
     */
    private function arrayToXmlRecursive($data, &$xml)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $subnode = $xml->addChild($key);
                $this->arrayToXmlRecursive($value, $subnode);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    /**
     * Set cache headers
     */
    public function cache($seconds = 3600)
    {
        $this->header('Cache-Control', 'public, max-age=' . $seconds);
        $this->header('Expires', gmdate('D, d M Y H:i:s T', time() + $seconds));
        return $this;
    }

    /**
     * Set no-cache headers
     */
    public function noCache()
    {
        $this->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->header('Pragma', 'no-cache');
        $this->header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        return $this;
    }

    /**
     * Set CORS headers
     */
    public function cors($origin = '*', $methods = 'GET, POST, PUT, DELETE, OPTIONS', $headers = 'Content-Type, Authorization')
    {
        $this->header('Access-Control-Allow-Origin', $origin);
        $this->header('Access-Control-Allow-Methods', $methods);
        $this->header('Access-Control-Allow-Headers', $headers);
        $this->header('Access-Control-Allow-Credentials', 'true');
        return $this;
    }

    /**
     * Set pagination headers
     */
    public function pagination($total, $page, $per_page)
    {
        $this->header('X-Total-Count', $total);
        $this->header('X-Page-Count', ceil($total / $per_page));
        $this->header('X-Per-Page', $per_page);
        $this->header('X-Current-Page', $page);
        return $this;
    }

    /**
     * Set rate limit headers
     */
    public function rateLimit($limit, $remaining, $reset)
    {
        $this->header('X-RateLimit-Limit', $limit);
        $this->header('X-RateLimit-Remaining', $remaining);
        $this->header('X-RateLimit-Reset', $reset);
        return $this;
    }

    /**
     * Get HTTP status message
     */
    private function getStatusMessage($code)
    {
        $messages = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $messages[$code] ?? 'Unknown Status';
    }

    /**
     * Magic method for fluent interface
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            call_user_func_array([$this, $name], $arguments);
            return $this;
        }

        throw new \Exception("Method {$name} does not exist");
    }
}
?>

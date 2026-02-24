<?php
/**
 * Request Class
 * HTTP request handling and parsing
 */

namespace Namak;

class Request
{
    private $method;
    private $path;
    private $query;
    private $post;
    private $files;
    private $headers;
    private $body;
    private $json;
    private $ip;
    private $user_agent;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path = $this->parsePath();
        $this->query = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;
        $this->headers = $this->parseHeaders();
        $this->body = file_get_contents('php://input');
        $this->json = $this->parseJson();
        $this->ip = $this->getClientIp();
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Parse request path
     */
    private function parsePath()
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove base path if needed
        $base = dirname($_SERVER['SCRIPT_NAME']);
        if ($base !== '/') {
            $path = substr($path, strlen($base));
        }

        // Remove trailing slash
        $path = rtrim($path, '/') ?: '/';

        return $path;
    }

    /**
     * Parse HTTP headers
     */
    private function parseHeaders()
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header_name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($header_name)] = $value;
            }
        }

        return $headers;
    }

    /**
     * Parse JSON body
     */
    private function parseJson()
    {
        $content_type = $this->getHeader('content-type');

        if (strpos($content_type, 'application/json') !== false && !empty($this->body)) {
            return json_decode($this->body, true) ?? [];
        }

        return [];
    }

    /**
     * Get client IP address
     */
    private function getClientIp()
    {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return trim($ip);
    }

    /**
     * Get request method
     */
    public function getMethod()
    {
        return strtoupper($this->method);
    }

    /**
     * Check if method is
     */
    public function isMethod($method)
    {
        return $this->getMethod() === strtoupper($method);
    }

    public function isGet()
    {
        return $this->isMethod('GET');
    }

    public function isPost()
    {
        return $this->isMethod('POST');
    }

    public function isPut()
    {
        return $this->isMethod('PUT');
    }

    public function isDelete()
    {
        return $this->isMethod('DELETE');
    }

    public function isPatch()
    {
        return $this->isMethod('PATCH');
    }

    /**
     * Get request path
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Get query parameter
     */
    public function query($key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get POST parameter
     */
    public function post($key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }

        return $this->post[$key] ?? $default;
    }

    /**
     * Get JSON input
     */
    public function json($key = null, $default = null)
    {
        if ($key === null) {
            return $this->json;
        }

        return $this->json[$key] ?? $default;
    }

    /**
     * Get all input (query, post, json combined)
     */
    public function all()
    {
        return array_merge($this->query, $this->post, $this->json);
    }

    /**
     * Get specific input
     */
    public function input($key, $default = null)
    {
        // Check JSON first
        if (isset($this->json[$key])) {
            return $this->json[$key];
        }

        // Then POST
        if (isset($this->post[$key])) {
            return $this->post[$key];
        }

        // Finally GET
        if (isset($this->query[$key])) {
            return $this->query[$key];
        }

        return $default;
    }

    /**
     * Get file upload
     */
    public function file($key)
    {
        if (!isset($this->files[$key])) {
            return null;
        }

        return new UploadedFile($this->files[$key]);
    }

    /**
     * Get all files
     */
    public function files()
    {
        $files = [];

        foreach ($this->files as $key => $file) {
            $files[$key] = new UploadedFile($file);
        }

        return $files;
    }

    /**
     * Get header
     */
    public function header($key, $default = null)
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * Get all headers
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * Get authorization header
     */
    public function getToken()
    {
        $auth_header = $this->header('authorization');

        if (!$auth_header) {
            return null;
        }

        if (strpos($auth_header, 'Bearer ') === 0) {
            return substr($auth_header, 7);
        }

        return $auth_header;
    }

    /**
     * Get request body
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Get client IP
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * Get user agent
     */
    public function getUserAgent()
    {
        return $this->user_agent;
    }

    /**
     * Check if AJAX request
     */
    public function isAjax()
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    /**
     * Check if JSON request
     */
    public function isJson()
    {
        $content_type = $this->header('content-type');
        return strpos($content_type, 'application/json') !== false;
    }

    /**
     * Validate input
     */
    public function validate($rules)
    {
        $errors = [];
        $input = $this->all();

        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;
            $rule_array = explode('|', $rule);

            foreach ($rule_array as $r) {
                $r = trim($r);

                if ($r === 'required' && empty($value)) {
                    $errors[$field] = "{$field} is required";
                } elseif (strpos($r, 'min:') === 0 && strlen($value) < (int)substr($r, 4)) {
                    $min = (int)substr($r, 4);
                    $errors[$field] = "{$field} must be at least {$min} characters";
                } elseif (strpos($r, 'max:') === 0 && strlen($value) > (int)substr($r, 4)) {
                    $max = (int)substr($r, 4);
                    $errors[$field] = "{$field} must not exceed {$max} characters";
                } elseif ($r === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "{$field} must be a valid email";
                } elseif ($r === 'numeric' && !is_numeric($value)) {
                    $errors[$field] = "{$field} must be numeric";
                }
            }
        }

        return $errors;
    }
}

/**
 * Uploaded File Class
 */
class UploadedFile
{
    private $name;
    private $tmp_name;
    private $type;
    private $size;
    private $error;

    public function __construct($file_data)
    {
        $this->name = $file_data['name'] ?? '';
        $this->tmp_name = $file_data['tmp_name'] ?? '';
        $this->type = $file_data['type'] ?? '';
        $this->size = $file_data['size'] ?? 0;
        $this->error = $file_data['error'] ?? UPLOAD_ERR_NO_FILE;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getTmpName()
    {
        return $this->tmp_name;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function getError()
    {
        return $this->error;
    }

    public function isValid()
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmp_name);
    }

    public function move($destination_path, $new_name = null)
    {
        if (!$this->isValid()) {
            throw new \Exception("Invalid upload file");
        }

        $name = $new_name ?? $this->name;
        $destination = $destination_path . '/' . $name;

        if (move_uploaded_file($this->tmp_name, $destination)) {
            return $destination;
        } else {
            throw new \Exception("Failed to move uploaded file");
        }
    }

    public function getExtension()
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    public function getMimeType()
    {
        return mime_content_type($this->tmp_name);
    }
}
?>

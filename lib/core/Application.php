<?php
/**
 * Application Core Class
 * Main application bootstrap and lifecycle management
 */

namespace Namak;

class Application
{
    private static $instance = null;
    private $config = [];
    private $container = [];
    private $booted = false;

    /**
     * Constructor
     */
    private function __construct($config = [])
    {
        $this->config = $config;
        $this->registerBindings();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance($config = [])
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Register core bindings in container
     */
    private function registerBindings()
    {
        // Database instance
        $this->bind('database', function() {
            return new Database($this->config['database']);
        });

        // Cache instance
        $this->bind('cache', function() {
            return new \App\Services\Cache();
        });

        // Logger instance
        $this->bind('logger', function() {
            return Logger::getInstance();
        });

        // Request instance
        $this->bind('request', function() {
            return new Request();
        });

        // Response instance
        $this->bind('response', function() {
            return new Response();
        });

        // Router instance
        $this->bind('router', function() {
            return new Router();
        });

        // Auth Service
        $this->bind('auth', function() {
            return new \App\Services\Auth();
        });
    }

    /**
     * Bind a service to the container
     */
    public function bind($name, $resolver)
    {
        if (is_callable($resolver)) {
            $this->container[$name] = $resolver;
        } else {
            $this->container[$name] = function() use ($resolver) {
                return $resolver;
            };
        }
    }

    /**
     * Get a service from the container
     */
    public function make($name)
    {
        if (!isset($this->container[$name])) {
            throw new \Exception("Service '{$name}' not found in container");
        }

        return call_user_func($this->container[$name]);
    }

    /**
     * Boot the application
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        // Load middleware
        $this->loadMiddleware();

        // Register routes
        $this->registerRoutes();

        $this->booted = true;
    }

    /**
     * Load middleware
     */
    private function loadMiddleware()
    {
        // Middleware stack will be executed before route handling
    }

    /**
     * Register application routes
     */
    private function registerRoutes()
    {
        $router = $this->make('router');

        // API Routes
        $router->group(['prefix' => '/api/v1'], function($router) {
            // Auth routes
            $router->post('/auth/login', 'AuthController@login');
            $router->post('/auth/register', 'AuthController@register');
            $router->post('/auth/logout', 'AuthController@logout');
            $router->post('/auth/refresh', 'AuthController@refresh');

            // Chat routes (require auth)
            $router->group(['middleware' => 'auth'], function($router) {
                $router->get('/chats/list', 'ChatController@list');
                $router->post('/chats/create', 'ChatController@create');
                $router->post('/chats/archive', 'ChatController@archive');
                $router->get('/chats/search', 'ChatController@search');
            });

            // Message routes (require auth)
            $router->group(['middleware' => 'auth'], function($router) {
                $router->post('/messages/send', 'MessageController@send');
                $router->get('/messages/get', 'MessageController@get');
                $router->post('/messages/edit', 'MessageController@edit');
                $router->post('/messages/delete', 'MessageController@delete');
                $router->post('/messages/pin', 'MessageController@pin');
            });

            // User routes (require auth)
            $router->group(['middleware' => 'auth'], function($router) {
                $router->get('/users/profile', 'UserController@profile');
                $router->post('/users/update', 'UserController@update');
                $router->get('/users/contacts', 'UserController@contacts');
            });

            // Media routes (require auth)
            $router->group(['middleware' => 'auth'], function($router) {
                $router->post('/media/upload', 'MediaController@upload');
                $router->get('/media/download', 'MediaController@download');
                $router->post('/media/delete', 'MediaController@delete');
            });
        });

        // Web Routes
        $router->get('/', function() {
            header('Location: /app');
        });
        $router->get('/login', 'PageController@login');
        $router->get('/register', 'PageController@register');
        $router->get('/app', 'PageController@app');
    }

    /**
     * Run the application
     */
    public function run()
    {
        try {
            // Boot application
            $this->boot();

            // Get request info
            $request = $this->make('request');
            $router = $this->make('router');

            // Match and dispatch route
            $route = $router->match($request->getMethod(), $request->getPath());

            if ($route) {
                $response = $router->dispatch($route, $request);
                echo $response;
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Route not found'
                ]);
            }
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Handle application exceptions
     */
    private function handleException(\Exception $e)
    {
        $logger = $this->make('logger');
        $logger->error($e->getMessage());

        if ($this->config['debug']) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTrace()
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'An error occurred'
            ]);
        }
    }

    /**
     * Get configuration value
     */
    public function config($key, $default = null)
    {
        $parts = explode('.', $key);
        $value = $this->config;

        foreach ($parts as $part) {
            if (isset($value[$part])) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Check if application is in debug mode
     */
    public function isDebug()
    {
        return $this->config['debug'] ?? false;
    }

    /**
     * Get application environment
     */
    public function getEnv()
    {
        return $this->config['env'] ?? 'production';
    }

    /**
     * Get application name
     */
    public function getName()
    {
        return $this->config['name'] ?? 'Namak';
    }

    /**
     * Get container instance for advanced usage
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Check if booted
     */
    public function isBooted()
    {
        return $this->booted;
    }
}

/**
 * Global logger class
 */
class Logger
{
    private static $instance = null;
    private $log_file;
    private $level = 'info';

    private function __construct()
    {
        $this->log_file = LOG_PATH . '/app.log';
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message, $level = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] {$message}\n";
        @file_put_contents($this->log_file, $log_message, FILE_APPEND);
    }

    public function info($message)
    {
        $this->log($message, 'INFO');
    }

    public function error($message)
    {
        $this->log($message, 'ERROR');
    }

    public function warning($message)
    {
        $this->log($message, 'WARNING');
    }

    public function debug($message)
    {
        if (APP_DEBUG) {
            $this->log($message, 'DEBUG');
        }
    }
}
?>

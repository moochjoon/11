<?php
/**
 * Router Class
 * URL routing and request dispatching
 */

namespace Namak;

class Router
{
    private $routes = [];
    private $current_group_prefix = '';
    private $current_group_middleware = [];
    private $middleware = [];
    private $not_found_callback = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerDefaultMiddleware();
    }

    /**
     * Register default middleware
     */
    private function registerDefaultMiddleware()
    {
        $this->middleware('cors', function() {
            // CORS middleware will be handled in bootstrap
        });

        $this->middleware('auth', function() {
            // Auth middleware
        });

        $this->middleware('throttle', function() {
            // Rate limiting middleware
        });
    }

    /**
     * Register a GET route
     */
    public function get($path, $callback)
    {
        return $this->addRoute('GET', $path, $callback);
    }

    /**
     * Register a POST route
     */
    public function post($path, $callback)
    {
        return $this->addRoute('POST', $path, $callback);
    }

    /**
     * Register a PUT route
     */
    public function put($path, $callback)
    {
        return $this->addRoute('PUT', $path, $callback);
    }

    /**
     * Register a DELETE route
     */
    public function delete($path, $callback)
    {
        return $this->addRoute('DELETE', $path, $callback);
    }

    /**
     * Register a PATCH route
     */
    public function patch($path, $callback)
    {
        return $this->addRoute('PATCH', $path, $callback);
    }

    /**
     * Register OPTIONS route
     */
    public function options($path, $callback)
    {
        return $this->addRoute('OPTIONS', $path, $callback);
    }

    /**
     * Register route for all methods
     */
    public function any($path, $callback)
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $path, $callback);
        }
        return $this;
    }

    /**
     * Add route
     */
    private function addRoute($method, $path, $callback)
    {
        $path = $this->current_group_prefix . $path;

        $route = [
            'method' => $method,
            'path' => $path,
            'pattern' => $this->convertPathToPattern($path),
            'callback' => $callback,
            'middleware' => $this->current_group_middleware,
        ];

        $this->routes[] = $route;
        return $this;
    }

    /**
     * Convert path to regex pattern
     */
    private function convertPathToPattern($path)
    {
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
        return '/^' . str_replace('/', '\/', $pattern) . '$/';
    }

    /**
     * Create route group
     */
    public function group($options, $callback)
    {
        $previous_prefix = $this->current_group_prefix;
        $previous_middleware = $this->current_group_middleware;

        if (isset($options['prefix'])) {
            $this->current_group_prefix .= $options['prefix'];
        }

        if (isset($options['middleware'])) {
            $middleware = (array)$options['middleware'];
            $this->current_group_middleware = array_merge($this->current_group_middleware, $middleware);
        }

        call_user_func($callback, $this);

        $this->current_group_prefix = $previous_prefix;
        $this->current_group_middleware = $previous_middleware;

        return $this;
    }

    /**
     * Register middleware
     */
    public function middleware($name, $callback)
    {
        $this->middleware[$name] = $callback;
        return $this;
    }

    /**
     * Match request to route
     */
    public function match($method, $path)
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_numeric($key)) {
                        $params[$key] = $value;
                    }
                }

                $route['params'] = $params;
                return $route;
            }
        }

        return null;
    }

    /**
     * Dispatch route
     */
    public function dispatch($route, Request $request)
    {
        $response = new Response();

        // Execute middleware
        foreach ($route['middleware'] as $middleware_name) {
            if (isset($this->middleware[$middleware_name])) {
                $result = call_user_func($this->middleware[$middleware_name]);
                if ($result === false) {
                    return $response->error('Middleware rejected request', [], 403)->getBody();
                }
            }
        }

        // Execute callback
        $callback = $route['callback'];

        // If callback is string (Controller@action format)
        if (is_string($callback)) {
            [$controller_name, $action] = explode('@', $callback);
            $controller_class = '\\App\\Controllers\\' . $controller_name;

            if (!class_exists($controller_class)) {
                return $response->error('Controller not found: ' . $controller_class, [], 500)->getBody();
            }

            $controller = new $controller_class();

            if (!method_exists($controller, $action)) {
                return $response->error('Action not found: ' . $action, [], 500)->getBody();
            }

            $result = $controller->$action($request, $response);
        } else {
            // Closure callback
            $result = call_user_func_array($callback, [$request, $response]);
        }

        // Return result
        if ($result instanceof Response) {
            return $result->getBody();
        } elseif (is_array($result)) {
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            return (string)$result;
        }
    }

    /**
     * Get all routes
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Print routes table (for debugging)
     */
    public function debugRoutes()
    {
        $output = "Routes Table:\n";
        $output .= str_repeat("-", 80) . "\n";
        $output .= sprintf("%-8s | %-40s | %-20s\n", "METHOD", "PATH", "CALLBACK");
        $output .= str_repeat("-", 80) . "\n";

        foreach ($this->routes as $route) {
            $callback = is_string($route['callback']) ? $route['callback'] : 'Closure';
            $output .= sprintf("%-8s | %-40s | %-20s\n", $route['method'], $route['path'], $callback);
        }

        $output .= str_repeat("-", 80) . "\n";
        return $output;
    }

    /**
     * Handle 404 errors
     */
    public function notFound($callback)
    {
        $this->not_found_callback = $callback;
        return $this;
    }

    /**
     * Get 404 callback
     */
    public function getNotFoundCallback()
    {
        return $this->not_found_callback;
    }

    /**
     * URL generation
     */
    public function url($path, $params = [])
    {
        $url = $path;

        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', $value, $url);
        }

        return $url;
    }

    /**
     * Get route by name (if routes are named)
     */
    public function getRoute($name)
    {
        foreach ($this->routes as $route) {
            if (($route['name'] ?? null) === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Get current route from request
     */
    public function getCurrentRoute(Request $request)
    {
        return $this->match($request->getMethod(), $request->getPath());
    }

    /**
     * Check if route exists
     */
    public function hasRoute($method, $path)
    {
        return $this->match($method, $path) !== null;
    }

    /**
     * Get routes by prefix
     */
    public function getRoutesByPrefix($prefix)
    {
        $filtered_routes = [];

        foreach ($this->routes as $route) {
            if (strpos($route['path'], $prefix) === 0) {
                $filtered_routes[] = $route;
            }
        }

        return $filtered_routes;
    }
}
?>

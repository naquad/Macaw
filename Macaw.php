<?php

class Macaw
{

        public static $routes = array();

        public static $methods = array();

        public static $callbacks = array();

        public static $patterns = array(
            ':any' => '[^/]+',
            ':num' => '[0-9]+',
            ':all' => '.*'
        );

        public static $error_callback;

        public static $verbs_with_body = array(
            'OPTIONS',
            'PUT',
            'PATCH'
        );

        public static $content_type_json = '~
            ^(?:
            application/(?:json|x-javascript)
            |
            text/(?:javascript|x-javascript|x-json)
        )
        ~x';

    /**
     * Defines a route w/ callback and method
     */
    public static function __callstatic($method, $params)
    {
        $uri = $params[0];
        $callback = $params[1];

        array_push(self::$routes, $uri);
        array_push(self::$methods, strtoupper($method));
        array_push(self::$callbacks, $callback);
    }

    /**
     * Defines callback if route is not found
     */
    public static function error($callback)
    {
        self::$error_callback = $callback;
    }

    /**
     * Parses JSON request bodies, populates $_POST
     * for queries like PUT, PATCH, etc...
     */
    public static function preprocessInput()
    {
        if ($_SERVER['HTTP_CONTENT_LENGTH'] == 0) // empty request
            return;

        $is_json = preg_match(self::$content_type_json, $_SERVER['HTTP_CONTENT_TYPE']);
        if (in_array($_SERVER['REQUEST_METHOD'], self::$verbs_with_body) || $is_json) {
            $request = file_get_contents('php://input');

            if ($is_json) {
                $result = json_decode($request, true);
                $_POST = $result === false ? array() : $result;
            } else {
                parse_str($request, $_POST);
            }
        }
    }

    /**
     * Runs the callback for the given request
     */
    public static function dispatch($uri = null, $method = null)
    {
        self::preprocessInput();

        if($uri === null) {
            if (isset($_GET['p']))
                $uri = $_GET['p'];
            else if (!empty($_SERVER['PATH_INFO']))
                $uri = $_SERVER['PATH_INFO'];
            else
                $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        if ($method === null) {
            if (!empty($_REQUEST['_method']))
                $method = $_REQUEST['_method'];
            else
                $method = $_SERVER['REQUEST_METHOD'];
        }

        $searches = array_keys(static::$patterns);
        $replaces = array_values(static::$patterns);

        $found_route = false;

        // check if route is defined without regex
        if (in_array($uri, self::$routes)) {
            $route_pos = array_keys(self::$routes, $uri);
            foreach ($route_pos as $route) {
                if (self::$methods[$route] == $method) {
                    $found_route = true;
                    call_user_func(self::$callbacks[$route]);
                }
            }
        } else {
            // check if defined with regex
            $pos = 0;
            foreach (self::$routes as $route) {
                if (strpos($route, ':') !== false) {
                    $route = str_replace($searches, $replaces, $route);
                }

                if (preg_match('#^' . $route . '$#', $uri, $matched)) {
                    if (self::$methods[$pos] == $method) {
                        $found_route = true;
                        array_shift($matched); //remove $matched[0] as [1] is the first parameter.
                        call_user_func_array(self::$callbacks[$pos], $matched);
                    }
                }
                $pos++;
            }
        }

        // run the error callback if the route was not found
        if ($found_route == false) {
            if (!self::$error_callback) {
                self::$error_callback = function() {
                    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
                    echo '404';
                };
            }
            call_user_func(self::$error_callback);
        }
    }
}

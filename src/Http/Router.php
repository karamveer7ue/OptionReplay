<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $regex, 'handler' => $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['pattern'], $path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            try {
                $result = ($route['handler'])($params);
            } catch (HttpException $e) {
                $result = Response::error($e->getMessage(), $e->getCode());
            } catch (\Throwable $e) {
                \App\Core\Logger::error('app', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                $result = str_starts_with($path, '/api/')
                    ? Response::error(\App\Core\Env::get('APP_ENV') === 'local' ? $e->getMessage() : 'Server error', 500)
                    : Response::html(View::render('error', ['message' => \App\Core\Env::get('APP_ENV') === 'local' ? $e->getMessage() : 'Something went wrong.']), 500);
            }
            $result->send();
            return;
        }
        if (str_starts_with($path, '/api/')) {
            Response::error('Not found', 404)->send();
            return;
        }
        Response::html(View::render('error', ['message' => 'Page not found']), 404)->send();
    }
}

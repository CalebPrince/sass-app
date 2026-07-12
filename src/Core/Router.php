<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal, dependency-free HTTP router.
 *
 * Routes are registered as (METHOD, pattern, handler, middleware[]). Patterns
 * use `{param}` placeholders that compile to named capture groups. Middleware
 * are simple callables run before the handler; any of them may terminate the
 * request (e.g. an auth guard emitting 401) by not returning true.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:callable, middleware:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $p, callable $h, array $m = []): void    { $this->add('GET', $p, $h, $m); }
    public function post(string $p, callable $h, array $m = []): void   { $this->add('POST', $p, $h, $m); }
    public function patch(string $p, callable $h, array $m = []): void  { $this->add('PATCH', $p, $h, $m); }
    public function delete(string $p, callable $h, array $m = []): void { $this->add('DELETE', $p, $h, $m); }

    public function dispatch(Request $request): void
    {
        $matchedPathButNotMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $matchedPathButNotMethod = true;
                continue;
            }

            // Bind path params by name.
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = $matches[$i + 1];
            }

            // Run guards in order. A guard returns false to halt (it will have
            // already emitted its own JSON response).
            foreach ($route['middleware'] as $guard) {
                if ($guard($request) !== true) {
                    return;
                }
            }

            ($route['handler'])($request, $args);
            return;
        }

        if ($matchedPathButNotMethod) {
            Response::error('Method not allowed', 405);
        }
        Response::notFound('No route matches ' . $request->path);
    }
}

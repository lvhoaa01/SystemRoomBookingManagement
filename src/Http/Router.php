<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\CallableRequestHandler;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\RequestHandler;
use App\Shared\Http\MethodNotAllowedHttpException;
use App\Shared\Http\NotFoundHttpException;
use Closure;
use RuntimeException;

final class Router
{
    /** @var list<array{method: string, path: string, handler: Closure, middleware: list<Middleware>, name: ?string}> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $namedRoutes = [];

    /** @param list<Middleware> $middleware */
    public function get(string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param list<Middleware> $middleware */
    public function post(string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param list<Middleware> $middleware */
    public function patch(string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /** @param list<Middleware> $middleware */
    public function delete(string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /**
     * @param list<string> $methods
     * @param list<Middleware> $middleware
     */
    public function map(array $methods, string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        foreach ($methods as $index => $method) {
            $this->add($method, $path, $handler, $middleware, $index === 0 ? $name : null);
        }
    }

    /** @param list<Middleware> $middleware */
    public function add(string $method, string $path, callable $handler, array $middleware = [], ?string $name = null): void
    {
        $method = strtoupper($method);
        $path = self::normalizePath($path);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException("Phương thức route không được hỗ trợ: {$method}");
        }
        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) {
                throw new RuntimeException('Middleware phải triển khai Middleware interface.');
            }
        }
        if ($name !== null && isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Tên route bị trùng: {$name}");
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => Closure::fromCallable($handler),
            'middleware' => $middleware,
            'name' => $name,
        ];
        if ($name !== null) {
            $this->namedRoutes[$name] = $path;
        }
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];
        foreach ($this->routes as $route) {
            $parameters = $this->matchPath($route['path'], $request->path);
            if ($parameters === null) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $request = $request->withRouteParameters($parameters);
            $next = new CallableRequestHandler($route['handler']);
            foreach (array_reverse($route['middleware']) as $middleware) {
                $next = new class($middleware, $next) implements RequestHandler {
                    public function __construct(
                        private readonly Middleware $middleware,
                        private readonly RequestHandler $next,
                    ) {
                    }

                    public function handle(Request $request): Response
                    {
                        return $this->middleware->process($request, $this->next);
                    }
                };
            }

            return $next->handle($request);
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedHttpException($allowedMethods);
        }

        throw new NotFoundHttpException();
    }

    /** @param array<string, scalar> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        $path = $this->namedRoutes[$name] ?? null;
        if ($path === null) {
            throw new RuntimeException("Không tìm thấy route có tên: {$name}");
        }

        // Tùy biến thay thế theo call
        $url = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($parameters): string {
                $key = $matches[1];
                if (!array_key_exists($key, $parameters)) {
                    throw new RuntimeException("Thiếu tham số route: {$key}");
                }
                return rawurlencode((string) $parameters[$key]);
            },
            $path,
        );

        return (string) $url;
    }

    /** @return array<string, string>|null */
    private function matchPath(string $routePath, string $requestPath): ?array
    {
        $routeSegments = self::segments($routePath);
        $requestSegments = self::segments(self::normalizePath($requestPath));
        if (count($routeSegments) !== count($requestSegments)) {
            return null;
        }

        $parameters = [];
        foreach ($routeSegments as $index => $routeSegment) {
            $requestSegment = $requestSegments[$index];
            // matches 0 -- lấy giá trị cả dấy {} // matches 1 -- chỉ lấy giá trị bên trong {}
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $routeSegment, $matches) === 1) {
                $parameters[$matches[1]] = rawurldecode($requestSegment);
                continue;
            }
            if ($routeSegment !== $requestSegment) {
                return null;
            }
        }

        return $parameters;
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return $path === '/' ? [] : explode('/', trim($path, '/'));
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}

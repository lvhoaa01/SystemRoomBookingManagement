<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, string> $routeParameters
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public array $cookies = [],
        public array $files = [],
        public array $routeParameters = [],
        public string $clientIp = '0.0.0.0',
        public string $requestId = '',
    ) {
    }

    public static function fromGlobals(?string $rawBody = null): self
    {
        $headers = self::headersFromServer($_SERVER);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            $override = $headers['x-http-method-override'] ?? ($_POST['_method'] ?? null);
            if (is_string($override) && in_array(strtoupper($override), ['PATCH', 'PUT', 'DELETE'], true)) {
                $method = strtoupper($override);
            }
        }

        $path = self::pathFromServer($_SERVER);

        $body = $_POST;
        $contentType = strtolower(explode(';', $headers['content-type'] ?? '')[0]);
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $json = $rawBody ?? file_get_contents('php://input');
            $decoded = json_decode($json === false || $json === '' ? '{}' : $json, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('JSON_BODY_INVALID');
            }
            $body = $decoded;
        }

        $providedRequestId = $headers['x-request-id'] ?? '';
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,128}$/', $providedRequestId) === 1
            ? $providedRequestId
            : bin2hex(random_bytes(16));

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $headers,
            array_map('strval', $_COOKIE),
            $_FILES,
            [],
            self::detectClientIp($_SERVER),
            $requestId,
        );
    }

    public static function fallbackFromGlobals(): self
    {
        $headers = self::headersFromServer($_SERVER);
        $providedRequestId = $headers['x-request-id'] ?? '';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::pathFromServer($_SERVER),
            $_GET,
            [],
            $headers,
            array_map('strval', $_COOKIE),
            $_FILES,
            [],
            self::detectClientIp($_SERVER),
            preg_match('/^[A-Za-z0-9._-]{8,128}$/', $providedRequestId) === 1
                ? $providedRequestId
                : bin2hex(random_bytes(16)),
        );
    }

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->cookies,
            $this->files,
            $parameters,
            $this->clientIp,
            $this->requestId,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function route(string $name, ?string $default = null): ?string
    {
        return $this->routeParameters[$name] ?? $default;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $this->query[$name] ?? $default;
    }

    public function expectsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains(strtolower($this->header('accept', '') ?? ''), 'application/json');
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @param array<string, mixed> $server */
    private static function pathFromServer(array $server): string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = self::normalizePath((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $basePath = str_replace('\\', '/', dirname($scriptName));
        if (in_array($basePath, ['/', '.', '\\'], true)) {
            $basePath = '';
        }
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            return self::normalizePath(substr($path, strlen($basePath)));
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }
        return $headers;
    }

    /** @param array<string, mixed> $server */
    private static function detectClientIp(array $server): string
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}

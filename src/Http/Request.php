<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = str_replace('\\', '/', dirname($scriptName));

        if (in_array($basePath, ['/', '.', '\\'], true)) {
            $basePath = '';
        }

        if (
            $basePath !== ''
            && ($path === $basePath || str_starts_with($path, $basePath . '/'))
        ) {
            $path = substr($path, strlen($basePath));
        }

        $path = $path === '' ? '/' : $path;

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
        );
    }
}


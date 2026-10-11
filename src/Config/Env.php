<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Env
{
    public static function load(string $path, bool $overwrite = false): void
    {
        if (!is_file($path)) {
            return;
        }

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($values === false) {
            throw new RuntimeException('Không thể đọc tệp cấu hình môi trường.');
        }

        foreach ($values as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException('Tên biến môi trường không hợp lệ.');
            }

            if (!$overwrite && self::exists($key)) {
                continue;
            }

            $normalized = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $_ENV[$key] = $normalized;
            $_SERVER[$key] = $normalized;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? $default : (string) $value;
    }

    public static function require(string $key, bool $allowEmpty = false): string
    {
        if (!self::exists($key)) {
            throw new RuntimeException("Thiếu biến môi trường bắt buộc: {$key}");
        }

        $value = self::get($key, '');
        if (!$allowEmpty && trim((string) $value) === '') {
            throw new RuntimeException("Biến môi trường không được để trống: {$key}");
        }

        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException("Biến môi trường {$key} phải là true hoặc false.");
        }

        return $parsed;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }
}

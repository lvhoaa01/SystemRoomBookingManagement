<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Shared\Clock\ClockInterface;

final readonly class FileLogger implements Logger
{
    public function __construct(
        private string $path,
        private ClockInterface $clock,
    ) {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => $this->clock->now()->format('Y-m-d\TH:i:s.u\Z'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $this->sanitize($context),
        ];
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($this->path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(
            '/password|matkhau|token|secret|authorization|cookie|session|csrf|cvv|pan/i',
            $key,
        ) === 1) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $itemKey => $item) {
                $clean[$itemKey] = $this->sanitize($item, (string) $itemKey);
            }
            return $clean;
        }
        if (is_object($value)) {
            return '[OBJECT:' . $value::class . ']';
        }
        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace(
            '/([A-Z0-9._%+-])[A-Z0-9._%+-]*(@[A-Z0-9.-]+\.[A-Z]{2,})/i',
            '$1***$2',
            $value,
        ) ?? '[REDACTED]';

        return preg_replace('/(?<!\d)(?:\+?84|0)\d{8,10}(?!\d)/', '[PHONE_REDACTED]', $value)
            ?? '[REDACTED]';
    }
}

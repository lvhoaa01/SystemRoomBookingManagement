<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Shared\Clock\ClockInterface;
use InvalidArgumentException;

final readonly class LoginThrottle
{
    private const SESSION_KEY = '_login_throttle';

    public function __construct(
        private ClockInterface $clock,
        private int $maxAttempts = 5,
        private int $windowSeconds = 900,
    ) {
        if ($this->maxAttempts < 1 || $this->windowSeconds < 60) {
            throw new InvalidArgumentException('Cấu hình giới hạn đăng nhập không hợp lệ.');
        }
    }

    public function isBlocked(string $identifier, string $clientIp): bool
    {
        $attempt = $this->activeAttempt($this->key($identifier, $clientIp));
        return $attempt !== null && $attempt['count'] >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier, string $clientIp): void
    {
        $key = $this->key($identifier, $clientIp);
        $attempt = $this->activeAttempt($key);
        if ($attempt === null) {
            $_SESSION[self::SESSION_KEY][$key] = [
                'count' => 1,
                'startedAt' => $this->clock->now()->getTimestamp(),
            ];
            return;
        }

        $_SESSION[self::SESSION_KEY][$key]['count'] = $attempt['count'] + 1;
    }

    public function clear(string $identifier, string $clientIp): void
    {
        unset($_SESSION[self::SESSION_KEY][$this->key($identifier, $clientIp)]);
        if (($_SESSION[self::SESSION_KEY] ?? []) === []) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    public function retryAfterSeconds(string $identifier, string $clientIp): int
    {
        $attempt = $this->activeAttempt($this->key($identifier, $clientIp));
        if ($attempt === null || $attempt['count'] < $this->maxAttempts) {
            return 0;
        }

        return max(0, $this->windowSeconds - ($this->clock->now()->getTimestamp() - $attempt['startedAt']));
    }

    /** @return array{count: int, startedAt: int}|null */
    private function activeAttempt(string $key): ?array
    {
        $attempt = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (
            !is_array($attempt)
            || !is_int($attempt['count'] ?? null)
            || !is_int($attempt['startedAt'] ?? null)
            || $attempt['count'] < 1
        ) {
            unset($_SESSION[self::SESSION_KEY][$key]);
            return null;
        }

        if ($this->clock->now()->getTimestamp() - $attempt['startedAt'] >= $this->windowSeconds) {
            unset($_SESSION[self::SESSION_KEY][$key]);
            return null;
        }

        return $attempt;
    }

    private function key(string $identifier, string $clientIp): string
    {
        return hash('sha256', strtolower(trim($identifier)) . '|' . $clientIp);
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Clock\ClockInterface;
use InvalidArgumentException;

final readonly class SessionRateLimiter
{
    private const SESSION_KEY = '_rate_limit_buckets';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function consume(string $bucket, string $subject, int $limit, int $windowSeconds): int
    {
        if (preg_match('/^[a-z][a-z0-9._-]{2,63}$/', $bucket) !== 1
            || $subject === '' || $limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('Cấu hình rate limit không hợp lệ.');
        }

        $key = hash('sha256', $bucket . '|' . $subject);
        $now = $this->clock->now()->getTimestamp();
        $attempt = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!is_array($attempt) || !is_int($attempt['count'] ?? null)
            || !is_int($attempt['startedAt'] ?? null) || $now - $attempt['startedAt'] >= $windowSeconds) {
            $_SESSION[self::SESSION_KEY][$key] = ['count' => 1, 'startedAt' => $now];
            return 0;
        }
        if ($attempt['count'] >= $limit) {
            return max(1, $windowSeconds - ($now - $attempt['startedAt']));
        }

        $_SESSION[self::SESSION_KEY][$key]['count'] = $attempt['count'] + 1;
        return 0;
    }
}

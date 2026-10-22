<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Infrastructure\LoginThrottle;
use App\Shared\Clock\FrozenClock;
use DateInterval;
use DateTimeImmutable;
use Tests\Support\TestCase;

final class LoginThrottleTest extends TestCase
{
    public function test_blocks_after_maximum_failures_and_resets_after_window(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00'));
        $throttle = new LoginThrottle($clock, 3, 120);

        self::assertFalse($throttle->isBlocked('user@example.test', '127.0.0.1'));
        $throttle->recordFailure('user@example.test', '127.0.0.1');
        $throttle->recordFailure('user@example.test', '127.0.0.1');
        $throttle->recordFailure('user@example.test', '127.0.0.1');

        self::assertTrue($throttle->isBlocked('USER@example.test', '127.0.0.1'));
        self::assertSame(120, $throttle->retryAfterSeconds('user@example.test', '127.0.0.1'));

        $clock->advance(new DateInterval('PT120S'));
        self::assertFalse($throttle->isBlocked('user@example.test', '127.0.0.1'));
        self::assertSame(0, $throttle->retryAfterSeconds('user@example.test', '127.0.0.1'));
    }

    public function test_success_can_clear_only_the_matching_identifier_and_ip_bucket(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-22T10:00:00+00:00'));
        $throttle = new LoginThrottle($clock, 1, 120);
        $throttle->recordFailure('first@example.test', '127.0.0.1');
        $throttle->recordFailure('second@example.test', '127.0.0.1');

        $throttle->clear('first@example.test', '127.0.0.1');

        self::assertFalse($throttle->isBlocked('first@example.test', '127.0.0.1'));
        self::assertTrue($throttle->isBlocked('second@example.test', '127.0.0.1'));
    }
}

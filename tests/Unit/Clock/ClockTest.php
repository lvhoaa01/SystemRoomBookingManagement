<?php

declare(strict_types=1);

namespace Tests\Unit\Clock;

use App\Shared\Clock\BusinessTime;
use App\Shared\Clock\FrozenClock;
use App\Shared\Clock\SystemClock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class ClockTest extends TestCase
{
    public function test_system_clock_returns_utc_timestamp(): void
    {
        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertLessThanOrEqual(2, abs(time() - $now->getTimestamp()));
    }

    public function test_frozen_clock_normalizes_to_utc_and_can_advance(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-20 14:00:00', new DateTimeZone('Asia/Ho_Chi_Minh')));

        self::assertSame('2026-10-20 07:00:00 UTC', $clock->now()->format('Y-m-d H:i:s T'));

        $clock->advance(new DateInterval('PT90M'));

        self::assertSame('2026-10-20 08:30:00 UTC', $clock->now()->format('Y-m-d H:i:s T'));
    }

    public function test_business_time_converts_technical_timestamp_to_property_timezone(): void
    {
        $utc = new DateTimeImmutable('2026-10-20 07:00:00', new DateTimeZone('UTC'));

        $local = BusinessTime::inTimezone($utc, 'Asia/Ho_Chi_Minh');

        self::assertSame('2026-10-20 14:00:00', $local->format('Y-m-d H:i:s'));
    }

    public function test_business_time_rejects_invalid_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessTime::inTimezone(new DateTimeImmutable('now'), 'Invalid/Timezone');
    }
}

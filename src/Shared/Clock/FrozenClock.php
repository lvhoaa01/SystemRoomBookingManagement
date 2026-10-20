<?php

declare(strict_types=1);

namespace App\Shared\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $currentTime;

    public function __construct(DateTimeImmutable $currentTime)
    {
        $this->freeze($currentTime);
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function freeze(DateTimeImmutable $currentTime): void
    {
        $this->currentTime = $currentTime->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(DateInterval $interval): void
    {
        $this->currentTime = $this->currentTime->add($interval);
    }
}

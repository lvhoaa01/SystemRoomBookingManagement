<?php

declare(strict_types=1);

namespace App\Shared\Clock;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}

<?php

declare(strict_types=1);

namespace App\Shared\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BusinessTime
{
    public static function inTimezone(DateTimeImmutable $timestamp, string $timezone): DateTimeImmutable
    {
        try {
            return $timestamp->setTimezone(new DateTimeZone($timezone));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Múi giờ nghiệp vụ không hợp lệ.', previous: $exception);
        }
    }
}

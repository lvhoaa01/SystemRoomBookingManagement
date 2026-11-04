<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RevenueSettlementRequest
{
    public function __construct(public int $propertyId, public string $from, public string $to)
    {
        if ($propertyId < 1) {
            throw new InvalidArgumentException('Cơ sở đối soát không hợp lệ.');
        }
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (!$fromDate || $fromDate->format('Y-m-d') !== $from || !$toDate || $toDate->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Khoảng ngày đối soát không hợp lệ.');
        }
        if ($fromDate > $toDate || $fromDate->diff($toDate)->days > 366) {
            throw new InvalidArgumentException('Kỳ đối soát phải từ 1 đến 367 ngày.');
        }
    }
}

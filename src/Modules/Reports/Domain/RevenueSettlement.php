<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class RevenueSettlement
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $propertyId,
        public string $propertyName,
        public string $from,
        public string $to,
        public string $status,
        public int $bookingCount,
        public Money $grossRevenue,
        public Money $onlineCollected,
        public Money $atPropertyCollected,
        public Money $refunded,
        public Money $netRevenue,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finalizedAt,
        public array $lines,
    ) {
    }
}

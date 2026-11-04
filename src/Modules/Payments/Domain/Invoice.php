<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class Invoice
{
    /** @param list<array<string, mixed>> $lines @param array<string, mixed> $snapshot */
    public function __construct(
        public int $id,
        public string $number,
        public int $bookingId,
        public string $bookingCode,
        public int $customerId,
        public int $organizationId,
        public int $propertyId,
        public string $propertyName,
        public Money $total,
        public Money $paid,
        public DateTimeImmutable $issuedAt,
        public array $lines,
        public array $snapshot,
    ) {
    }
}

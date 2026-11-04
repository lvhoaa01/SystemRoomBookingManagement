<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class CollectedPayment
{
    public function __construct(
        public int $transactionId,
        public int $bookingId,
        public Money $amount,
        public string $method,
        public ?string $reference,
        public DateTimeImmutable $collectedAt,
        public bool $replayed,
    ) {
    }
}

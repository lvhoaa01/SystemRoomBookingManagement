<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Domain;

final readonly class ProductPricingSnapshot
{
    /**
     * @param list<array<string, mixed>> $rates
     * @param list<array{id: int, discountRate: string}> $promotions
     * @param array<string, mixed> $cancellationSnapshot
     */
    public function __construct(
        public string $paymentTiming,
        public string $timezone,
        public string $taxRate,
        public bool $taxIncluded,
        public string $serviceFeeRate,
        public bool $serviceFeeIncluded,
        public array $rates,
        public array $promotions,
        public array $cancellationSnapshot,
    ) {
    }
}

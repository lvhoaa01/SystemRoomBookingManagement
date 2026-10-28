<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use App\Shared\Contract\PriceBreakdown;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;

interface PriceCalculator
{
    public function calculate(
        ProductPricingSnapshot $product,
        StayPeriod $stay,
        RoomParty $party,
        DateTimeImmutable $bookedAt,
    ): PriceBreakdown;
}

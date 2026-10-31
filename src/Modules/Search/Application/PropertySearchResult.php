<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\AllocationResult;
use App\Shared\Money\Money;

final readonly class PropertySearchResult
{
    /** @param list<AllocationResult> $allocationOptions */
    public function __construct(
        public PropertySearchSnapshot $property,
        public AllocationResult $representative,
        public array $allocationOptions,
        public int $minimumAvailable,
        public Money $taxFeeTotal,
        public string $cancellationLabel,
    ) {
    }
}

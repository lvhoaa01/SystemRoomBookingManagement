<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\SearchCriteria;

final readonly class PropertyAvailabilityResult
{
    /**
     * @param array<string, array{available: bool, minPrice: ?string}> $timingSummary
     * @param array<int, array{roomTypeId: int, productId: int}> $selected
     */
    public function __construct(
        public PropertySearchSnapshot $property,
        public SearchCriteria $criteria,
        public string $paymentTiming,
        public ?AllocationResult $allocation,
        public array $timingSummary,
        public array $selected,
        public bool $selectionChanged,
        public ?string $changeReason,
    ) {
    }

    public function available(): bool
    {
        return $this->allocation !== null;
    }
}

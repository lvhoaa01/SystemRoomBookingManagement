<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Commercial\Application\PriceCalculator;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Json\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PropertyAllocationService
{
    public function __construct(
        private PriceCalculator $priceCalculator,
        private RoomAllocator $roomAllocator,
    ) {
    }

    /** @return list<AllocationResult> */
    public function allocate(
        PropertySearchSnapshot $property,
        SearchCriteria $criteria,
        DateTimeImmutable $bookedAt,
        ?string $paymentTiming = null,
    ): array {
        $criteriaFingerprint = CanonicalJson::hash($criteria);
        $candidates = [];
        foreach ($property->roomProducts as $product) {
            if ($paymentTiming !== null && $product->paymentTiming !== $paymentTiming) {
                continue;
            }
            $prices = [];
            foreach ($criteria->parties as $party) {
                if ($party->guests() > $product->maximumGuests
                    || $party->adults > $product->maximumAdults
                    || $party->children() > $product->maximumChildren) {
                    continue;
                }
                try {
                    $prices[$party->roomOrder] = $this->priceCalculator->calculate(
                        $product->pricing,
                        $criteria->stayPeriod,
                        $party,
                        $bookedAt,
                    );
                } catch (InvalidArgumentException) {
                    // Restriction, lịch giá hoặc promotion không hợp lệ làm sản phẩm này không bán được cho nhóm đó.
                }
            }
            if ($prices === []) {
                continue;
            }
            $candidates[] = new AllocationCandidate(
                $product->roomTypeId,
                $product->productId,
                $product->roomTypeName,
                $product->productName,
                $product->mealType,
                $product->paymentTiming,
                $product->maximumGuests,
                $product->maximumAdults,
                $product->maximumChildren,
                $prices,
                $product->inventoryVersions,
                $criteriaFingerprint,
            );
        }

        return $this->roomAllocator->allocate(
            $criteria->parties,
            $candidates,
            $property->availability(),
            $paymentTiming,
        );
    }
}

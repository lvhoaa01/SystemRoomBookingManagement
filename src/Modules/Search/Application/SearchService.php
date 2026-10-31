<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Money\Money;

final readonly class SearchService
{
    private const PAGE_SIZE = 12;

    public function __construct(
        private SearchCatalogRepository $catalog,
        private PropertyAllocationService $allocationService,
        private ClockInterface $clock,
    ) {
    }

    public function search(SearchCriteria $criteria): SearchPage
    {
        $results = [];
        $paymentTiming = $criteria->filters['paymentTiming'] ?? null;
        foreach ($this->catalog->findCandidates($criteria, $this->clock->now()) as $property) {
            $allocations = $this->allocationService->allocate(
                $property,
                $criteria,
                $this->clock->now(),
                is_string($paymentTiming) ? $paymentTiming : null,
            );
            if ($allocations === []) {
                continue;
            }
            $representative = $allocations[0];
            if (!$this->passesPriceRange($representative, $criteria)) {
                continue;
            }
            $results[] = new PropertySearchResult(
                $property,
                $representative,
                $allocations,
                $this->minimumAvailable($property, $representative),
                $this->taxFeeTotal($representative),
                $this->cancellationLabel($representative),
            );
        }

        $this->sort($results, $criteria->sort);
        $total = count($results);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / self::PAGE_SIZE);
        $offset = ($criteria->page - 1) * self::PAGE_SIZE;

        return new SearchPage(
            $criteria,
            array_slice($results, $offset, self::PAGE_SIZE),
            $total,
            $criteria->page,
            self::PAGE_SIZE,
            $totalPages,
        );
    }

    private function passesPriceRange(AllocationResult $allocation, SearchCriteria $criteria): bool
    {
        $amount = $allocation->grandTotal->amount();
        return (!isset($criteria->filters['minPrice']) || $amount >= $criteria->filters['minPrice'])
            && (!isset($criteria->filters['maxPrice']) || $amount <= $criteria->filters['maxPrice']);
    }

    private function minimumAvailable(PropertySearchSnapshot $property, AllocationResult $allocation): int
    {
        $availability = $property->availability();
        $minimum = PHP_INT_MAX;
        foreach ($allocation->rooms as $room) {
            $minimum = min($minimum, $availability[$room->roomTypeId] ?? 0);
        }
        return $minimum === PHP_INT_MAX ? 0 : $minimum;
    }

    private function taxFeeTotal(AllocationResult $allocation): Money
    {
        $total = Money::vnd(0);
        foreach ($allocation->rooms as $room) {
            foreach ($room->priceBreakdown->nights as $night) {
                $total = $total
                    ->add(Money::vnd((string) ($night['taxAmount'] ?? '0')))
                    ->add(Money::vnd((string) ($night['serviceFeeAmount'] ?? '0')));
            }
        }
        return $total;
    }

    private function cancellationLabel(AllocationResult $allocation): string
    {
        $freeHours = [];
        foreach ($allocation->rooms as $room) {
            $snapshot = $room->priceBreakdown->cancellationSnapshot;
            if (($snapshot['policyType'] ?? '') !== 'FLEXIBLE' || !is_int($snapshot['freeCancellationHours'] ?? null)) {
                return 'Không hoàn tiền';
            }
            $freeHours[] = $snapshot['freeCancellationHours'];
        }
        return $freeHours === [] ? 'Theo chính sách cơ sở' : 'Hủy miễn phí trước ' . min($freeHours) . ' giờ';
    }

    /** @param list<PropertySearchResult> $results */
    private function sort(array &$results, string $sort): void
    {
        usort($results, static function (PropertySearchResult $left, PropertySearchResult $right) use ($sort): int {
            $order = match ($sort) {
                'PRICE_ASC' => $left->representative->grandTotal->compareTo($right->representative->grandTotal),
                'PRICE_DESC' => $right->representative->grandTotal->compareTo($left->representative->grandTotal),
                'REVIEW_SCORE' => (float) $right->property->reviewScore <=> (float) $left->property->reviewScore,
                'STAR_RATING' => (float) ($right->property->starRating ?? 0) <=> (float) ($left->property->starRating ?? 0),
                default => ((float) $right->property->reviewScore <=> (float) $left->property->reviewScore)
                    ?: ($right->property->reviewCount <=> $left->property->reviewCount)
                    ?: $left->representative->grandTotal->compareTo($right->representative->grandTotal),
            };
            return $order !== 0 ? $order : $left->property->propertyId <=> $right->property->propertyId;
        });
    }
}

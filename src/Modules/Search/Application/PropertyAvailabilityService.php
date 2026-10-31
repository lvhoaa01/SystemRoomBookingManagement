<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\SearchCriteria;
use InvalidArgumentException;

final readonly class PropertyAvailabilityService
{
    public function __construct(
        private SearchCatalogRepository $catalog,
        private PropertyAllocationService $allocationService,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<int, array{roomTypeId: int, productId: int}> $selected
     */
    public function check(
        string $slug,
        SearchCriteria $criteria,
        ?string $paymentTiming = null,
        ?string $previousFingerprint = null,
        array $selected = [],
    ): ?PropertyAvailabilityResult {
        if ($paymentTiming !== null && !in_array($paymentTiming, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)) {
            throw new InvalidArgumentException('Thời điểm thanh toán không hợp lệ.');
        }
        if ($previousFingerprint !== null && preg_match('/^[a-f0-9]{64}$/', $previousFingerprint) !== 1) {
            throw new InvalidArgumentException('Selection fingerprint không hợp lệ.');
        }
        $selected = $this->normalizeSelected($selected);
        $catalogCriteria = $this->withoutPaymentTiming($criteria);
        $now = $this->clock->now();
        $property = $this->catalog->findBySlug($slug, $catalogCriteria, $now);
        if ($property === null) {
            return null;
        }

        $all = $this->allocationService->allocate($property, $criteria, $now);
        $summary = $this->timingSummary($all);
        $defaultTiming = $all[0]->paymentTiming ?? 'PAY_AT_PROPERTY';
        $timing = $paymentTiming ?? ($criteria->filters['paymentTiming'] ?? $defaultTiming);
        if (!is_string($timing) || !in_array($timing, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)) {
            $timing = $defaultTiming;
        }
        $current = $this->allocationService->allocate($property, $criteria, $now, $timing)[0] ?? null;

        // Trên detail, paymentTiming vừa mô tả nhánh đang xem vừa có thể là filter gốc từ search.
        // Thử biến thể còn lại để giữ continuity nếu fingerprint của card chứng minh đó là tiêu chí gốc.
        if ($previousFingerprint !== null
            && ($current === null || $current->selectionFingerprint !== $previousFingerprint)) {
            $alternateCriteria = $this->togglePaymentTimingFilter($criteria, $timing);
            $alternate = $this->allocationService->allocate($property, $alternateCriteria, $now, $timing)[0] ?? null;
            if ($alternate !== null && $alternate->selectionFingerprint === $previousFingerprint) {
                $current = $alternate;
            }
        }

        $fingerprintChanged = $previousFingerprint !== null
            && ($current === null || $current->selectionFingerprint !== $previousFingerprint);
        $mappingChanged = $selected !== []
            && ($current === null || $this->allocationMapping($current) !== $selected);
        $selectionChanged = $fingerprintChanged || $mappingChanged;
        $reason = null;
        if ($selectionChanged) {
            $reason = $current === null
                ? 'Lựa chọn trước đó không còn đủ giá hoặc tồn cho toàn bộ kỳ lưu trú.'
                : 'Giá, tồn phòng hoặc sản phẩm đã thay đổi; vui lòng xác nhận lựa chọn hiện tại.';
        }

        return new PropertyAvailabilityResult(
            $property,
            $criteria,
            $timing,
            $current,
            $summary,
            $selected,
            $selectionChanged,
            $reason,
        );
    }

    private function withoutPaymentTiming(SearchCriteria $criteria): SearchCriteria
    {
        $filters = $criteria->filters;
        unset($filters['paymentTiming']);
        return new SearchCriteria(
            $criteria->destination,
            $criteria->stayPeriod,
            $criteria->parties,
            $filters,
            $criteria->sort,
            $criteria->page,
        );
    }

    private function togglePaymentTimingFilter(SearchCriteria $criteria, string $timing): SearchCriteria
    {
        $filters = $criteria->filters;
        if (isset($filters['paymentTiming'])) {
            unset($filters['paymentTiming']);
        } else {
            $filters['paymentTiming'] = $timing;
        }
        return new SearchCriteria(
            $criteria->destination,
            $criteria->stayPeriod,
            $criteria->parties,
            $filters,
            $criteria->sort,
            $criteria->page,
        );
    }

    /** @param list<AllocationResult> $allocations @return array<string, array{available: bool, minPrice: ?string}> */
    private function timingSummary(array $allocations): array
    {
        $summary = [
            'PAY_AT_PROPERTY' => ['available' => false, 'minPrice' => null],
            'PAY_ONLINE' => ['available' => false, 'minPrice' => null],
        ];
        foreach ($allocations as $allocation) {
            $summary[$allocation->paymentTiming] = [
                'available' => true,
                'minPrice' => $allocation->grandTotal->decimal(),
            ];
        }
        return $summary;
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function allocationMapping(AllocationResult $allocation): array
    {
        $mapping = [];
        foreach ($allocation->rooms as $room) {
            $mapping[$room->roomOrder] = [
                'roomTypeId' => $room->roomTypeId,
                'productId' => $room->productId,
            ];
        }
        ksort($mapping, SORT_NUMERIC);
        return $mapping;
    }

    /**
     * @param array<int, array{roomTypeId: int, productId: int}> $selected
     * @return array<int, array{roomTypeId: int, productId: int}>
     */
    private function normalizeSelected(array $selected): array
    {
        $normalized = [];
        foreach ($selected as $roomOrder => $choice) {
            $order = filter_var($roomOrder, FILTER_VALIDATE_INT);
            $roomTypeId = filter_var($choice['roomTypeId'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($choice['productId'] ?? null, FILTER_VALIDATE_INT);
            if ($order === false || $order < 1 || $order > 5
                || $roomTypeId === false || $roomTypeId < 1
                || $productId === false || $productId < 1) {
                throw new InvalidArgumentException('Ánh xạ phòng/sản phẩm đã chọn không hợp lệ.');
            }
            $normalized[(int) $order] = [
                'roomTypeId' => (int) $roomTypeId,
                'productId' => (int) $productId,
            ];
        }
        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }
}

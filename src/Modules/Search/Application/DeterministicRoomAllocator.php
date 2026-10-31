<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\RoomAllocation;
use App\Shared\Contract\RoomParty;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use InvalidArgumentException;

final readonly class DeterministicRoomAllocator implements RoomAllocator
{
    private const TIMINGS = ['PAY_AT_PROPERTY', 'PAY_ONLINE'];

    public function allocate(
        array $parties,
        array $candidates,
        array $availability,
        ?string $paymentTiming = null,
    ): array {
        $parties = $this->normalizeParties($parties);
        $candidates = $this->normalizeCandidates($candidates);
        $availability = $this->normalizeAvailability($availability);

        if ($paymentTiming !== null && !in_array($paymentTiming, self::TIMINGS, true)) {
            throw new InvalidArgumentException('Bộ lọc thời điểm thanh toán không hợp lệ.');
        }
        $timings = $paymentTiming === null ? self::TIMINGS : [$paymentTiming];
        $results = [];
        foreach ($timings as $timing) {
            $partition = array_values(array_filter(
                $candidates,
                static fn (AllocationCandidate $candidate): bool => $candidate->paymentTiming === $timing,
            ));
            $result = $this->bestForPartition($parties, $partition, $availability, $timing);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        usort($results, function (AllocationResult $left, AllocationResult $right): int {
            $priceOrder = $left->grandTotal->compareTo($right->grandTotal);
            if ($priceOrder !== 0) {
                return $priceOrder;
            }
            $timingOrder = $this->timingRank($left->paymentTiming) <=> $this->timingRank($right->paymentTiming);
            return $timingOrder !== 0 ? $timingOrder : $this->allocationTuple($left) <=> $this->allocationTuple($right);
        });

        return $results;
    }

    /**
     * @param list<RoomParty> $parties
     * @param list<AllocationCandidate> $candidates
     * @param array<int, int> $availability
     */
    private function bestForPartition(
        array $parties,
        array $candidates,
        array $availability,
        string $timing,
    ): ?AllocationResult {
        if ($candidates === []) {
            return null;
        }

        /** @var list<array{candidate: AllocationCandidate, allocation: RoomAllocation}>|null $best */
        $best = null;
        $bestAmount = null;
        $bestTuple = null;

        $walk = function (
            int $partyIndex,
            array $remaining,
            array $selected,
            int $totalAmount,
        ) use (&$walk, &$best, &$bestAmount, &$bestTuple, $parties, $candidates): void {
            if ($bestAmount !== null && $totalAmount > $bestAmount) {
                return;
            }
            if ($partyIndex === count($parties)) {
                $tuple = $this->selectedTuple($selected);
                if ($bestAmount === null || $totalAmount < $bestAmount || ($totalAmount === $bestAmount && $tuple < $bestTuple)) {
                    $best = $selected;
                    $bestAmount = $totalAmount;
                    $bestTuple = $tuple;
                }
                return;
            }

            $party = $parties[$partyIndex];
            $eligible = array_values(array_filter(
                $candidates,
                static fn (AllocationCandidate $candidate): bool => $candidate->canHost($party),
            ));
            usort($eligible, static function (AllocationCandidate $left, AllocationCandidate $right) use ($party): int {
                $priceOrder = $left->priceFor($party)->grandTotal->compareTo($right->priceFor($party)->grandTotal);
                return $priceOrder !== 0
                    ? $priceOrder
                    : [$left->roomTypeId, $left->productId] <=> [$right->roomTypeId, $right->productId];
            });

            foreach ($eligible as $candidate) {
                if (($remaining[$candidate->roomTypeId] ?? 0) < 1) {
                    continue;
                }
                $price = $candidate->priceFor($party);
                $nextRemaining = $remaining;
                --$nextRemaining[$candidate->roomTypeId];
                $allocation = new RoomAllocation(
                    $party->roomOrder,
                    $candidate->roomTypeId,
                    $candidate->productId,
                    $candidate->paymentTiming,
                    $party,
                    $price,
                );
                $walk(
                    $partyIndex + 1,
                    $nextRemaining,
                    [...$selected, ['candidate' => $candidate, 'allocation' => $allocation]],
                    $totalAmount + $price->grandTotal->amount(),
                );
            }
        };

        $walk(0, $availability, [], 0);
        if ($best === null || $bestAmount === null) {
            return null;
        }

        $rooms = array_map(static fn (array $item): RoomAllocation => $item['allocation'], $best);
        $fingerprintPayload = [
            'criteria' => $best[0]['candidate']->criteriaFingerprint,
            'paymentTiming' => $timing,
            'availability' => $availability,
            'rooms' => array_map(static fn (array $item): array => [
                'allocation' => $item['allocation'],
                'inventoryVersions' => $item['candidate']->inventoryVersions,
            ], $best),
        ];

        return new AllocationResult(
            $timing,
            $rooms,
            Money::vnd($bestAmount),
            CanonicalJson::hash($fingerprintPayload),
        );
    }

    /** @param list<mixed> $parties @return list<RoomParty> */
    private function normalizeParties(array $parties): array
    {
        if ($parties === [] || !array_is_list($parties) || count($parties) > 5) {
            throw new InvalidArgumentException('Allocator cần từ 1 đến 5 nhóm phòng.');
        }
        foreach ($parties as $party) {
            if (!$party instanceof RoomParty) {
                throw new InvalidArgumentException('Mỗi nhóm phân bổ phải là RoomParty.');
            }
        }
        usort($parties, static fn (RoomParty $left, RoomParty $right): int => $left->roomOrder <=> $right->roomOrder);
        return $parties;
    }

    /** @param list<mixed> $candidates @return list<AllocationCandidate> */
    private function normalizeCandidates(array $candidates): array
    {
        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('Danh sách ứng viên phải là list.');
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof AllocationCandidate) {
                throw new InvalidArgumentException('Mỗi ứng viên phải là AllocationCandidate.');
            }
        }
        return $candidates;
    }

    /** @param array<mixed, mixed> $availability @return array<int, int> */
    private function normalizeAvailability(array $availability): array
    {
        $normalized = [];
        foreach ($availability as $roomTypeId => $quantity) {
            $id = filter_var($roomTypeId, FILTER_VALIDATE_INT);
            $count = filter_var($quantity, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || $count === false || $count < 0) {
                throw new InvalidArgumentException('Snapshot tồn phòng không hợp lệ.');
            }
            $normalized[(int) $id] = (int) $count;
        }
        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /** @param list<array{candidate: AllocationCandidate, allocation: RoomAllocation}> $selected */
    private function selectedTuple(array $selected): string
    {
        return implode('|', array_map(
            static fn (array $item): string => sprintf('%010d:%010d', $item['candidate']->roomTypeId, $item['candidate']->productId),
            $selected,
        ));
    }

    private function allocationTuple(AllocationResult $result): string
    {
        return implode('|', array_map(
            static fn (RoomAllocation $room): string => sprintf('%010d:%010d', $room->roomTypeId, $room->productId),
            $result->rooms,
        ));
    }

    private function timingRank(string $timing): int
    {
        return $timing === 'PAY_AT_PROPERTY' ? 0 : 1;
    }
}

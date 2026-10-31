<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Search;

use App\Modules\Search\Application\AllocationCandidate;
use App\Modules\Search\Application\DeterministicRoomAllocator;
use App\Shared\Contract\PriceBreakdown;
use App\Shared\Contract\RoomParty;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class DeterministicRoomAllocatorTest extends TestCase
{
    public function test_splits_timings_and_prefers_pay_at_property_when_totals_tie(): void
    {
        $party = new RoomParty(1, 2);
        $allocator = new DeterministicRoomAllocator();

        $results = $allocator->allocate(
            [$party],
            [
                $this->candidate(2, 20, 'PAY_ONLINE', [$party], 1_000),
                $this->candidate(3, 30, 'PAY_AT_PROPERTY', [$party], 1_000),
            ],
            [2 => 1, 3 => 1],
        );

        self::assertCount(2, $results);
        self::assertSame('PAY_AT_PROPERTY', $results[0]->paymentTiming);
        self::assertSame('PAY_ONLINE', $results[1]->paymentTiming);
        self::assertNotSame($results[0]->selectionFingerprint, $results[1]->selectionFingerprint);
    }

    public function test_decrements_shared_room_type_inventory_during_backtracking(): void
    {
        $parties = [new RoomParty(1, 2), new RoomParty(2, 2)];
        $allocator = new DeterministicRoomAllocator();
        $candidates = [
            $this->candidate(10, 100, 'PAY_AT_PROPERTY', $parties, 800),
            $this->candidate(20, 200, 'PAY_AT_PROPERTY', $parties, 1_000),
        ];

        $result = $allocator->allocate($parties, $candidates, [10 => 1, 20 => 1], 'PAY_AT_PROPERTY')[0];

        self::assertSame([10, 20], array_map(static fn ($room): int => $room->roomTypeId, $result->rooms));
        self::assertSame(1_800, $result->grandTotal->amount());
    }

    public function test_does_not_drop_or_move_guests_when_capacity_is_insufficient(): void
    {
        $party = new RoomParty(1, 2, [5, 8]);
        $candidate = $this->candidate(10, 100, 'PAY_AT_PROPERTY', [$party], 800, maximumGuests: 3, maximumChildren: 1);

        $results = (new DeterministicRoomAllocator())->allocate([$party], [$candidate], [10 => 5]);

        self::assertSame([], $results);
    }

    public function test_same_snapshot_is_deterministic_and_ties_use_room_product_tuple(): void
    {
        $party = new RoomParty(1, 1);
        $allocator = new DeterministicRoomAllocator();
        $candidates = [
            $this->candidate(8, 90, 'PAY_AT_PROPERTY', [$party], 500),
            $this->candidate(7, 99, 'PAY_AT_PROPERTY', [$party], 500),
            $this->candidate(7, 80, 'PAY_AT_PROPERTY', [$party], 500),
        ];

        $first = $allocator->allocate([$party], $candidates, [7 => 1, 8 => 1]);
        $second = $allocator->allocate([$party], array_reverse($candidates), [8 => 1, 7 => 1]);

        self::assertSame(7, $first[0]->rooms[0]->roomTypeId);
        self::assertSame(80, $first[0]->rooms[0]->productId);
        self::assertSame($first[0]->selectionFingerprint, $second[0]->selectionFingerprint);
    }

    public function test_timing_filter_never_returns_the_other_partition(): void
    {
        $party = new RoomParty(1, 2);
        $results = (new DeterministicRoomAllocator())->allocate(
            [$party],
            [
                $this->candidate(1, 10, 'PAY_AT_PROPERTY', [$party], 1_000),
                $this->candidate(2, 20, 'PAY_ONLINE', [$party], 900),
            ],
            [1 => 1, 2 => 1],
            'PAY_ONLINE',
        );

        self::assertCount(1, $results);
        self::assertSame('PAY_ONLINE', $results[0]->paymentTiming);
    }

    /** @param list<RoomParty> $parties */
    private function candidate(
        int $roomTypeId,
        int $productId,
        string $timing,
        array $parties,
        int $amount,
        int $maximumGuests = 4,
        int $maximumChildren = 2,
    ): AllocationCandidate {
        $prices = [];
        foreach ($parties as $party) {
            $prices[$party->roomOrder] = $this->breakdown($timing, $amount);
        }
        return new AllocationCandidate(
            $roomTypeId,
            $productId,
            'Phòng ' . $roomTypeId,
            'Sản phẩm ' . $productId,
            'ROOM_ONLY',
            $timing,
            $maximumGuests,
            3,
            $maximumChildren,
            $prices,
            ['2026-12-10' => 1],
            str_repeat('a', 64),
        );
    }

    private function breakdown(string $timing, int $amount): PriceBreakdown
    {
        return new PriceBreakdown(
            $timing,
            [['stayDate' => '2026-12-10', 'total' => $amount . '.00']],
            Money::vnd($amount),
            Money::vnd(0),
            Money::vnd(0),
            Money::vnd($timing === 'PAY_ONLINE' ? $amount : 0),
            Money::vnd($timing === 'PAY_AT_PROPERTY' ? $amount : 0),
            Money::vnd($amount),
            null,
            [],
            [],
            [1],
        );
    }
}

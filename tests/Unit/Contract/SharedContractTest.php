<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\InventoryDemand;
use App\Shared\Contract\PriceBreakdown;
use App\Shared\Contract\RoomAllocation;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class SharedContractTest extends TestCase
{
    public function test_stay_period_is_half_open_and_lists_each_night(): void
    {
        $stay = new StayPeriod('2026-10-20', '2026-10-23');

        self::assertSame(3, $stay->nightCount());
        self::assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], $stay->nights());
        self::assertTrue($stay->containsNight('2026-10-22'));
        self::assertFalse($stay->containsNight('2026-10-23'));
    }

    public function test_stay_period_rejects_invalid_order_and_more_than_thirty_nights(): void
    {
        try {
            new StayPeriod('2026-10-20', '2026-10-20');
            self::fail('Expected invalid date order.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new StayPeriod('2026-10-01', '2026-11-01');
    }

    public function test_stay_period_rejects_impossible_calendar_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StayPeriod('2026-02-30', '2026-03-02');
    }

    public function test_room_party_counts_guests_and_validates_adults_and_child_ages(): void
    {
        $party = new RoomParty(1, 2, [0, 17]);

        self::assertSame(2, $party->children());
        self::assertSame(4, $party->guests());

        try {
            new RoomParty(1, 0);
            self::fail('Expected at least one adult.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new RoomParty(1, 1, [18]);
    }

    public function test_search_criteria_normalizes_text_and_requires_unique_room_orders(): void
    {
        $stay = new StayPeriod('2026-10-20', '2026-10-22');
        $criteria = new SearchCriteria(
            '  Thành phố Hồ Chí Minh ',
            $stay,
            [new RoomParty(1, 2), new RoomParty(2, 1, [8])],
            ['stars' => [4, 5]],
            ' PRICE_ASC ',
        );

        self::assertSame('Thành phố Hồ Chí Minh', $criteria->destination);
        self::assertSame('PRICE_ASC', $criteria->sort);
        self::assertSame(CanonicalJson::hash($criteria), CanonicalJson::hash($criteria->jsonSerialize()));

        $this->expectException(InvalidArgumentException::class);
        new SearchCriteria('HCM', $stay, [new RoomParty(1, 2), new RoomParty(1, 1)]);
    }

    public function test_inventory_demand_requires_valid_date_and_positive_quantity(): void
    {
        $demand = new InventoryDemand(10, '2026-10-20', 2);

        self::assertSame(10, $demand->roomTypeId);
        self::assertSame(2, $demand->quantity);

        $this->expectException(InvalidArgumentException::class);
        new InventoryDemand(10, '2026-10-20', 0);
    }

    public function test_price_breakdown_enforces_all_or_nothing_payment_split(): void
    {
        $online = $this->breakdown('PAY_ONLINE', 1000);

        self::assertSame(1000, $online->payNowTotal->amount());
        self::assertTrue($online->payAtPropertyTotal->isZero());

        $this->expectException(InvalidArgumentException::class);
        new PriceBreakdown(
            'PAY_ONLINE',
            [['date' => '2026-10-20', 'total' => '1000.00']],
            Money::vnd(1000),
            Money::vnd(0),
            Money::vnd(0),
            Money::vnd(500),
            Money::vnd(500),
            Money::vnd(1000),
            null,
            [],
            [],
            [1],
        );
    }

    public function test_room_allocation_requires_matching_party_and_payment_timing(): void
    {
        $party = new RoomParty(1, 2);
        $allocation = new RoomAllocation(1, 20, 30, 'PAY_ONLINE', $party, $this->breakdown('PAY_ONLINE', 1000));

        self::assertSame(20, $allocation->roomTypeId);

        $this->expectException(InvalidArgumentException::class);
        new RoomAllocation(2, 20, 30, 'PAY_ONLINE', $party, $this->breakdown('PAY_ONLINE', 1000));
    }

    public function test_allocation_result_checks_timing_total_and_fingerprint(): void
    {
        $roomOne = new RoomAllocation(
            1,
            20,
            30,
            'PAY_AT_PROPERTY',
            new RoomParty(1, 2),
            $this->breakdown('PAY_AT_PROPERTY', 1000),
        );
        $roomTwo = new RoomAllocation(
            2,
            21,
            31,
            'PAY_AT_PROPERTY',
            new RoomParty(2, 1, [7]),
            $this->breakdown('PAY_AT_PROPERTY', 1500),
        );
        $fingerprint = CanonicalJson::hash([$roomOne, $roomTwo]);

        $result = new AllocationResult('PAY_AT_PROPERTY', [$roomOne, $roomTwo], Money::vnd(2500), $fingerprint);

        self::assertSame(2500, $result->grandTotal->amount());
        self::assertSame($fingerprint, $result->selectionFingerprint);

        $this->expectException(InvalidArgumentException::class);
        new AllocationResult('PAY_AT_PROPERTY', [$roomOne, $roomTwo], Money::vnd(2499), $fingerprint);
    }

    private function breakdown(string $paymentTiming, int $total): PriceBreakdown
    {
        return new PriceBreakdown(
            $paymentTiming,
            [['date' => '2026-10-20', 'total' => $total . '.00']],
            Money::vnd($total),
            Money::vnd(0),
            Money::vnd(0),
            Money::vnd($paymentTiming === 'PAY_ONLINE' ? $total : 0),
            Money::vnd($paymentTiming === 'PAY_AT_PROPERTY' ? $total : 0),
            Money::vnd($total),
            null,
            ['type' => 'FLEXIBLE'],
            ['timing' => $paymentTiming],
            [1],
        );
    }
}

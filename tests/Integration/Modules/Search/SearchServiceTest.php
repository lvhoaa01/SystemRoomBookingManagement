<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Search\Application\DeterministicRoomAllocator;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Application\SearchService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use PDO;
use Tests\Support\TestCase;

final class SearchServiceTest extends TestCase
{
    private SearchService $service;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo = $pdo;
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-01 02:00:00+00:00'));
        $this->service = new SearchService(
            new SearchCatalogRepository($pdo),
            new PropertyAllocationService(new StandardPriceCalculator(), new DeterministicRoomAllocator()),
            $clock,
        );
    }

    public function test_returns_only_properties_with_a_complete_pure_timing_allocation(): void
    {
        $page = $this->service->search($this->criteria(parties: [
            new RoomParty(1, 2, [7]),
            new RoomParty(2, 1),
        ]));

        self::assertGreaterThan(0, $page->total);
        self::assertLessThanOrEqual(12, count($page->results));
        foreach ($page->results as $result) {
            self::assertCount(2, $result->representative->rooms);
            foreach ($result->representative->rooms as $room) {
                self::assertSame($result->representative->paymentTiming, $room->paymentTiming);
            }
            self::assertGreaterThan(0, $result->representative->grandTotal->amount());
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->representative->selectionFingerprint);
        }
    }

    public function test_payment_filter_only_prices_and_sorts_that_partition(): void
    {
        $page = $this->service->search($this->criteria(
            filters: ['paymentTiming' => 'PAY_ONLINE'],
            sort: 'PRICE_ASC',
        ));

        self::assertGreaterThan(1, count($page->results));
        $previous = 0;
        foreach ($page->results as $result) {
            self::assertSame('PAY_ONLINE', $result->representative->paymentTiming);
            self::assertGreaterThanOrEqual($previous, $result->representative->grandTotal->amount());
            $previous = $result->representative->grandTotal->amount();
        }
    }

    public function test_price_range_uses_the_same_representative_total_shown_on_the_card(): void
    {
        $baseline = $this->service->search($this->criteria(sort: 'PRICE_ASC'));
        self::assertNotEmpty($baseline->results);
        $amount = $baseline->results[0]->representative->grandTotal->amount();

        $filtered = $this->service->search($this->criteria(filters: [
            'minPrice' => $amount,
            'maxPrice' => $amount,
        ]));

        self::assertNotEmpty($filtered->results);
        foreach ($filtered->results as $result) {
            self::assertSame($amount, $result->representative->grandTotal->amount());
        }
    }

    public function test_same_snapshot_returns_the_same_order_allocation_and_fingerprint(): void
    {
        $criteria = $this->criteria(sort: 'PRICE_ASC');
        $first = $this->service->search($criteria);
        $second = $this->service->search($criteria);

        self::assertSame(
            array_map(static fn ($item): array => [
                $item->property->propertyId,
                $item->representative->selectionFingerprint,
                $item->representative->grandTotal->amount(),
            ], $first->results),
            array_map(static fn ($item): array => [
                $item->property->propertyId,
                $item->representative->selectionFingerprint,
                $item->representative->grandTotal->amount(),
            ], $second->results),
        );
    }

    public function test_seeded_capacity_segments_produce_distinct_customer_search_results(): void
    {
        $cases = [
            'một khách' => [[new RoomParty(1, 1)], 24],
            'hai người lớn' => [[new RoomParty(1, 2)], 21],
            'gia đình có một trẻ em' => [[new RoomParty(1, 2, [7])], 17],
            'gia đình có hai trẻ em' => [[new RoomParty(1, 2, [7, 10])], 10],
            'ba người lớn' => [[new RoomParty(1, 3)], 11],
            'bốn người lớn' => [[new RoomParty(1, 4)], 6],
            'năm người lớn' => [[new RoomParty(1, 5)], 3],
        ];

        foreach ($cases as $label => [$parties, $expectedTotal]) {
            $page = $this->service->search($this->criteria(parties: $parties));

            self::assertSame(
                $expectedTotal,
                $page->total,
                "Kịch bản {$label} phải phản ánh đúng phân khúc sức chứa của seed.",
            );
        }
    }

    public function test_seeded_inventory_reduces_results_as_the_room_count_increases(): void
    {
        $oneRoom = $this->service->search($this->criteria(parties: [new RoomParty(1, 2)]));
        $fiveRooms = $this->service->search($this->criteria(parties: [
            new RoomParty(1, 2),
            new RoomParty(2, 2),
            new RoomParty(3, 2),
            new RoomParty(4, 2),
            new RoomParty(5, 2),
        ]));

        self::assertSame(21, $oneRoom->total);
        self::assertSame(10, $fiveRooms->total);
    }

    public function test_seeded_filters_no_longer_return_the_same_market(): void
    {
        $cases = [
            'thanh toán trực tuyến' => [['paymentTiming' => 'PAY_ONLINE'], 15],
            'thanh toán tại cơ sở' => [['paymentTiming' => 'PAY_AT_PROPERTY'], 24],
            'có bữa sáng' => [['mealTypes' => ['BREAKFAST_INCLUDED']], 14],
            'hủy miễn phí' => [['freeCancellation' => true], 20],
            'ba sao' => [['starRatings' => [3]], 5],
            'bốn sao' => [['starRatings' => [4]], 7],
            'năm sao' => [['starRatings' => [5]], 12],
            'điểm từ tám' => [['minReviewScore' => 8], 15],
            'điểm từ chín' => [['minReviewScore' => 9], 7],
        ];

        foreach ($cases as $label => [$filters, $expectedTotal]) {
            $page = $this->service->search($this->criteria(
                filters: $filters,
                parties: [new RoomParty(1, 1)],
            ));

            self::assertSame($expectedTotal, $page->total, "Bộ lọc {$label} phải có thị trường riêng.");
        }
    }

    public function test_seeded_sold_out_rows_are_backed_by_real_active_holds(): void
    {
        $soldOutWithHolds = (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM TonPhongNgay inventory
             INNER JOIN LoaiPhong room_type ON room_type.LoaiPhongID = inventory.LoaiPhongID
             WHERE room_type.CoSoLuuTruID <= 27
               AND room_type.TrangThai = 'ACTIVE'
               AND inventory.SoLuongDaGiu > 0
               AND inventory.TongSoLuong = inventory.SoLuongDaGiu"
        )->fetchColumn();

        self::assertGreaterThan(500, $soldOutWithHolds);
    }

    /** @param array<string, mixed> $filters @param list<RoomParty>|null $parties */
    private function criteria(array $filters = [], string $sort = 'RECOMMENDED', ?array $parties = null): SearchCriteria
    {
        return new SearchCriteria(
            'HCM',
            new StayPeriod('2026-12-10', '2026-12-12'),
            $parties ?? [new RoomParty(1, 2)],
            $filters,
            $sort,
        );
    }
}

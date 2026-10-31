<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Search\Application\DeterministicRoomAllocator;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Modules\Search\Application\SearchService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use PDO;
use Tests\Support\TestCase;

final class PropertyAvailabilityServiceTest extends TestCase
{
    private PDO $pdo;
    private SearchService $search;
    private PropertyAvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-01 02:00:00+00:00'));
        $catalog = new SearchCatalogRepository($this->pdo);
        $allocation = new PropertyAllocationService(new StandardPriceCalculator(), new DeterministicRoomAllocator());
        $this->search = new SearchService($catalog, $allocation, $clock);
        $this->availability = new PropertyAvailabilityService($catalog, $allocation, $clock);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_detail_matches_search_price_allocation_timing_and_fingerprint_when_snapshot_is_unchanged(): void
    {
        $criteria = $this->criteria();
        $card = $this->search->search($criteria)->results[0];
        $selected = $this->mapping($card->representative);

        $detail = $this->availability->check(
            $card->property->slug,
            $criteria,
            $card->representative->paymentTiming,
            $card->representative->selectionFingerprint,
            $selected,
        );

        self::assertNotNull($detail);
        self::assertNotNull($detail->allocation);
        self::assertFalse($detail->selectionChanged);
        self::assertSame($card->representative->paymentTiming, $detail->paymentTiming);
        self::assertSame($card->representative->grandTotal->amount(), $detail->allocation->grandTotal->amount());
        self::assertSame($card->representative->selectionFingerprint, $detail->allocation->selectionFingerprint);
    }

    public function test_rate_version_change_is_reported_instead_of_silently_reusing_the_card(): void
    {
        $criteria = $this->criteria();
        $card = $this->search->search($criteria)->results[0];
        $productId = $card->representative->rooms[0]->productId;
        $this->pdo->beginTransaction();
        $update = $this->pdo->prepare(
            'UPDATE GiaPhongNgay SET GiaCoBan = GiaCoBan + 10000, PhienBan = PhienBan + 1
             WHERE SanPhamPhongID = ? AND NgayLuuTru = ?'
        );
        $update->execute([$productId, '2026-12-10']);

        $detail = $this->availability->check(
            $card->property->slug,
            $criteria,
            $card->representative->paymentTiming,
            $card->representative->selectionFingerprint,
            $this->mapping($card->representative),
        );

        self::assertNotNull($detail);
        self::assertTrue($detail->selectionChanged);
        self::assertNotNull($detail->changeReason);
        self::assertNotSame($card->representative->selectionFingerprint, $detail->allocation?->selectionFingerprint);
    }

    public function test_timing_toggle_only_returns_the_requested_partition(): void
    {
        $criteria = $this->criteria();
        $card = $this->search->search($criteria)->results[0];
        $otherTiming = $card->representative->paymentTiming === 'PAY_AT_PROPERTY' ? 'PAY_ONLINE' : 'PAY_AT_PROPERTY';

        $detail = $this->availability->check($card->property->slug, $criteria, $otherTiming);

        self::assertNotNull($detail);
        self::assertSame($otherTiming, $detail->paymentTiming);
        if ($detail->allocation !== null) {
            foreach ($detail->allocation->rooms as $room) {
                self::assertSame($otherTiming, $room->paymentTiming);
            }
        }
        self::assertArrayHasKey('PAY_AT_PROPERTY', $detail->timingSummary);
        self::assertArrayHasKey('PAY_ONLINE', $detail->timingSummary);
    }

    public function test_selected_mapping_is_untrusted_and_mismatch_is_reported(): void
    {
        $criteria = $this->criteria();
        $card = $this->search->search($criteria)->results[0];
        $selected = $this->mapping($card->representative);
        $selected[1]['productId'] += 999_999;

        $detail = $this->availability->check(
            $card->property->slug,
            $criteria,
            $card->representative->paymentTiming,
            null,
            $selected,
        );

        self::assertNotNull($detail);
        self::assertTrue($detail->selectionChanged);
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            'HCM',
            new StayPeriod('2026-12-10', '2026-12-12'),
            [new RoomParty(1, 2)],
        );
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function mapping(\App\Shared\Contract\AllocationResult $allocation): array
    {
        $mapping = [];
        foreach ($allocation->rooms as $room) {
            $mapping[$room->roomOrder] = [
                'roomTypeId' => $room->roomTypeId,
                'productId' => $room->productId,
            ];
        }
        return $mapping;
    }
}

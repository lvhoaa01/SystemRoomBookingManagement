<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use App\Bootstrap\App;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class SearchCatalogRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SearchCatalogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->repository = new SearchCatalogRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_reads_only_active_hcm_properties_with_complete_rates_and_inventory(): void
    {
        $snapshots = $this->repository->findCandidates($this->criteria(), $this->bookedAt());

        self::assertNotEmpty($snapshots);
        foreach ($snapshots as $property) {
            $statement = $this->pdo->prepare(
                'SELECT cs.MaThanhPho, cs.TrangThai, tc.TrangThai AS organizationStatus
                 FROM CoSoLuuTru cs INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
                 WHERE cs.CoSoLuuTruID = ?'
            );
            $statement->execute([$property->propertyId]);
            $state = $statement->fetch(PDO::FETCH_ASSOC);
            self::assertSame('HCM', $state['MaThanhPho']);
            self::assertSame('ACTIVE', $state['TrangThai']);
            self::assertSame('HOAT_DONG', $state['organizationStatus']);
            self::assertNotEmpty($property->roomProducts);
            foreach ($property->roomProducts as $product) {
                self::assertCount(2, $product->pricing->rates);
                self::assertCount(2, $product->inventoryVersions);
                self::assertGreaterThan(0, $product->minimumAvailable);
            }
        }
    }

    public function test_closed_rate_removes_that_product_from_the_snapshot(): void
    {
        $before = $this->repository->findCandidates($this->criteria('Silverland'), $this->bookedAt());
        self::assertNotEmpty($before);
        $property = $before[0];
        $productId = $property->roomProducts[0]->productId;

        $this->pdo->beginTransaction();
        $update = $this->pdo->prepare(
            'UPDATE GiaPhongNgay SET DongBan = 1 WHERE SanPhamPhongID = ? AND NgayLuuTru = ?'
        );
        $update->execute([$productId, '2026-12-10']);

        $after = $this->repository->findBySlug($property->slug, $this->criteria(), $this->bookedAt());
        self::assertNotNull($after);
        self::assertNotContains($productId, array_map(static fn ($item): int => $item->productId, $after->roomProducts));
    }

    public function test_applies_payment_meal_and_facility_filters_in_the_read_model(): void
    {
        $criteria = $this->criteria(filters: [
            'paymentTiming' => 'PAY_ONLINE',
            'mealTypes' => ['ROOM_ONLY'],
            'facilityIds' => [1],
        ]);

        $snapshots = $this->repository->findCandidates($criteria, $this->bookedAt());

        self::assertNotEmpty($snapshots);
        foreach ($snapshots as $property) {
            self::assertContains(1, array_column($property->facilities, 'id'));
            foreach ($property->roomProducts as $product) {
                self::assertSame('PAY_ONLINE', $product->paymentTiming);
                self::assertSame('ROOM_ONLY', $product->mealType);
            }
        }
    }

    public function test_slug_lookup_hides_property_after_it_is_suspended(): void
    {
        $property = $this->repository->findCandidates($this->criteria('Silverland'), $this->bookedAt())[0];
        $this->pdo->beginTransaction();
        $update = $this->pdo->prepare("UPDATE CoSoLuuTru SET TrangThai = 'SUSPENDED' WHERE CoSoLuuTruID = ?");
        $update->execute([$property->propertyId]);

        self::assertNull($this->repository->findBySlug($property->slug, $this->criteria(), $this->bookedAt()));
    }

    /** @param array<string, mixed> $filters */
    private function criteria(string $destination = 'HCM', array $filters = []): SearchCriteria
    {
        return new SearchCriteria(
            $destination,
            new StayPeriod('2026-12-10', '2026-12-12'),
            [new RoomParty(1, 2)],
            $filters,
        );
    }

    private function bookedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC'));
    }
}

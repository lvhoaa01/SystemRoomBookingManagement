<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Inventory\Application\BookingInventoryItem;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Tests\Support\TestCase;

final class InventoryReservationServiceTest extends TestCase
{
    private PDO $pdo;
    private InventoryReservationService $service;
    private InventoryRepository $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $app = App::boot(dirname(__DIR__, 4));
        $this->pdo = $app->container()->get(PDO::class);
        $this->service = $app->container()->get(InventoryReservationService::class);
        $this->inventory = $app->container()->get(InventoryRepository::class);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_holds_multiple_rooms_and_nights_then_releases_exactly_once(): void
    {
        $fixture = $this->bookingFixture(2);
        $dates = ['2035-01-01', '2035-01-02'];
        foreach ($dates as $date) {
            self::assertNotNull($this->inventory->upsertTotal(
                $fixture['roomTypeId'], $fixture['organizationId'], $date, 2,
            ));
        }
        $items = array_map(
            fn (int $id): BookingInventoryItem => new BookingInventoryItem($id, $fixture['roomTypeId'], $dates),
            $fixture['bookingItemIds'],
        );

        $this->service->holdForBooking(
            $fixture['bookingId'],
            $items,
            new DateTimeImmutable('2034-12-31 12:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(4, $this->ledgerCount($fixture['bookingItemIds'], 'ACTIVE'));
        foreach ($dates as $date) {
            $row = $this->inventory->findForOrganization(
                $fixture['roomTypeId'], $date, $fixture['organizationId'],
            );
            self::assertSame(2, (int) $row['heldQuantity']);
            self::assertSame(0, (int) $row['availableQuantity']);
        }

        self::assertSame(4, $this->service->releaseActive($fixture['bookingId'], 'TEST_RELEASE'));
        self::assertSame(0, $this->service->releaseActive($fixture['bookingId'], 'TEST_RELEASE'));
        self::assertSame(4, $this->ledgerCount($fixture['bookingItemIds'], 'RELEASED'));
        foreach ($dates as $date) {
            self::assertSame(0, (int) $this->inventory->findForOrganization(
                $fixture['roomTypeId'], $date, $fixture['organizationId'],
            )['heldQuantity']);
        }
    }

    public function test_missing_night_creates_no_ledger_and_does_not_change_existing_counter(): void
    {
        $fixture = $this->bookingFixture(1);
        self::assertNotNull($this->inventory->upsertTotal(
            $fixture['roomTypeId'], $fixture['organizationId'], '2035-02-01', 1,
        ));

        try {
            $this->service->holdForBooking(
                $fixture['bookingId'],
                [new BookingInventoryItem(
                    $fixture['bookingItemIds'][0],
                    $fixture['roomTypeId'],
                    ['2035-02-01', '2035-02-02'],
                )],
                null,
            );
            self::fail('Thiếu một đêm tồn phải làm thao tác thất bại.');
        } catch (DomainException) {
            self::assertSame(0, $this->ledgerCount($fixture['bookingItemIds'], 'ACTIVE'));
            self::assertSame(0, (int) $this->inventory->findForOrganization(
                $fixture['roomTypeId'], '2035-02-01', $fixture['organizationId'],
            )['heldQuantity']);
        }
    }

    public function test_finds_only_expired_holds_of_pending_payment_bookings(): void
    {
        $fixture = $this->bookingFixture(1, 'PENDING_PAYMENT');
        $date = '2035-03-01';
        self::assertNotNull($this->inventory->upsertTotal(
            $fixture['roomTypeId'], $fixture['organizationId'], $date, 1,
        ));
        $this->service->holdForBooking(
            $fixture['bookingId'],
            [new BookingInventoryItem($fixture['bookingItemIds'][0], $fixture['roomTypeId'], [$date])],
            new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertContains(
            $fixture['bookingId'],
            $this->service->findExpiredBookingIds(
                new DateTimeImmutable('2031-01-01 00:00:00', new DateTimeZone('UTC')),
            ),
        );
    }

    /**
     * @return array{bookingId: int, roomTypeId: int, organizationId: int, bookingItemIds: list<int>}
     */
    private function bookingFixture(int $itemCount, ?string $requiredStatus = null): array
    {
        $where = $requiredStatus === null
            ? "dc.TrangThai IN ('PENDING_PAYMENT','CONFIRMED')"
            : "dc.TrangThai = " . $this->pdo->quote($requiredStatus);
        $row = $this->pdo->query(
            "SELECT dc.DatChoID, sp.SanPhamPhongID, sp.LoaiPhongID, cs.ToChucDoiTacID,
                    COALESCE((SELECT MAX(h.ThuTuPhong) FROM HangMucDatCho h WHERE h.DatChoID = dc.DatChoID), 0) AS maxRoomOrder
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = dc.CoSoLuuTruID
             INNER JOIN LoaiPhong lp ON lp.CoSoLuuTruID = dc.CoSoLuuTruID
             INNER JOIN SanPhamPhong sp ON sp.LoaiPhongID = lp.LoaiPhongID
             WHERE {$where}
             ORDER BY dc.DatChoID, sp.SanPhamPhongID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Seed phải có booking hợp lệ cho integration test Inventory.');
        $this->service->releaseActive((int) $row['DatChoID'], 'TEST_SETUP');

        $insert = $this->pdo->prepare(
            "INSERT INTO HangMucDatCho
                (DatChoID, SanPhamPhongID, LoaiPhongID, ThuTuPhong,
                 TenLoaiPhongSnapshot, TenSanPhamSnapshot, LoaiBuaAnSnapshot,
                 KhachJSON, TongTien, SnapshotDieuKienJSON, SnapshotHuyJSON)
             VALUES (?, ?, ?, ?, 'Inventory test room', 'Inventory test product', 'NONE',
                     '{}', 100000, '{}', '{}')"
        );
        $ids = [];
        for ($index = 1; $index <= $itemCount; ++$index) {
            $insert->execute([
                (int) $row['DatChoID'],
                (int) $row['SanPhamPhongID'],
                (int) $row['LoaiPhongID'],
                (int) $row['maxRoomOrder'] + $index,
            ]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        return [
            'bookingId' => (int) $row['DatChoID'],
            'roomTypeId' => (int) $row['LoaiPhongID'],
            'organizationId' => (int) $row['ToChucDoiTacID'],
            'bookingItemIds' => $ids,
        ];
    }

    /** @param list<int> $bookingItemIds */
    private function ledgerCount(array $bookingItemIds, string $status): int
    {
        $placeholders = implode(',', array_fill(0, count($bookingItemIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM GiuTonPhongDem
             WHERE HangMucDatChoID IN (' . $placeholders . ') AND TrangThai = ?'
        );
        $statement->execute([...$bookingItemIds, $status]);
        return (int) $statement->fetchColumn();
    }
}

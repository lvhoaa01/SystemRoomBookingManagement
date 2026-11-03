<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\TransactionManager;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class RateBookingRaceAcceptanceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 2))->container()->get(PDO::class);
    }

    public function test_booking_and_rate_edit_create_one_coherent_snapshot_or_reject_stale_preview(): void
    {
        $container = App::boot(dirname(__DIR__, 2))->container();
        [$property, $allocation] = $this->selection();
        $room = $allocation->rooms[0];
        $customerId = $this->customerId();
        $preview = $container->get(PreviewService::class)->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            [1 => ['roomTypeId' => $room->roomTypeId, 'productId' => $room->productId]],
        );
        $dates = array_map(static fn (array $night): string => (string) $night['stayDate'], $room->priceBreakdown->nights);
        $rates = $this->rateRows($room->productId, $dates);
        $inventory = $this->inventoryRows($room->roomTypeId, $dates);
        $organization = $this->organization($room->productId);
        $newRows = array_map(static fn (array $row): array => [
            'stayDate' => (string) $row['NgayLuuTru'],
            'basePrice' => (string) ((int) $row['GiaCoBan'] + 10_000),
            'closed' => (int) $row['DongBan'] === 1,
            'minimumStay' => (int) $row['SoDemToiThieu'],
            'maximumStay' => $row['SoDemToiDa'] === null ? null : (int) $row['SoDemToiDa'],
            'minimumLeadDays' => (int) $row['SoNgayDatTruoc'],
        ], $rates);
        $bookingId = null;
        try {
            [$bookingResult, $rateResult] = $this->runWorkers(
                [
                    'previewToken' => $preview->token,
                    'customerId' => $customerId,
                    'clientRequestId' => $this->uuid(),
                    'requestId' => 'acceptance-booking-rate',
                ],
                [
                    'productId' => $room->productId,
                    'organization' => $organization,
                    'actorId' => $organization['actorId'],
                    'rows' => $newRows,
                    'requestId' => 'acceptance-rate-booking',
                ],
            );

            self::assertTrue($rateResult['success']);
            if ($bookingResult['success']) {
                $bookingId = (int) $bookingResult['bookingId'];
                $actual = array_map('intval', $this->pdo->query(
                    "SELECT gd.GiaCoBan FROM GiaDemDatCho gd
                     INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID
                     WHERE hm.DatChoID={$bookingId} ORDER BY gd.NgayLuuTru",
                )->fetchAll(PDO::FETCH_COLUMN));
                $old = array_map(static fn (array $row): int => (int) $row['GiaCoBan'], $rates);
                $new = array_map(static fn (array $row): int => (int) $row['basePrice'], $newRows);
                self::assertTrue($actual === $old || $actual === $new, 'Snapshot booking không được trộn hai phiên bản giá.');
            } else {
                self::assertSame('PREVIEW_STALE', $bookingResult['error']);
            }
            $this->assertInventoryMatchesLedger($inventory);
        } finally {
            if ($bookingId !== null) {
                $this->deleteBooking($bookingId);
            }
            foreach ($inventory as $row) {
                $this->update('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $row);
            }
            foreach ($rates as $row) {
                $this->update('GiaPhongNgay', ['SanPhamPhongID', 'NgayLuuTru'], $row);
            }
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID IN (?,?)')
                ->execute(['acceptance-booking-rate', 'acceptance-rate-booking']);
            $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID=?')->execute([$preview->preview->id]);
        }
    }

    /** @return array{\App\Modules\Search\Application\PropertySearchSnapshot, AllocationResult} */
    private function selection(): array
    {
        $container = App::boot(dirname(__DIR__, 2))->container();
        $catalog = new SearchCatalogRepository($this->pdo);
        $clock = $container->get(ClockInterface::class);
        foreach ($catalog->findCandidates($this->criteria(), $clock->now()) as $property) {
            $allocation = $container->get(PropertyAllocationService::class)
                ->allocate($property, $this->criteria(), $clock->now(), 'PAY_AT_PROPERTY')[0] ?? null;
            if ($allocation !== null && count($allocation->rooms) === 1) {
                return [$property, $allocation];
            }
        }
        self::fail('Fixture phải có allocation một phòng để nghiệm thu race giá-booking.');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function runWorkers(array $booking, array $rate): array
    {
        $startAt = microtime(true) + 0.25;
        $booking['startAt'] = $startAt;
        $rate['startAt'] = $startAt;
        $processes = [
            $this->start(dirname(__DIR__) . '/Support/BookingConcurrencyWorker.php', $booking),
            $this->start(dirname(__DIR__) . '/Support/RateRaceWorker.php', $rate),
        ];
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || $stderr !== '') {
                throw new RuntimeException('Worker race giá-booking lỗi: ' . trim($stderr));
            }
            $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $results[] = $result;
        }
        return $results;
    }

    /** @param array<string, mixed> $payload @return array{resource, array<int, resource>} */
    private function start(string $worker, array $payload): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
            . escapeshellarg(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)));
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException('Không thể chạy worker race giá-booking.');
        }
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @param list<string> $dates @return list<array<string, mixed>> */
    private function rateRows(int $productId, array $dates): array
    {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM GiaPhongNgay WHERE SanPhamPhongID=? AND NgayLuuTru IN ({$placeholders}) ORDER BY NgayLuuTru",
        );
        $statement->execute([$productId, ...$dates]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(count($dates), $rows);
        return $rows;
    }

    /** @param list<string> $dates @return list<array<string, mixed>> */
    private function inventoryRows(int $roomTypeId, array $dates): array
    {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM TonPhongNgay WHERE LoaiPhongID=? AND NgayLuuTru IN ({$placeholders}) ORDER BY NgayLuuTru",
        );
        $statement->execute([$roomTypeId, ...$dates]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{id: int, code: string, name: string, status: string, actorId: int} */
    private function organization(int $productId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT tc.ToChucDoiTacID id, tc.MaToChuc code, tc.TenToChuc name, tc.TrangThai status,
                    (SELECT tv.NguoiDungID FROM ThanhVienDoiTac tv WHERE tv.ToChucDoiTacID=tc.ToChucDoiTacID ORDER BY tv.NguoiDungID LIMIT 1) actorId
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID=sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=lp.CoSoLuuTruID
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID=cs.ToChucDoiTacID
             WHERE sp.SanPhamPhongID=?",
        );
        $statement->execute([$productId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
            'status' => (string) $row['status'], 'actorId' => (int) $row['actorId']];
    }

    /** @param list<array<string, mixed>> $inventory */
    private function assertInventoryMatchesLedger(array $inventory): void
    {
        foreach ($inventory as $row) {
            $roomTypeId = (int) $row['LoaiPhongID'];
            $date = (string) $row['NgayLuuTru'];
            self::assertSame(
                (string) $this->pdo->query("SELECT COUNT(*) FROM GiuTonPhongDem WHERE LoaiPhongID={$roomTypeId} AND NgayLuuTru='{$date}' AND TrangThai='ACTIVE'")->fetchColumn(),
                (string) $this->pdo->query("SELECT SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID={$roomTypeId} AND NgayLuuTru='{$date}'")->fetchColumn(),
            );
        }
    }

    private function deleteBooking(int $bookingId): void
    {
        $container = App::boot(dirname(__DIR__, 2))->container();
        $container->get(TransactionManager::class)->run(function () use ($container, $bookingId): void {
            $container->get(InventoryReservationService::class)->releaseActive($bookingId, 'TEST_CLEANUP');
            $this->pdo->prepare("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu='DatCho' AND DoiTuID=?")->execute([(string) $bookingId]);
            $this->pdo->prepare('DELETE FROM ThanhToan WHERE DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE gd FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID WHERE hm.DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE FROM HangMucDatCho WHERE DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE FROM DatCho WHERE DatChoID=?')->execute([$bookingId]);
        });
    }

    /** @param list<string> $keys @param array<string, mixed> $row */
    private function update(string $table, array $keys, array $row): void
    {
        $columns = array_keys($row);
        $set = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", $columns));
        $where = implode(' AND ', array_map(static fn (string $column): string => "`{$column}`=?", $keys));
        $values = [...array_values($row), ...array_map(static fn (string $key): mixed => $row[$key], $keys)];
        $this->pdo->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}")->execute($values);
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria('HCM', new StayPeriod('2026-12-10', '2026-12-12'), [new RoomParty(1, 2)]);
    }

    private function customerId(): int
    {
        return (int) $this->pdo->query("SELECT NguoiDungID FROM NguoiDung WHERE VaiTro='CUSTOMER' AND TrangThai='HOAT_DONG' ORDER BY NguoiDungID LIMIT 1")->fetchColumn();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

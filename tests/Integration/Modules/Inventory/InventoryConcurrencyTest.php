<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class InventoryConcurrencyTest extends TestCase
{
    private PDO $pdo;

    /** @var list<int> */
    private array $bookingItemIds = [];

    /** @var list<array{roomTypeId: int, stayDate: string}> */
    private array $inventoryRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->bookingItemIds !== []) {
                $placeholders = implode(',', array_fill(0, count($this->bookingItemIds), '?'));
                $this->pdo->prepare(
                    'DELETE FROM GiuTonPhongDem WHERE HangMucDatChoID IN (' . $placeholders . ')'
                )->execute($this->bookingItemIds);
                $this->pdo->prepare(
                    'DELETE FROM HangMucDatCho WHERE HangMucDatChoID IN (' . $placeholders . ')'
                )->execute($this->bookingItemIds);
            }
            foreach ($this->inventoryRows as $row) {
                $this->pdo->prepare(
                    'DELETE FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
                )->execute([$row['roomTypeId'], $row['stayDate']]);
            }
        }
        parent::tearDown();
    }

    public function test_two_connections_competing_for_last_unit_allow_only_one_hold(): void
    {
        $fixture = $this->fixture(2, '2036-01-01');
        $results = $this->runConcurrently([
            ['hold', $this->holdPayload($fixture, $fixture['bookingItemIds'][0])],
            ['hold', $this->holdPayload($fixture, $fixture['bookingItemIds'][1])],
        ]);

        self::assertSame(1, count(array_filter($results, static fn (array $row): bool => $row['success'])));
        $row = $this->inventoryRow($fixture['roomTypeId'], $fixture['stayDate']);
        self::assertSame(1, (int) $row['SoLuongDaGiu']);
        self::assertSame(1, $this->activeLedgerCount($fixture['roomTypeId'], $fixture['stayDate']));
    }

    public function test_concurrent_total_reduction_and_hold_preserve_capacity_invariant(): void
    {
        $fixture = $this->fixture(1, '2036-01-02');
        $results = $this->runConcurrently([
            ['hold', $this->holdPayload($fixture, $fixture['bookingItemIds'][0])],
            ['reduce', [
                'roomTypeId' => $fixture['roomTypeId'],
                'organizationId' => $fixture['organizationId'],
                'stayDate' => $fixture['stayDate'],
                'totalQuantity' => 0,
            ]],
        ]);

        self::assertSame(1, count(array_filter($results, static fn (array $row): bool => $row['success'])));
        $row = $this->inventoryRow($fixture['roomTypeId'], $fixture['stayDate']);
        self::assertLessThanOrEqual((int) $row['TongSoLuong'], (int) $row['SoLuongDaGiu']);
        self::assertSame(
            (int) $row['SoLuongDaGiu'],
            $this->activeLedgerCount($fixture['roomTypeId'], $fixture['stayDate']),
        );
    }

    /**
     * @return array{bookingId: int, roomTypeId: int, organizationId: int, stayDate: string, bookingItemIds: list<int>}
     */
    private function fixture(int $itemCount, string $stayDate): array
    {
        $row = $this->pdo->query(
            "SELECT dc.DatChoID, sp.SanPhamPhongID, sp.LoaiPhongID, cs.ToChucDoiTacID,
                    COALESCE((SELECT MAX(h.ThuTuPhong) FROM HangMucDatCho h WHERE h.DatChoID = dc.DatChoID), 0) AS maxRoomOrder
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = dc.CoSoLuuTruID
             INNER JOIN LoaiPhong lp ON lp.CoSoLuuTruID = dc.CoSoLuuTruID
             INNER JOIN SanPhamPhong sp ON sp.LoaiPhongID = lp.LoaiPhongID
             WHERE dc.TrangThai IN ('PENDING_PAYMENT','CONFIRMED')
             ORDER BY dc.DatChoID, sp.SanPhamPhongID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $this->pdo->prepare(
            'INSERT INTO TonPhongNgay
                (LoaiPhongID, NgayLuuTru, TongSoLuong, SoLuongDaGiu, PhienBan)
             VALUES (?, ?, 1, 0, 1)'
        )->execute([(int) $row['LoaiPhongID'], $stayDate]);
        $this->inventoryRows[] = ['roomTypeId' => (int) $row['LoaiPhongID'], 'stayDate' => $stayDate];

        $insert = $this->pdo->prepare(
            "INSERT INTO HangMucDatCho
                (DatChoID, SanPhamPhongID, LoaiPhongID, ThuTuPhong,
                 TenLoaiPhongSnapshot, TenSanPhamSnapshot, LoaiBuaAnSnapshot,
                 KhachJSON, TongTien, SnapshotDieuKienJSON, SnapshotHuyJSON)
             VALUES (?, ?, ?, ?, 'Concurrency room', 'Concurrency product', 'NONE',
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
        $this->bookingItemIds = [...$this->bookingItemIds, ...$ids];
        return [
            'bookingId' => (int) $row['DatChoID'],
            'roomTypeId' => (int) $row['LoaiPhongID'],
            'organizationId' => (int) $row['ToChucDoiTacID'],
            'stayDate' => $stayDate,
            'bookingItemIds' => $ids,
        ];
    }

    /** @param array{bookingId: int, roomTypeId: int, stayDate: string} $fixture */
    private function holdPayload(array $fixture, int $bookingItemId): array
    {
        return [
            'bookingId' => $fixture['bookingId'],
            'bookingItemId' => $bookingItemId,
            'roomTypeId' => $fixture['roomTypeId'],
            'stayDate' => $fixture['stayDate'],
        ];
    }

    /**
     * @param list<array{string, array<string, int|string>}> $jobs
     * @return list<array{success: bool, error?: string, message?: string}>
     */
    private function runConcurrently(array $jobs): array
    {
        $worker = dirname(__DIR__, 3) . '/Support/InventoryConcurrencyWorker.php';
        $processes = [];
        foreach ($jobs as [$action, $payload]) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
                . escapeshellarg($action) . ' '
                . escapeshellarg(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)));
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 4));
            if (!is_resource($process)) {
                throw new RuntimeException('Không thể khởi chạy worker concurrency.');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0 || $stderr !== '') {
                throw new RuntimeException('Worker concurrency lỗi: ' . trim($stderr));
            }
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $results[] = $result;
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function inventoryRow(int $roomTypeId, string $stayDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TongSoLuong, SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        );
        $statement->execute([$roomTypeId, $stayDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function activeLedgerCount(int $roomTypeId, string $stayDate): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM GiuTonPhongDem
             WHERE LoaiPhongID = ? AND NgayLuuTru = ? AND TrangThai = 'ACTIVE'"
        );
        $statement->execute([$roomTypeId, $stayDate]);
        return (int) $statement->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Bootstrap\App;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class PaymentRaceAcceptanceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 2))->container()->get(PDO::class);
    }

    public function test_callback_and_expiry_race_has_one_terminal_state_and_late_success_does_not_revive_booking(): void
    {
        $bookingId = (int) $this->pdo->query(
            "SELECT dc.DatChoID
             FROM DatCho dc
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='PENDING_PAYMENT' AND tt.TrangThai='PROCESSING'
               AND EXISTS (
                   SELECT 1 FROM HangMucDatCho hm
                   INNER JOIN GiuTonPhongDem gt ON gt.HangMucDatChoID=hm.HangMucDatChoID
                   WHERE hm.DatChoID=dc.DatChoID AND gt.TrangThai='ACTIVE'
               )
             ORDER BY dc.DatChoID LIMIT 1",
        )->fetchColumn();
        self::assertGreaterThan(0, $bookingId);
        $state = $this->snapshot($bookingId);

        try {
            $expiredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 minute')->format('Y-m-d H:i:s.u');
            $this->pdo->prepare('UPDATE DatCho SET HetHanThanhToanLuc=? WHERE DatChoID=?')->execute([$expiredAt, $bookingId]);
            $this->pdo->prepare(
                "UPDATE GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
                 SET gt.HetHanLuc=? WHERE hm.DatChoID=? AND gt.TrangThai='ACTIVE'",
            )->execute([$expiredAt, $bookingId]);

            $results = $this->runWorkers($bookingId);
            self::assertCount(2, array_filter($results, static fn (array $result): bool => $result['success'] === true));
            self::assertSame('PAYMENT_FAILED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . $bookingId));
            self::assertSame('FAILED', $this->scalar('SELECT TrangThai FROM ThanhToan WHERE DatChoID=' . $bookingId));
            self::assertSame('SUCCEEDED', $this->scalar('SELECT TrangThaiHoanTien FROM ThanhToan WHERE DatChoID=' . $bookingId));
            self::assertSame('0', $this->scalar(
                "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
                 WHERE hm.DatChoID={$bookingId} AND gt.TrangThai='ACTIVE'",
            ));
            $this->assertInventoryMatchesLedger($state['inventory']);
        } finally {
            $this->restore($state);
        }
    }

    /** @return list<array<string, mixed>> */
    private function runWorkers(int $bookingId): array
    {
        $worker = dirname(__DIR__) . '/Support/PaymentRaceWorker.php';
        $processes = [];
        foreach (['callback', 'expiry'] as $action) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
                . escapeshellarg($action) . ' ' . escapeshellarg((string) $bookingId);
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (!is_resource($process)) {
                throw new RuntimeException('Không thể chạy worker race payment.');
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
            $exit = proc_close($process);
            if ($exit !== 0 || $stderr !== '') {
                throw new RuntimeException('Worker race payment lỗi: ' . trim($stderr));
            }
            $decoded = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $results[] = $decoded;
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function snapshot(int $bookingId): array
    {
        $holds = $this->rows(
            "SELECT gt.* FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID={$bookingId}",
        );
        $inventory = [];
        foreach ($holds as $hold) {
            $key = $hold['LoaiPhongID'] . ':' . $hold['NgayLuuTru'];
            $inventory[$key] = $this->row(
                "SELECT * FROM TonPhongNgay WHERE LoaiPhongID={$hold['LoaiPhongID']} AND NgayLuuTru='{$hold['NgayLuuTru']}'",
            );
        }
        return [
            'booking' => $this->row('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId),
            'payment' => $this->row('SELECT * FROM ThanhToan WHERE DatChoID=' . $bookingId),
            'holds' => $holds,
            'inventory' => array_values($inventory),
            'audits' => $this->rows("SELECT * FROM NhatKyHeThong WHERE LoaiDoiTu='DatCho' AND DoiTuID='{$bookingId}'"),
        ];
    }

    /** @param list<array<string, mixed>> $inventory */
    private function assertInventoryMatchesLedger(array $inventory): void
    {
        foreach ($inventory as $row) {
            $roomTypeId = (int) $row['LoaiPhongID'];
            $date = (string) $row['NgayLuuTru'];
            self::assertSame(
                $this->scalar("SELECT COUNT(*) FROM GiuTonPhongDem WHERE LoaiPhongID={$roomTypeId} AND NgayLuuTru='{$date}' AND TrangThai='ACTIVE'"),
                $this->scalar("SELECT SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID={$roomTypeId} AND NgayLuuTru='{$date}'"),
            );
        }
    }

    /** @param array<string, mixed> $state */
    private function restore(array $state): void
    {
        $bookingId = (int) $state['booking']['DatChoID'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu='DatCho' AND DoiTuID='{$bookingId}'");
            foreach ($state['audits'] as $row) {
                $this->insert('NhatKyHeThong', $row);
            }
            foreach ($state['inventory'] as $row) {
                $this->update('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $row);
            }
            foreach ($state['holds'] as $row) {
                $this->update('GiuTonPhongDem', ['GiuTonPhongDemID'], $row);
            }
            $this->update('ThanhToan', ['ThanhToanID'], $state['payment']);
            $this->update('DatCho', ['DatChoID'], $state['booking']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
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
}

<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\CancellationFeeService;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class LifecycleRaceAcceptanceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 2))->container()->get(PDO::class);
    }

    public function test_payment_finalize_and_cancel_leave_one_consistent_terminal_result(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='PENDING_PAYMENT' AND dc.ThoiDiemThanhToan='PAY_ONLINE'
               AND tt.TrangThai='PROCESSING'
             ORDER BY dc.DatChoID LIMIT 1",
        );
        $state = $this->snapshot((int) $booking['DatChoID']);
        $clock = new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC'));
        try {
            $this->pdo->prepare(
                "UPDATE DatCho SET NgayNhanPhong='2030-01-10', NgayTraPhong='2030-01-12',
                 HetHanThanhToanLuc='2030-01-02 00:00:00.000000' WHERE DatChoID=?",
            )->execute([(int) $booking['DatChoID']]);
            $this->pdo->prepare(
                "UPDATE GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
                 SET gt.HetHanLuc='2030-01-02 00:00:00.000000' WHERE hm.DatChoID=? AND gt.TrangThai='ACTIVE'",
            )->execute([(int) $booking['DatChoID']]);
            $fee = $this->cancellationFee((string) $booking['MaDatCho'], (int) $booking['KhachHangID'], $clock);
            $results = $this->runWorkers([
                [
                    'action' => 'payment_success', 'bookingId' => (int) $booking['DatChoID'],
                    'requestId' => 'acceptance-finalize-cancel-payment', 'clock' => $clock->format(DATE_ATOM),
                ],
                $this->cancelPayload($booking, $fee, $clock, '10000000-0000-4000-8000-000000000006'),
            ]);

            self::assertTrue($results[0]['success']);
            self::assertContains($this->bookingStatus((int) $booking['DatChoID']), ['CONFIRMED', 'CANCELLED']);
            self::assertSame('PAID', $this->scalar('SELECT TrangThai FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']));
            self::assertLessThanOrEqual(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM NhatKyHeThong WHERE LoaiDoiTu='ThanhToan' AND DoiTuID='" . (int) $booking['DatChoID'] . "' AND HanhDong='BOOKING_REFUND_SUCCEEDED'",
            ));
            $this->assertInventoryMatchesLedger($state['inventory']);
        } finally {
            $this->restore($state);
        }
    }

    public function test_cancel_and_no_show_allow_only_one_state_transition(): void
    {
        $booking = $this->confirmedPayAtProperty();
        $state = $this->snapshot((int) $booking['DatChoID']);
        $checkIn = new DateTimeImmutable('2030-02-10 ' . $booking['checkInTime'], new DateTimeZone($booking['timezone']));
        try {
            $this->pdo->prepare("UPDATE DatCho SET NgayNhanPhong='2030-02-10', NgayTraPhong='2030-02-12' WHERE DatChoID=?")
                ->execute([(int) $booking['DatChoID']]);
            $cancelClock = $checkIn->modify('-1 microsecond');
            $fee = $this->cancellationFee((string) $booking['MaDatCho'], (int) $booking['KhachHangID'], $cancelClock);
            $results = $this->runWorkers([
                $this->cancelPayload($booking, $fee, $cancelClock, '10000000-0000-4000-8000-000000000007'),
                [
                    'action' => 'no_show', 'code' => $booking['MaDatCho'],
                    'organizationId' => (int) $booking['organizationId'], 'actorId' => $this->partnerActor((int) $booking['organizationId']),
                    'requestId' => 'acceptance-cancel-no-show', 'clock' => $checkIn->format(DATE_ATOM),
                ],
            ]);

            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['success'])));
            self::assertContains($this->bookingStatus((int) $booking['DatChoID']), ['CANCELLED', 'NO_SHOW']);
            self::assertSame('0', $this->activeHoldCount((int) $booking['DatChoID']));
            $this->assertInventoryMatchesLedger($state['inventory']);
        } finally {
            $this->restore($state);
        }
    }

    public function test_waiver_approval_and_direct_cancel_release_inventory_once(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED'
               AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY' AND tt.TrangThai='NOT_TRACKED'
               AND NOT EXISTS (
                   SELECT 1 FROM YeuCauHuyMienPhi w
                   WHERE w.DatChoID=dc.DatChoID AND w.TrangThai='PENDING'
               )
             ORDER BY dc.DatChoID LIMIT 1",
        );
        $this->pdo->prepare(
            "INSERT INTO YeuCauHuyMienPhi (DatChoID,NguoiYeuCauID,LyDo,TrangThai,TaoLuc)
             VALUES (?,?,'Nghiệm thu tranh chấp waiver.','PENDING',CURRENT_TIMESTAMP(6))",
        )->execute([(int) $booking['DatChoID'], (int) $booking['KhachHangID']]);
        $waiverId = (int) $this->pdo->lastInsertId();
        $state = $this->snapshot((int) $booking['DatChoID'], $waiverId);
        $clock = new DateTimeImmutable('2030-03-01 00:00:00', new DateTimeZone('UTC'));
        try {
            $this->pdo->prepare("UPDATE DatCho SET NgayNhanPhong='2030-03-10', NgayTraPhong='2030-03-12' WHERE DatChoID=?")
                ->execute([(int) $booking['DatChoID']]);
            $fee = $this->cancellationFee((string) $booking['MaDatCho'], (int) $booking['KhachHangID'], $clock);
            $results = $this->runWorkers([
                $this->cancelPayload($booking, $fee, $clock, '10000000-0000-4000-8000-000000000008'),
                [
                    'action' => 'waiver_approve', 'waiverId' => $waiverId,
                    'organizationId' => (int) $booking['organizationId'], 'actorId' => $this->partnerActor((int) $booking['organizationId']),
                    'requestId' => 'acceptance-waiver-cancel', 'clock' => $clock->format(DATE_ATOM),
                ],
            ]);

            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['success'])));
            self::assertSame('CANCELLED', $this->bookingStatus((int) $booking['DatChoID']));
            self::assertSame('0', $this->activeHoldCount((int) $booking['DatChoID']));
            $this->assertInventoryMatchesLedger($state['inventory']);
        } finally {
            $this->restore($state);
            $this->pdo->prepare("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu='YeuCauHuyMienPhi' AND DoiTuID=?")
                ->execute([(string) $waiverId]);
            $this->pdo->prepare('DELETE FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=?')->execute([$waiverId]);
        }
    }

    public function test_completion_and_no_show_allow_only_one_terminal_state(): void
    {
        $booking = $this->confirmedPayAtProperty();
        $state = $this->snapshot((int) $booking['DatChoID']);
        $clock = new DateTimeImmutable('2000-01-02 ' . $booking['checkOutTime'], new DateTimeZone($booking['timezone']));
        $clock = $clock->modify('+1 second');
        try {
            $this->pdo->prepare("UPDATE DatCho SET NgayNhanPhong='2000-01-01', NgayTraPhong='2000-01-02' WHERE DatChoID=?")
                ->execute([(int) $booking['DatChoID']]);
            $results = $this->runWorkers([
                ['action' => 'complete', 'clock' => $clock->format(DATE_ATOM)],
                [
                    'action' => 'no_show', 'code' => $booking['MaDatCho'],
                    'organizationId' => (int) $booking['organizationId'], 'actorId' => $this->partnerActor((int) $booking['organizationId']),
                    'requestId' => 'acceptance-completion-no-show', 'clock' => $clock->format(DATE_ATOM),
                ],
            ]);

            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['success'])));
            self::assertContains($this->bookingStatus((int) $booking['DatChoID']), ['COMPLETED', 'NO_SHOW']);
            self::assertSame('0', $this->activeHoldCount((int) $booking['DatChoID']));
            $this->assertInventoryMatchesLedger($state['inventory']);
        } finally {
            $this->restore($state);
        }
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function runWorkers(array $jobs): array
    {
        $startAt = microtime(true) + 0.25;
        $worker = dirname(__DIR__) . '/Support/LifecycleRaceWorker.php';
        $processes = [];
        foreach ($jobs as $job) {
            $job['startAt'] = $startAt;
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
                . escapeshellarg(base64_encode(json_encode($job, JSON_THROW_ON_ERROR)));
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (!is_resource($process)) {
                throw new RuntimeException('Không thể chạy worker tranh chấp lifecycle.');
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
                throw new RuntimeException('Worker lifecycle lỗi: ' . trim($stderr));
            }
            $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $results[] = $result;
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function confirmedPayAtProperty(): array
    {
        return $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime,
                    cs.GioTraPhong checkOutTime, cs.MuiGio timezone
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.DatChoID LIMIT 1",
        );
    }

    private function cancellationFee(string $code, int $customerId, DateTimeImmutable $clock): Money
    {
        $context = (new CancellationRepository($this->pdo))->findForCustomer($code, $customerId);
        self::assertIsArray($context);
        return App::boot(dirname(__DIR__, 2))->container()->get(CancellationFeeService::class)->calculate($context, $clock)->fee;
    }

    /** @param array<string, mixed> $booking @return array<string, mixed> */
    private function cancelPayload(array $booking, Money $fee, DateTimeImmutable $clock, string $key): array
    {
        return [
            'action' => 'cancel', 'code' => $booking['MaDatCho'], 'customerId' => (int) $booking['KhachHangID'],
            'key' => $key, 'expectedFee' => $fee->decimal(), 'clock' => $clock->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(int $bookingId, ?int $waiverId = null): array
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
        $auditWhere = "(DoiTuID='{$bookingId}' AND LoaiDoiTu IN ('DatCho','ThanhToan'))";
        if ($waiverId !== null) {
            $auditWhere .= " OR (DoiTuID='{$waiverId}' AND LoaiDoiTu='YeuCauHuyMienPhi')";
        }
        return [
            'booking' => $this->row("SELECT * FROM DatCho WHERE DatChoID={$bookingId}"),
            'payment' => $this->row("SELECT * FROM ThanhToan WHERE DatChoID={$bookingId}"),
            'holds' => $holds,
            'inventory' => array_values($inventory),
            'waiver' => $waiverId === null ? null : $this->row("SELECT * FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID={$waiverId}"),
            'audits' => $this->rows("SELECT * FROM NhatKyHeThong WHERE {$auditWhere}"),
            'auditWhere' => $auditWhere,
        ];
    }

    /** @param array<string, mixed> $state */
    private function restore(array $state): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE ' . $state['auditWhere']);
            foreach ($state['audits'] as $row) {
                $this->insert('NhatKyHeThong', $row);
            }
            foreach ($state['inventory'] as $row) {
                $this->update('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $row);
            }
            foreach ($state['holds'] as $row) {
                $this->update('GiuTonPhongDem', ['GiuTonPhongDemID'], $row);
            }
            if (is_array($state['waiver'])) {
                $this->update('YeuCauHuyMienPhi', ['YeuCauHuyMienPhiID'], $state['waiver']);
            }
            $this->update('ThanhToan', ['ThanhToanID'], $state['payment']);
            $this->update('DatCho', ['DatChoID'], $state['booking']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
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

    private function partnerActor(int $organizationId): int
    {
        return (int) $this->scalar("SELECT NguoiDungID FROM ThanhVienDoiTac WHERE ToChucDoiTacID={$organizationId} ORDER BY NguoiDungID LIMIT 1");
    }

    private function bookingStatus(int $bookingId): string
    {
        return $this->scalar("SELECT TrangThai FROM DatCho WHERE DatChoID={$bookingId}");
    }

    private function activeHoldCount(int $bookingId): string
    {
        return $this->scalar(
            "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID={$bookingId} AND gt.TrangThai='ACTIVE'",
        );
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

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Payments;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CheckInService;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Payments\Application\AtPropertyPaymentRequest;
use App\Modules\Payments\Application\RecordAtPropertyPaymentService;
use App\Modules\Payments\Infrastructure\PaymentTransactionRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecordAtPropertyPaymentServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_records_full_property_payment_once_after_check_in(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime,
                    cs.MuiGio timezone, tv.NguoiDungID actorId
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             INNER JOIN ThanhVienDoiTac tv ON tv.ToChucDoiTacID=cs.ToChucDoiTacID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        );
        $payment = $this->row('SELECT * FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']);
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'],
            new DateTimeZone((string) $booking['timezone']),
        ));
        $checkInKey = 'payment-check-in-' . bin2hex(random_bytes(5));
        $paymentKey = '00000000-0000-4000-8000-' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
        $transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $audit = new DatabaseAuditTrail($this->pdo);
        $lifecycle = new BookingLifecycleRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);

        try {
            (new CheckInService($lifecycle, $payments, $transactions, $audit, $clock))->checkIn(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $checkInKey,
                '127.0.0.1',
            );
            $service = new RecordAtPropertyPaymentService(
                $lifecycle,
                $payments,
                new PaymentTransactionRepository($this->pdo),
                $transactions,
                $audit,
                $clock,
            );
            $request = new AtPropertyPaymentRequest($paymentKey, 'CASH', null);
            $first = $service->record(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $request,
                '127.0.0.1',
            );
            $second = $service->record(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $request,
                '127.0.0.1',
            );

            self::assertFalse($first->replayed);
            self::assertTrue($second->replayed);
            self::assertSame($first->transactionId, $second->transactionId);
            self::assertSame((string) $payment['TongPhaiTra'], $first->amount->decimal());
            $current = $this->row('SELECT * FROM ThanhToan WHERE ThanhToanID=' . (int) $payment['ThanhToanID']);
            self::assertSame('PAID', $current['TrangThai']);
            self::assertSame((string) $current['TongPhaiTra'], (string) $current['DaThanhToan']);
            self::assertSame($paymentKey, $current['IdempotencyKey']);
            self::assertSame('1', (string) $this->pdo->query(
                'SELECT COUNT(*) FROM GiaoDichThanhToan WHERE ThanhToanID=' . (int) $payment['ThanhToanID']
            )->fetchColumn());
            self::assertSame('1', (string) $this->pdo->query(
                'SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID=' . $this->pdo->quote($paymentKey)
            )->fetchColumn());

            try {
                $service->record(
                    (string) $booking['MaDatCho'],
                    (int) $booking['organizationId'],
                    (int) $booking['actorId'],
                    new AtPropertyPaymentRequest($paymentKey, 'BANK_TRANSFER', 'DIFFERENT'),
                    '127.0.0.1',
                );
                self::fail('Không được replay cùng key với payload khác.');
            } catch (BookingException $exception) {
                self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
            }
        } finally {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID IN (?,?)')->execute([$checkInKey, $paymentKey]);
                $this->pdo->prepare('DELETE FROM GiaoDichThanhToan WHERE ThanhToanID=?')->execute([(int) $payment['ThanhToanID']]);
                $this->restore('ThanhToan', 'ThanhToanID', $payment);
                $this->restoreBooking($booking);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @param array<string, mixed> $booking */
    private function restoreBooking(array $booking): void
    {
        $row = array_filter($booking, static fn (string $key): bool => !in_array(
            $key,
            ['organizationId', 'checkInTime', 'timezone', 'actorId'],
            true,
        ), ARRAY_FILTER_USE_KEY);
        $this->restore('DatCho', 'DatChoID', $row);
    }

    /** @param array<string, mixed> $row */
    private function restore(string $table, string $key, array $row): void
    {
        $columns = array_keys($row);
        $set = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", $columns));
        $values = [...array_values($row), $row[$key]];
        $this->pdo->prepare("UPDATE `{$table}` SET {$set} WHERE `{$key}`=?")->execute($values);
    }
}

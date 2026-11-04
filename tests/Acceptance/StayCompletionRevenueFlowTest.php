<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use App\Bootstrap\App;
use App\Config\AppConfig;
use App\Config\MailConfig;
use App\Modules\Bookings\Application\CheckInService;
use App\Modules\Bookings\Application\CheckoutService;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Notifications\Application\DispatchNotificationsService;
use App\Modules\Notifications\Application\QueueInvoiceNotificationService;
use App\Modules\Notifications\Infrastructure\FileMailer;
use App\Modules\Notifications\Infrastructure\NotificationRepository;
use App\Modules\Payments\Application\AtPropertyPaymentRequest;
use App\Modules\Payments\Application\IssueInvoiceService;
use App\Modules\Payments\Application\RecordAtPropertyPaymentService;
use App\Modules\Payments\Infrastructure\InvoiceRepository;
use App\Modules\Payments\Infrastructure\PaymentTransactionRepository;
use App\Modules\Reports\Application\RevenueSettlementRequest;
use App\Modules\Reports\Application\RevenueSettlementService;
use App\Modules\Reports\Infrastructure\RevenueSettlementRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class StayCompletionRevenueFlowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = App::boot(dirname(__DIR__, 2))->container()->get(PDO::class);
    }

    public function test_full_pay_at_property_stay_invoice_email_and_settlement_flow(): void
    {
        $booking = $this->row(
            "SELECT b.*, c.ToChucDoiTacID organizationId, c.GioNhanPhong checkInTime,
                    c.MuiGio timezone, m.NguoiDungID actorId
             FROM DatCho b
             INNER JOIN CoSoLuuTru c ON c.CoSoLuuTruID=b.CoSoLuuTruID
             INNER JOIN ThanhToan p ON p.DatChoID=b.DatChoID
             INNER JOIN ThanhVienDoiTac m ON m.ToChucDoiTacID=c.ToChucDoiTacID
             WHERE b.TrangThai='CONFIRMED' AND b.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND p.TrangThai='NOT_TRACKED'
             ORDER BY b.NgayNhanPhong DESC, b.DatChoID DESC LIMIT 1"
        );
        $payment = $this->row('SELECT * FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']);
        $state = $this->snapshot((int) $booking['DatChoID']);
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'],
            new DateTimeZone((string) $booking['timezone']),
        ));
        $keys = [
            'checkIn' => 'acceptance-checkin-' . bin2hex(random_bytes(4)),
            'payment' => $this->uuid(),
            'checkout' => $this->uuid(),
            'settlement' => 'acceptance-settlement-' . bin2hex(random_bytes(4)),
            'finalize' => 'acceptance-finalize-' . bin2hex(random_bytes(4)),
        ];
        $mailDirectory = dirname(__DIR__, 2) . '/storage/tmp/acceptance-mail-' . bin2hex(random_bytes(4));
        $invoiceId = 0;
        $settlementId = 0;
        $transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $audit = new DatabaseAuditTrail($this->pdo);
        $lifecycle = new BookingLifecycleRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);
        $notifications = new NotificationRepository($this->pdo);
        $invoices = new InvoiceRepository($this->pdo);
        $inventory = new TransactionalInventoryReservationService(
            $this->pdo,
            new InventoryRepository($this->pdo),
            $clock,
        );

        try {
            (new CheckInService($lifecycle, $payments, $transactions, $audit, $clock))->checkIn(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $keys['checkIn'],
                '127.0.0.1',
            );
            self::assertSame('CHECKED_IN', $this->scalar(
                'SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']
            ));

            $collection = (new RecordAtPropertyPaymentService(
                $lifecycle,
                $payments,
                new PaymentTransactionRepository($this->pdo),
                $transactions,
                $audit,
                $clock,
            ))->record(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                new AtPropertyPaymentRequest($keys['payment'], 'BANK_TRANSFER', 'ACCEPTANCE-TRANSFER-001'),
                '127.0.0.1',
            );
            self::assertSame('BANK_TRANSFER', $collection->method);
            self::assertSame('PAID', $this->scalar(
                'SELECT TrangThai FROM ThanhToan WHERE ThanhToanID=' . (int) $payment['ThanhToanID']
            ));

            $clock->advance(new DateInterval('PT1H'));
            $invoice = (new CheckoutService(
                $lifecycle,
                $payments,
                $inventory,
                new IssueInvoiceService(
                    $this->pdo,
                    $invoices,
                    new QueueInvoiceNotificationService($notifications, AppConfig::fromEnv()),
                ),
                $invoices,
                $transactions,
                $audit,
                $clock,
            ))->checkout(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $keys['checkout'],
                '127.0.0.1',
            );
            $invoiceId = $invoice->id;
            self::assertSame('COMPLETED', $this->scalar(
                'SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']
            ));
            self::assertSame('PENDING', $notifications->findByInvoiceId($invoiceId)?->status);

            $dispatch = (new DispatchNotificationsService(
                $notifications,
                new FileMailer(new MailConfig('file', 'no-reply@staybooking.local', 'StayBooking'), $mailDirectory),
                $transactions,
                $clock,
            ))->dispatch(10);
            self::assertSame(['sent' => 1, 'failed' => 0], $dispatch);
            self::assertSame('SENT', $notifications->findByInvoiceId($invoiceId)?->status);

            $settlementService = new RevenueSettlementService(
                new RevenueSettlementRepository($this->pdo),
                $transactions,
                $audit,
                $clock,
            );
            $date = $invoice->issuedAt->format('Y-m-d');
            $draft = $settlementService->generate(
                new RevenueSettlementRequest((int) $booking['CoSoLuuTruID'], $date, $date),
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $keys['settlement'],
                '127.0.0.1',
            );
            $settlementId = $draft->id;
            self::assertSame(1, $draft->bookingCount);
            self::assertSame('0.00', $draft->onlineCollected->decimal());
            self::assertSame($invoice->total->decimal(), $draft->atPropertyCollected->decimal());
            self::assertSame('BANK_TRANSFER', $draft->lines[0]['collectionChannel']);

            $finalized = $settlementService->finalize(
                $settlementId,
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $keys['finalize'],
                '127.0.0.1',
            );
            self::assertSame('FINALIZED', $finalized->status);
            self::assertTrue($invoice->total->equals($finalized->netRevenue));
            self::assertSame('0', $this->scalar(
                "SELECT COUNT(*) FROM GiuTonPhongDem l
                 INNER JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
                 WHERE i.DatChoID=" . (int) $booking['DatChoID'] . " AND l.TrangThai='ACTIVE'"
            ));
        } finally {
            $this->restore($state, $payment, $invoiceId, $settlementId, array_values($keys));
            foreach (glob($mailDirectory . '/*.eml') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($mailDirectory)) {
                rmdir($mailDirectory);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(int $bookingId): array
    {
        $holds = $this->rows(
            'SELECT l.* FROM GiuTonPhongDem l INNER JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
             WHERE i.DatChoID=' . $bookingId
        );
        $inventory = [];
        foreach ($holds as $hold) {
            $key = $hold['LoaiPhongID'] . ':' . $hold['NgayLuuTru'];
            $inventory[$key] = $this->row(
                "SELECT * FROM TonPhongNgay WHERE LoaiPhongID={$hold['LoaiPhongID']}
                 AND NgayLuuTru='{$hold['NgayLuuTru']}'"
            );
        }
        return ['booking' => $this->row('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId),
            'holds' => $holds, 'inventory' => array_values($inventory)];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $payment @param list<string> $auditKeys */
    private function restore(array $state, array $payment, int $invoiceId, int $settlementId, array $auditKeys): void
    {
        $bookingId = (int) $state['booking']['DatChoID'];
        $this->pdo->beginTransaction();
        try {
            foreach ($auditKeys as $key) {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$key]);
            }
            if ($settlementId > 0) {
                $this->pdo->prepare('DELETE FROM ChiTietDoiSoat WHERE KyDoiSoatID=?')->execute([$settlementId]);
                $this->pdo->prepare('DELETE FROM KyDoiSoat WHERE KyDoiSoatID=?')->execute([$settlementId]);
            }
            if ($invoiceId > 0) {
                $this->pdo->prepare('DELETE FROM HangDoiThongBao WHERE HoaDonID=?')->execute([$invoiceId]);
                $this->pdo->prepare('DELETE FROM ChiTietHoaDon WHERE HoaDonID=?')->execute([$invoiceId]);
                $this->pdo->prepare('DELETE FROM HoaDon WHERE HoaDonID=?')->execute([$invoiceId]);
            }
            $this->pdo->prepare('DELETE FROM GiaoDichThanhToan WHERE ThanhToanID=?')
                ->execute([(int) $payment['ThanhToanID']]);
            $this->update('ThanhToan', ['ThanhToanID'], $payment);
            $this->pdo->prepare(
                'DELETE l FROM GiuTonPhongDem l INNER JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
                 WHERE i.DatChoID=?'
            )->execute([$bookingId]);
            foreach ($state['holds'] as $hold) {
                $this->insert('GiuTonPhongDem', $hold);
            }
            foreach ($state['inventory'] as $inventory) {
                $this->update('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $inventory);
            }
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

    private function uuid(): string
    {
        return '00000000-0000-4000-8000-' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
    }
}

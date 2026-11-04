<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Payments;

use App\Bootstrap\App;
use App\Config\AppConfig;
use App\Modules\Bookings\Application\CheckInService;
use App\Modules\Bookings\Application\CheckoutService;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Notifications\Application\DispatchNotificationsService;
use App\Modules\Notifications\Application\Mailer;
use App\Modules\Notifications\Application\QueueInvoiceNotificationService;
use App\Modules\Notifications\Domain\MailMessage;
use App\Modules\Notifications\Infrastructure\FileMailer;
use App\Modules\Notifications\Infrastructure\NotificationRepository;
use App\Config\MailConfig;
use App\Modules\Payments\Application\IssueInvoiceService;
use App\Modules\Payments\Infrastructure\InvoiceRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CheckoutInvoiceServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_checkout_releases_inventory_and_issues_one_authorized_invoice(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime,
                    cs.MuiGio timezone,
                    (SELECT MIN(tv.NguoiDungID) FROM ThanhVienDoiTac tv
                     WHERE tv.ToChucDoiTacID=cs.ToChucDoiTacID) actorId
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_ONLINE'
               AND tt.TrangThai='PAID' AND tt.DaHoan=0
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        );
        $state = $this->snapshot((int) $booking['DatChoID']);
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'],
            new DateTimeZone((string) $booking['timezone']),
        ));
        $checkInKey = 'invoice-check-in-' . bin2hex(random_bytes(5));
        $checkoutKey = '00000000-0000-4000-8000-' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
        $transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $audit = new DatabaseAuditTrail($this->pdo);
        $lifecycle = new BookingLifecycleRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);
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
                $checkInKey,
                '127.0.0.1',
            );
            $clock->freeze($clock->now()->modify('+1 hour'));
            $service = new CheckoutService(
                $lifecycle,
                $payments,
                $inventory,
                new IssueInvoiceService(
                    $this->pdo,
                    $invoices,
                    new QueueInvoiceNotificationService(
                        new NotificationRepository($this->pdo),
                        AppConfig::fromEnv(),
                    ),
                ),
                $invoices,
                $transactions,
                $audit,
                $clock,
            );
            $first = $service->checkout(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $checkoutKey,
                '127.0.0.1',
            );
            $second = $service->checkout(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $checkoutKey,
                '127.0.0.1',
            );

            self::assertSame($first->id, $second->id);
            self::assertSame('COMPLETED', (string) $this->pdo->query(
                'SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']
            )->fetchColumn());
            self::assertSame('0', (string) $this->pdo->query(
                "SELECT COUNT(*) FROM GiuTonPhongDem gt
                 INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
                 WHERE hm.DatChoID=" . (int) $booking['DatChoID'] . " AND gt.TrangThai='ACTIVE'"
            )->fetchColumn());
            self::assertSame((string) $booking['TongTien'], $first->total->decimal());
            $lineTotal = Money::vnd(0);
            foreach ($first->lines as $line) {
                $lineTotal = $lineTotal->add(Money::vnd((string) $line['lineTotal']));
            }
            self::assertTrue($first->total->equals($lineTotal));
            self::assertNotNull($invoices->findForCustomer((string) $booking['MaDatCho'], (int) $booking['KhachHangID']));
            self::assertNull($invoices->findForCustomer((string) $booking['MaDatCho'], (int) $booking['KhachHangID'] + 999));
            self::assertNotNull($invoices->findForOrganization((string) $booking['MaDatCho'], (int) $booking['organizationId']));
            self::assertNull($invoices->findForOrganization((string) $booking['MaDatCho'], (int) $booking['organizationId'] + 999));
            self::assertSame('1', (string) $this->pdo->query(
                'SELECT COUNT(*) FROM HoaDon WHERE DatChoID=' . (int) $booking['DatChoID']
            )->fetchColumn());
            $notificationRepository = new NotificationRepository($this->pdo);
            $notification = $notificationRepository->findByInvoiceId($first->id);
            self::assertNotNull($notification);
            self::assertSame('PENDING', $notification->status);

            $failingMailer = new class implements Mailer {
                public function send(MailMessage $message): void
                {
                    throw new \RuntimeException('SMTP tạm thời không khả dụng.');
                }
            };
            $dispatcher = new DispatchNotificationsService(
                $notificationRepository,
                $failingMailer,
                $transactions,
                $clock,
            );
            self::assertSame(['sent' => 0, 'failed' => 1], $dispatcher->dispatch(1));
            $failed = $notificationRepository->findByInvoiceId($first->id);
            self::assertNotNull($failed);
            self::assertSame('FAILED', $failed->status);
            self::assertSame(1, $failed->attempts);

            $mailDirectory = dirname(__DIR__, 4) . '/storage/tmp/staybooking-mail-' . bin2hex(random_bytes(4));
            $clock->advance(new \DateInterval('PT1M'));
            $dispatcher = new DispatchNotificationsService(
                $notificationRepository,
                new FileMailer(new MailConfig('file', 'no-reply@staybooking.local', 'StayBooking'), $mailDirectory),
                $transactions,
                $clock,
            );
            $secondDispatch = $dispatcher->dispatch(1);
            $lastError = (string) $this->pdo->query(
                'SELECT COALESCE(LoiCuoi, \'\') FROM HangDoiThongBao WHERE HoaDonID=' . $first->id
            )->fetchColumn();
            self::assertSame(['sent' => 1, 'failed' => 0], $secondDispatch, $lastError);
            self::assertSame(['sent' => 0, 'failed' => 0], $dispatcher->dispatch(1));
            $sent = $notificationRepository->findByInvoiceId($first->id);
            self::assertNotNull($sent);
            self::assertSame('SENT', $sent->status);
            self::assertSame(2, $sent->attempts);
            $mailFiles = glob($mailDirectory . '/*.eml') ?: [];
            self::assertCount(1, $mailFiles);
            self::assertStringContainsString($first->number, (string) file_get_contents($mailFiles[0]));
            unlink($mailFiles[0]);
            rmdir($mailDirectory);
        } finally {
            $this->restore($state, [$checkInKey, $checkoutKey]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(int $bookingId): array
    {
        $booking = $this->row('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId);
        $holds = $this->rows(
            'SELECT gt.* FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID=' . $bookingId
        );
        $inventory = [];
        foreach ($holds as $hold) {
            $key = $hold['LoaiPhongID'] . ':' . $hold['NgayLuuTru'];
            $inventory[$key] = $this->row(
                "SELECT * FROM TonPhongNgay WHERE LoaiPhongID={$hold['LoaiPhongID']}
                 AND NgayLuuTru='{$hold['NgayLuuTru']}'"
            );
        }
        return ['booking' => $booking, 'holds' => $holds, 'inventory' => array_values($inventory)];
    }

    /** @param array<string, mixed> $state @param list<string> $auditKeys */
    private function restore(array $state, array $auditKeys): void
    {
        $bookingId = (int) $state['booking']['DatChoID'];
        $this->pdo->beginTransaction();
        try {
            foreach ($auditKeys as $key) {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$key]);
            }
            $this->pdo->prepare('DELETE q FROM HangDoiThongBao q INNER JOIN HoaDon hd ON hd.HoaDonID=q.HoaDonID WHERE hd.DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE ct FROM ChiTietHoaDon ct INNER JOIN HoaDon hd ON hd.HoaDonID=ct.HoaDonID WHERE hd.DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE FROM HoaDon WHERE DatChoID=?')->execute([$bookingId]);
            $this->pdo->prepare('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=?')->execute([$bookingId]);
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

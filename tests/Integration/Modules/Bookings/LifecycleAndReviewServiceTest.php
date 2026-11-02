<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CancellationCommand;
use App\Modules\Bookings\Application\CancellationService;
use App\Modules\Bookings\Application\CompletionService;
use App\Modules\Bookings\Application\NoShowRefundService;
use App\Modules\Bookings\Application\NoShowService;
use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PaymentGatewayResult;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\MockPaymentGateway;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Commercial\Application\NoShowFeeCalculator;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Reviews\Application\ReviewRequest;
use App\Modules\Reviews\Application\ReviewService;
use App\Modules\Reviews\Infrastructure\ReviewRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class LifecycleAndReviewServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_partner_no_show_releases_inventory_and_is_idempotent(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        );
        $state = $this->snapshotLifecycle((int) $booking['DatChoID']);
        $requestId = 'phase14-noshow-' . bin2hex(random_bytes(5));
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'], new DateTimeZone($booking['timezone']),
        ));
        try {
            $this->pdo->prepare(
                "UPDATE HangMucDatCho SET SnapshotHuyJSON=JSON_SET(SnapshotHuyJSON, '$.noShowPenaltyRate', '100') WHERE DatChoID=?"
            )->execute([(int) $booking['DatChoID']]);
            $service = $this->noShowService($clock);
            try {
                $service->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'] + 999, 2,
                    'phase14-wrong-tenant', '127.0.0.1');
                self::fail('Partner khác tổ chức không được đánh dấu no-show.');
            } catch (BookingException $exception) {
                self::assertSame('BOOKING_NOT_FOUND', $exception->errorCode);
            }
            $lateService = $this->noShowService(new FrozenClock($clock->now()->modify('+2 days')));
            try {
                $lateService->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                    'phase14-late-window', '127.0.0.1');
                self::fail('Không được đánh dấu no-show sau cửa sổ cho phép.');
            } catch (BookingException $exception) {
                self::assertSame('NO_SHOW_WINDOW_INVALID', $exception->errorCode);
            }
            $first = $service->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                $requestId, '127.0.0.1');
            $second = $service->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                $requestId, '127.0.0.1');
            self::assertTrue($first['fee']->equals($second['fee']));
            self::assertSame('NO_SHOW', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']));
            self::assertSame('0', $this->scalar("SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=" . (int) $booking['DatChoID'] . " AND gt.TrangThai='ACTIVE'"));
            self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='" . $requestId . "'"));
            try {
                App::boot(dirname(__DIR__, 4))->container()->get(CancellationService::class)->cancel(
                    (string) $booking['MaDatCho'], (int) $booking['KhachHangID'],
                    new CancellationCommand('00000000-0000-4000-8000-000000014014', 'Kiểm tra tranh chấp trạng thái.', Money::vnd(0)),
                    '127.0.0.1',
                );
                self::fail('Booking no-show không được chuyển tiếp sang cancelled.');
            } catch (BookingException $exception) {
                self::assertSame('BOOKING_NOT_CANCELLABLE', $exception->errorCode);
            }
            self::assertNotContains((int) $booking['DatChoID'], array_column(
                (new BookingLifecycleRepository($this->pdo))->completionCandidates(1_000), 'id'
            ));
        } finally {
            $this->restoreLifecycle($state, [$requestId, 'phase14-wrong-tenant', 'phase14-late-window']);
        }
    }

    public function test_online_no_show_refunds_paid_amount_above_fee_once_and_cannot_be_reviewed(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             LEFT JOIN DanhGia dg ON dg.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_ONLINE'
               AND tt.TrangThai='PAID' AND tt.TrangThaiHoanTien='NONE' AND dg.DanhGiaID IS NULL
             ORDER BY dc.DatChoID DESC LIMIT 1"
        );
        $state = $this->snapshotLifecycle((int) $booking['DatChoID']);
        $requestId = 'phase14-online-' . bin2hex(random_bytes(5));
        $failedRefundAuditId = 'no-show-refund:' . $requestId . ':failed';
        $refundAuditId = 'no-show-refund:' . $requestId . ':succeeded';
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'], new DateTimeZone($booking['timezone']),
        ));
        try {
            $this->pdo->prepare(
                "UPDATE HangMucDatCho SET SnapshotHuyJSON=JSON_SET(SnapshotHuyJSON, '$.noShowPenaltyRate', '25') WHERE DatChoID=?"
            )->execute([(int) $booking['DatChoID']]);
            $failedService = $this->noShowService($clock, new MockPaymentGateway(PaymentGatewayResult::FAILED));
            $first = $failedService->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                $requestId, '127.0.0.1');
            self::assertSame('FAILED', $this->scalar(
                'SELECT TrangThaiHoanTien FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']
            ));
            self::assertSame('0.00', $this->scalar(
                'SELECT DaHoan FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']
            ));

            $service = $this->noShowService($clock);
            $second = $service->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                $requestId, '127.0.0.1');
            $third = $service->mark((string) $booking['MaDatCho'], (int) $booking['organizationId'], 2,
                $requestId, '127.0.0.1');
            self::assertTrue($first['expectedRefund']->equals($second['expectedRefund']));
            self::assertTrue($second['expectedRefund']->equals($third['expectedRefund']));
            self::assertFalse($first['expectedRefund']->isZero());
            self::assertSame($first['expectedRefund']->decimal(), $this->scalar(
                'SELECT DaHoan FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']
            ));
            self::assertSame('SUCCEEDED', $this->scalar(
                'SELECT TrangThaiHoanTien FROM ThanhToan WHERE DatChoID=' . (int) $booking['DatChoID']
            ));
            self::assertSame('0.00', $this->scalar(
                'SELECT SoTienHoan FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']
            ));
            self::assertSame('1', $this->scalar(
                "SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='" . $refundAuditId . "'"
            ));

            $reviews = new ReviewService(new ReviewRepository($this->pdo), $this->transactions(),
                new DatabaseAuditTrail($this->pdo), $clock);
            try {
                $reviews->create((string) $booking['MaDatCho'], (int) $booking['KhachHangID'],
                    ReviewRequest::fromInput(['overall' => 8]), 'phase14-review-denied', '127.0.0.1');
                self::fail('Booking no-show không được tạo review.');
            } catch (BookingException $exception) {
                self::assertSame('REVIEW_NOT_ALLOWED', $exception->errorCode);
            }
        } finally {
            $this->restoreLifecycle($state, [$requestId, $failedRefundAuditId, $refundAuditId, 'phase14-review-denied']);
        }
    }

    public function test_completion_batch_releases_inventory_and_running_again_is_safe(): void
    {
        $bookings = $this->rows(
            "SELECT dc.*, cs.GioTraPhong checkOutTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CONFIRMED' ORDER BY dc.NgayTraPhong, dc.DatChoID LIMIT 2"
        );
        self::assertCount(2, $bookings);
        $states = array_map(fn (array $booking): array => $this->snapshotLifecycle((int) $booking['DatChoID']), $bookings);
        $cutoffs = array_map(static fn (array $booking): DateTimeImmutable => new DateTimeImmutable(
            $booking['NgayTraPhong'] . ' ' . $booking['checkOutTime'], new DateTimeZone($booking['timezone']),
        ), $bookings);
        usort($cutoffs, static fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);
        $clock = new FrozenClock($cutoffs[1]->modify('+1 second'));
        try {
            $service = new CompletionService(
                new BookingLifecycleRepository($this->pdo), $this->inventory($clock),
                $this->transactions(), new DatabaseAuditTrail($this->pdo), $clock,
            );
            self::assertSame(1, $service->completeDue(1));
            self::assertSame(1, $service->completeDue(1));
            foreach ($bookings as $booking) {
                $bookingId = (int) $booking['DatChoID'];
                self::assertSame('COMPLETED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . $bookingId));
                self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='completion:{$bookingId}'"));
            }
        } finally {
            foreach ($states as $state) {
                $this->restoreLifecycle($state, ['completion:' . $state['booking']['DatChoID']]);
            }
        }
    }

    public function test_completed_customer_can_review_once_and_admin_only_changes_visibility(): void
    {
        $booking = $this->row(
            "SELECT dc.DatChoID id, dc.MaDatCho code, dc.KhachHangID customerId,
                    dc.CoSoLuuTruID propertyId,
                    dc.NgayTraPhong checkOut, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             LEFT JOIN DanhGia dg ON dg.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dg.DanhGiaID IS NULL
             ORDER BY dc.DatChoID LIMIT 1"
        );
        $bookingState = $this->row('SELECT * FROM DatCho WHERE DatChoID=' . (int) $booking['id']);
        $clock = new FrozenClock((new DateTimeImmutable($booking['checkOut'] . ' 12:00:00', new DateTimeZone($booking['timezone'])))->modify('+1 day'));
        $createId = 'phase14-review-' . bin2hex(random_bytes(5));
        $moderateId = 'phase14-moderate-' . bin2hex(random_bytes(5));
        $service = new ReviewService(new ReviewRepository($this->pdo), $this->transactions(), new DatabaseAuditTrail($this->pdo), $clock);
        $reviewId = 0;
        $publishedBefore = $this->scalar("SELECT COUNT(*) FROM DanhGia WHERE CoSoLuuTruID="
            . (int) $booking['propertyId'] . " AND TrangThai='PUBLISHED'");
        try {
            $this->pdo->prepare("UPDATE DatCho SET TrangThai='COMPLETED', HoanThanhLuc=? WHERE DatChoID=?")
                ->execute([$clock->now()->format('Y-m-d H:i:s.u'), (int) $booking['id']]);
            try {
                $service->create((string) $booking['code'], (int) $booking['customerId'] + 999,
                    ReviewRequest::fromInput(['overall' => 9]), 'phase14-wrong-customer', '127.0.0.1');
                self::fail('Khách khác không được đánh giá booking.');
            } catch (BookingException $exception) {
                self::assertSame('BOOKING_NOT_FOUND', $exception->errorCode);
            }
            $lateReviewService = new ReviewService(new ReviewRepository($this->pdo), $this->transactions(),
                new DatabaseAuditTrail($this->pdo), new FrozenClock($clock->now()->modify('+91 days')));
            try {
                $lateReviewService->create((string) $booking['code'], (int) $booking['customerId'],
                    ReviewRequest::fromInput(['overall' => 9]), 'phase14-late-review', '127.0.0.1');
                self::fail('Không được đánh giá sau cửa sổ 90 ngày.');
            } catch (BookingException $exception) {
                self::assertSame('REVIEW_WINDOW_INVALID', $exception->errorCode);
            }
            $reviewId = $service->create((string) $booking['code'], (int) $booking['customerId'],
                ReviewRequest::fromInput(['overall' => 9, 'cleanliness' => 8, 'comment' => 'Kỳ nghỉ tốt.']),
                $createId, '127.0.0.1');
            self::assertGreaterThan(0, $reviewId);
            self::assertSame((string) ((int) $publishedBefore + 1), $this->scalar(
                "SELECT COUNT(*) FROM DanhGia WHERE CoSoLuuTruID=" . (int) $booking['propertyId'] . " AND TrangThai='PUBLISHED'"
            ));
            self::assertSame($reviewId, $service->create((string) $booking['code'], (int) $booking['customerId'],
                ReviewRequest::fromInput(['overall' => 9, 'cleanliness' => 8, 'comment' => 'Kỳ nghỉ tốt.']),
                $createId, '127.0.0.1'));
            try {
                $service->create((string) $booking['code'], (int) $booking['customerId'],
                    ReviewRequest::fromInput(['overall' => 7]), 'phase14-second-review', '127.0.0.1');
                self::fail('Một booking không được có review thứ hai.');
            } catch (BookingException $exception) {
                self::assertSame('REVIEW_ALREADY_EXISTS', $exception->errorCode);
            }
            $service->moderate($reviewId, 'HIDDEN', 1, $moderateId, '127.0.0.1');
            self::assertSame('HIDDEN', $this->scalar('SELECT TrangThai FROM DanhGia WHERE DanhGiaID=' . $reviewId));
            self::assertSame('Kỳ nghỉ tốt.', $this->scalar('SELECT BinhLuan FROM DanhGia WHERE DanhGiaID=' . $reviewId));
            self::assertSame($publishedBefore, $this->scalar(
                "SELECT COUNT(*) FROM DanhGia WHERE CoSoLuuTruID=" . (int) $booking['propertyId'] . " AND TrangThai='PUBLISHED'"
            ));
        } finally {
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID IN (?,?,?,?,?)')->execute([
                $createId, $moderateId, 'phase14-wrong-customer', 'phase14-late-review', 'phase14-second-review',
            ]);
            if ($reviewId > 0) {
                $this->pdo->prepare('DELETE FROM DanhGia WHERE DanhGiaID=?')->execute([$reviewId]);
            }
            $this->update('DatCho', ['DatChoID'], $bookingState);
        }
    }

    private function noShowService(FrozenClock $clock, ?PaymentGateway $gateway = null): NoShowService
    {
        $lifecycle = new BookingLifecycleRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);
        $audit = new DatabaseAuditTrail($this->pdo);
        $refunds = new NoShowRefundService($this->pdo, $lifecycle, $payments, $gateway ?? new MockPaymentGateway(),
            $this->transactions(), $audit, $clock);
        return new NoShowService($lifecycle, $payments, new NoShowFeeCalculator(), $this->inventory($clock),
            $refunds, $this->transactions(), $audit, $clock);
    }

    private function inventory(FrozenClock $clock): TransactionalInventoryReservationService
    {
        return new TransactionalInventoryReservationService($this->pdo, new InventoryRepository($this->pdo), $clock);
    }

    private function transactions(): TransactionManager
    {
        return new TransactionManager($this->pdo, static fn (int $_): null => null);
    }

    /** @return array<string, mixed> */
    private function snapshotLifecycle(int $bookingId): array
    {
        $booking = $this->row('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId);
        $holds = $this->rows("SELECT gt.* FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID={$bookingId}");
        $inventory = [];
        foreach ($holds as $hold) {
            $key = $hold['LoaiPhongID'] . ':' . $hold['NgayLuuTru'];
            $inventory[$key] = $this->row("SELECT * FROM TonPhongNgay WHERE LoaiPhongID={$hold['LoaiPhongID']} AND NgayLuuTru='{$hold['NgayLuuTru']}'");
        }
        $items = $this->rows('SELECT * FROM HangMucDatCho WHERE DatChoID=' . $bookingId);
        $payment = $this->row('SELECT * FROM ThanhToan WHERE DatChoID=' . $bookingId);
        return ['booking' => $booking, 'items' => $items, 'payment' => $payment,
            'holds' => $holds, 'inventory' => array_values($inventory)];
    }

    /** @param array<string, mixed> $state @param list<string> $requestIds */
    private function restoreLifecycle(array $state, array $requestIds): void
    {
        $bookingId = (int) $state['booking']['DatChoID'];
        $this->pdo->beginTransaction();
        try {
            foreach ($requestIds as $id) {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$id]);
            }
            $this->pdo->prepare('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=?')->execute([$bookingId]);
            foreach ($state['holds'] as $row) {
                $this->insert('GiuTonPhongDem', $row);
            }
            foreach ($state['inventory'] as $row) {
                $this->update('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $row);
            }
            foreach ($state['items'] as $row) {
                $this->update('HangMucDatCho', ['HangMucDatChoID'], $row);
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
    private function rows(string $sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    private function scalar(string $sql): string { return (string) $this->pdo->query($sql)->fetchColumn(); }

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

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CheckInService;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CheckInServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_partner_checks_in_confirmed_booking_once_without_releasing_inventory(): void
    {
        $booking = $this->row(
            "SELECT dc.*, cs.ToChucDoiTacID organizationId, cs.GioNhanPhong checkInTime,
                    cs.GioTraPhong checkOutTime, cs.MuiGio timezone, tv.NguoiDungID actorId
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             INNER JOIN ThanhVienDoiTac tv ON tv.ToChucDoiTacID=cs.ToChucDoiTacID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        );
        $requestId = 'check-in-' . bin2hex(random_bytes(8));
        $activeBefore = $this->activeHolds((int) $booking['DatChoID']);
        $clock = new FrozenClock(new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' ' . $booking['checkInTime'],
            new DateTimeZone((string) $booking['timezone']),
        ));
        $service = $this->service($clock);

        try {
            try {
                $service->checkIn(
                    (string) $booking['MaDatCho'],
                    (int) $booking['organizationId'] + 999,
                    (int) $booking['actorId'],
                    'wrong-org-' . bin2hex(random_bytes(5)),
                    '127.0.0.1',
                );
                self::fail('Tổ chức khác không được nhận khách.');
            } catch (BookingException $exception) {
                self::assertSame('BOOKING_NOT_FOUND', $exception->errorCode);
            }

            $first = $service->checkIn(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $requestId,
                '127.0.0.1',
            );
            $second = $service->checkIn(
                (string) $booking['MaDatCho'],
                (int) $booking['organizationId'],
                (int) $booking['actorId'],
                $requestId,
                '127.0.0.1',
            );

            self::assertEquals($first, $second);
            $current = $this->row('SELECT TrangThai, NhanPhongLuc, NguoiNhanPhongID FROM DatCho WHERE DatChoID=' . (int) $booking['DatChoID']);
            self::assertSame('CHECKED_IN', $current['TrangThai']);
            self::assertNotNull($current['NhanPhongLuc']);
            self::assertSame((string) $booking['actorId'], (string) $current['NguoiNhanPhongID']);
            self::assertSame($activeBefore, $this->activeHolds((int) $booking['DatChoID']));
            self::assertSame('1', (string) $this->pdo->query(
                "SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID=" . $this->pdo->quote($requestId)
            )->fetchColumn());
        } finally {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$requestId]);
                $this->restoreBooking($booking);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
    }

    public function test_check_in_before_arrival_date_is_rejected(): void
    {
        $booking = $this->row(
            "SELECT dc.MaDatCho, dc.DatChoID, dc.NgayNhanPhong, cs.ToChucDoiTacID organizationId,
                    cs.MuiGio timezone, tv.NguoiDungID actorId
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhVienDoiTac tv ON tv.ToChucDoiTacID=cs.ToChucDoiTacID
             WHERE dc.TrangThai='CONFIRMED' ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        );
        $clock = new FrozenClock((new DateTimeImmutable(
            $booking['NgayNhanPhong'] . ' 00:00:00',
            new DateTimeZone((string) $booking['timezone']),
        ))->modify('-1 second'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Chỉ nhận khách từ ngày nhận phòng');
        $this->service($clock)->checkIn(
            (string) $booking['MaDatCho'],
            (int) $booking['organizationId'],
            (int) $booking['actorId'],
            'too-early-' . bin2hex(random_bytes(5)),
            '127.0.0.1',
        );
    }

    private function service(FrozenClock $clock): CheckInService
    {
        return new CheckInService(
            new BookingLifecycleRepository($this->pdo),
            new PaymentRepository($this->pdo),
            new TransactionManager($this->pdo, static fn (int $_): null => null),
            new DatabaseAuditTrail($this->pdo),
            $clock,
        );
    }

    private function activeHolds(int $bookingId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID=? AND gt.TrangThai='ACTIVE'"
        );
        $statement->execute([$bookingId]);
        return (int) $statement->fetchColumn();
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
        $columns = array_keys(array_filter(
            $booking,
            static fn (string $column): bool => !in_array(
                $column,
                ['organizationId', 'checkInTime', 'checkOutTime', 'timezone', 'actorId'],
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        ));
        $set = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", $columns));
        $values = array_map(static fn (string $column): mixed => $booking[$column], $columns);
        $values[] = $booking['DatChoID'];
        $this->pdo->prepare("UPDATE DatCho SET {$set} WHERE DatChoID=?")->execute($values);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class BookingLifecycleRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function lockForOrganization(string $code, int $organizationId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT dc.DatChoID FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.MaDatCho=:code AND cs.ToChucDoiTacID=:organizationId FOR UPDATE'
        );
        $statement->execute(['code' => $code, 'organizationId' => $organizationId]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->context((int) $id);
    }

    /** @return array<string, mixed>|null */
    public function lockById(int $bookingId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare('SELECT DatChoID FROM DatCho WHERE DatChoID=? FOR UPDATE');
        $statement->execute([$bookingId]);
        return $statement->fetchColumn() === false ? null : $this->context($bookingId);
    }

    /** @return list<array<string, mixed>> */
    public function completionCandidates(int $limit): array
    {
        $statement = $this->pdo->query(
            "SELECT dc.DatChoID AS id, dc.NgayTraPhong AS checkOut,
                    cs.GioTraPhong AS checkOutTime, cs.MuiGio AS timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CHECKED_IN'
             ORDER BY dc.NgayTraPhong, dc.DatChoID LIMIT " . $limit
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'checkOut' => (string) $row['checkOut'],
            'checkOutTime' => (string) $row['checkOutTime'],
            'timezone' => (string) $row['timezone'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $snapshot */
    public function markNoShow(int $bookingId, int $actorId, Money $fee, array $snapshot, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET TrangThai='NO_SHOW', NguoiDanhDauNoShowID=:actorId,
                    NoShowLuc=:at, PhiNoShow=:fee, NoShowSnapshotJSON=:snapshot
             WHERE DatChoID=:bookingId AND TrangThai='CONFIRMED'"
        );
        $statement->execute([
            'actorId' => $actorId,
            'at' => self::utc($at),
            'fee' => $fee->decimal(),
            'snapshot' => CanonicalJson::encode($snapshot),
            'bookingId' => $bookingId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể chuyển booking sang NO_SHOW.');
        }
    }

    public function markCheckedIn(int $bookingId, int $actorId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET TrangThai='CHECKED_IN', NhanPhongLuc=:at, NguoiNhanPhongID=:actorId
             WHERE DatChoID=:bookingId AND TrangThai='CONFIRMED'"
        );
        $statement->execute([
            'at' => self::utc($at),
            'actorId' => $actorId,
            'bookingId' => $bookingId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể chuyển booking sang CHECKED_IN.');
        }
    }

    public function markCompleted(int $bookingId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET TrangThai='COMPLETED', HoanThanhLuc=:at
             WHERE DatChoID=:bookingId AND TrangThai='CHECKED_IN'"
        );
        $statement->execute(['at' => self::utc($at), 'bookingId' => $bookingId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể chuyển booking sang COMPLETED.');
        }
    }

    /** @return array<string, mixed>|null */
    private function context(int $bookingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT dc.DatChoID AS id, dc.MaDatCho AS code, dc.KhachHangID AS customerId,
                    dc.CoSoLuuTruID AS propertyId, cs.ToChucDoiTacID AS organizationId,
                    dc.TrangThai AS status, dc.ThoiDiemThanhToan AS paymentTiming,
                    dc.TongTien AS grandTotal, dc.NgayNhanPhong AS checkIn,
                    dc.NgayTraPhong AS checkOut, cs.GioNhanPhong AS checkInTime,
                    cs.GioTraPhong AS checkOutTime, cs.MuiGio AS timezone,
                    dc.NhanPhongLuc AS checkedInAt, dc.NguoiNhanPhongID AS checkedInBy,
                    dc.PhiNoShow AS noShowFee, dc.NoShowSnapshotJSON AS noShowJson
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.DatChoID=?'
        );
        $statement->execute([$bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'customerId', 'propertyId', 'organizationId'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['checkedInBy'] = $row['checkedInBy'] === null ? null : (int) $row['checkedInBy'];
        $row['noShowSnapshot'] = $row['noShowJson'] === null ? null : $this->decode((string) $row['noShowJson']);
        unset($row['noShowJson']);
        $row['items'] = $this->items($bookingId);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID AS id, TongTien AS total, SnapshotHuyJSON AS snapshot
             FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID'
        );
        $statement->execute([$bookingId]);
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'total' => (string) $row['total'],
            'cancellationSnapshot' => $this->decode((string) $row['snapshot']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot vòng đời booking không hợp lệ.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot vòng đời booking phải là array.');
        }
        return $value;
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Vòng đời booking chỉ được khóa trong transaction.');
        }
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

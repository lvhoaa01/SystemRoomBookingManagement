<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Infrastructure;

use App\Modules\Reviews\Application\ReviewRequest;
use PDO;
use RuntimeException;

final readonly class ReviewRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function lockBooking(string $code, int $customerId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT dc.DatChoID AS id, dc.CoSoLuuTruID AS propertyId, dc.KhachHangID AS customerId,
                    dc.TrangThai AS status, dc.NgayTraPhong AS checkOut, cs.MuiGio AS timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.MaDatCho=:code AND dc.KhachHangID=:customerId FOR UPDATE'
        );
        $statement->execute(['code' => $code, 'customerId' => $customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['propertyId'] = (int) $row['propertyId'];
        $row['customerId'] = (int) $row['customerId'];
        return $row;
    }

    public function existsForBooking(int $bookingId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM DanhGia WHERE DatChoID=?');
        $statement->execute([$bookingId]);
        return $statement->fetchColumn() !== false;
    }

    public function create(int $bookingId, int $propertyId, int $customerId, ReviewRequest $request): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO DanhGia
                (DatChoID,CoSoLuuTruID,KhachHangID,DiemTong,DiemSachSe,DiemViTri,
                 DiemNhanVien,DiemThoaiMai,DiemTienNghi,DiemDangTien,BinhLuan,TrangThai)
             VALUES (:bookingId,:propertyId,:customerId,:overall,:cleanliness,:location,
                     :staff,:comfort,:facilities,:value,:comment,'PUBLISHED')"
        );
        $statement->execute([
            'bookingId' => $bookingId, 'propertyId' => $propertyId, 'customerId' => $customerId,
            'overall' => $request->overall, ...$request->categories, 'comment' => $request->comment,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT dg.DanhGiaID AS id, dc.MaDatCho AS bookingCode, cs.TenCoSo AS propertyName,
                    nd.HoTen AS customerName, dg.DiemTong AS overall, dg.BinhLuan AS comment,
                    dg.TrangThai AS status, dg.TaoLuc AS createdAt
             FROM DanhGia dg INNER JOIN DatCho dc ON dc.DatChoID=dg.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dg.CoSoLuuTruID
             INNER JOIN NguoiDung nd ON nd.NguoiDungID=dg.KhachHangID
             ORDER BY dg.TaoLuc DESC, dg.DanhGiaID DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function lockReview(int $reviewId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare('SELECT DanhGiaID AS id, TrangThai AS status FROM DanhGia WHERE DanhGiaID=? FOR UPDATE');
        $statement->execute([$reviewId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['id' => (int) $row['id'], 'status' => (string) $row['status']] : null;
    }

    public function setStatus(int $reviewId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE DanhGia SET TrangThai=? WHERE DanhGiaID=?');
        $statement->execute([$status, $reviewId]);
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Review chỉ được khóa trong transaction.');
        }
    }
}

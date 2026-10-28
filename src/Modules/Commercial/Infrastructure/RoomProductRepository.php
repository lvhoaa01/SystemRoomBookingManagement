<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class RoomProductRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForProperty(int $propertyId, int $organizationId): array
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT sp.SanPhamPhongID AS id, lp.CoSoLuuTruID AS propertyId,
                    sp.LoaiPhongID AS roomTypeId, lp.TenLoaiPhong AS roomTypeName,
                    sp.ChinhSachHuyID AS cancellationPolicyId, csh.TenChinhSach AS cancellationPolicyName,
                    sp.MaSanPham AS code, sp.TenSanPham AS name, sp.LoaiBuaAn AS mealType,
                    sp.ThoiDiemThanhToan AS paymentTiming, sp.TrangThai AS status
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID = sp.ChinhSachHuyID
                 AND csh.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY sp.SanPhamPhongID ASC'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $productId, int $organizationId): ?array
    {
        self::assertIds($productId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT sp.SanPhamPhongID AS id, lp.CoSoLuuTruID AS propertyId,
                    sp.LoaiPhongID AS roomTypeId, lp.TenLoaiPhong AS roomTypeName,
                    sp.ChinhSachHuyID AS cancellationPolicyId, csh.TenChinhSach AS cancellationPolicyName,
                    sp.MaSanPham AS code, sp.TenSanPham AS name, sp.LoaiBuaAn AS mealType,
                    sp.ThoiDiemThanhToan AS paymentTiming, sp.TrangThai AS status
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID = sp.ChinhSachHuyID
                 AND csh.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE sp.SanPhamPhongID = :productId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['productId' => $productId, 'organizationId' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $propertyId, int $organizationId, array $data): ?int
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'INSERT INTO SanPhamPhong (
                LoaiPhongID, ChinhSachHuyID, MaSanPham, TenSanPham,
                LoaiBuaAn, ThoiDiemThanhToan, TrangThai
             )
             SELECT lp.LoaiPhongID, csh.ChinhSachHuyID, :code, :name,
                    :mealType, :paymentTiming, :status
             FROM LoaiPhong lp
             INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID = :cancellationPolicyId
                 AND csh.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.LoaiPhongID = :roomTypeId AND lp.CoSoLuuTruID = :propertyId
                 AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute([
            'propertyId' => $propertyId,
            'organizationId' => $organizationId,
            ...$data,
        ]);
        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $productId, int $organizationId, array $data): bool
    {
        self::assertIds($productId, $organizationId);
        $statement = $this->pdo->prepare(
            'UPDATE SanPhamPhong sp
             INNER JOIN LoaiPhong currentRoom ON currentRoom.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = currentRoom.CoSoLuuTruID
             INNER JOIN LoaiPhong targetRoom ON targetRoom.LoaiPhongID = :roomTypeId
                 AND targetRoom.CoSoLuuTruID = cs.CoSoLuuTruID
             INNER JOIN ChinhSachHuy targetPolicy ON targetPolicy.ChinhSachHuyID = :cancellationPolicyId
                 AND targetPolicy.CoSoLuuTruID = cs.CoSoLuuTruID
             SET sp.LoaiPhongID = targetRoom.LoaiPhongID,
                 sp.ChinhSachHuyID = targetPolicy.ChinhSachHuyID,
                 sp.MaSanPham = :code, sp.TenSanPham = :name,
                 sp.LoaiBuaAn = :mealType, sp.ThoiDiemThanhToan = :paymentTiming,
                 sp.TrangThai = :status
             WHERE sp.SanPhamPhongID = :productId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['productId' => $productId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() <= 1 && $this->findForOrganization($productId, $organizationId) !== null;
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }
}

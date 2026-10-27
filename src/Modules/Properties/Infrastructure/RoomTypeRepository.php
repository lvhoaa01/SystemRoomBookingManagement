<?php

declare(strict_types=1);

namespace App\Modules\Properties\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class RoomTypeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForProperty(int $propertyId, int $organizationId): array
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT lp.LoaiPhongID AS id, lp.CoSoLuuTruID AS propertyId,
                    lp.MaLoaiPhong AS code, lp.TenLoaiPhong AS name, lp.MoTa AS description,
                    lp.SoKhachToiDa AS maxGuests, lp.SoNguoiLonToiDa AS maxAdults,
                    lp.SoTreEmToiDa AS maxChildren, lp.CauHinhGiuongJSON AS bedsJson,
                    lp.DienTichM2 AS areaM2, lp.ChoPhepHutThuoc AS smokingAllowed,
                    lp.AnhJSON AS imagesJson, lp.TrangThai AS status,
                    lp.TaoLuc AS createdAt, lp.CapNhatLuc AS updatedAt
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY lp.LoaiPhongID ASC'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $roomTypeId, int $organizationId): ?array
    {
        self::assertIds($roomTypeId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT lp.LoaiPhongID AS id, lp.CoSoLuuTruID AS propertyId,
                    lp.MaLoaiPhong AS code, lp.TenLoaiPhong AS name, lp.MoTa AS description,
                    lp.SoKhachToiDa AS maxGuests, lp.SoNguoiLonToiDa AS maxAdults,
                    lp.SoTreEmToiDa AS maxChildren, lp.CauHinhGiuongJSON AS bedsJson,
                    lp.DienTichM2 AS areaM2, lp.ChoPhepHutThuoc AS smokingAllowed,
                    lp.AnhJSON AS imagesJson, lp.TrangThai AS status
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['roomTypeId' => $roomTypeId, 'organizationId' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $propertyId, int $organizationId, array $data): ?int
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'INSERT INTO LoaiPhong (
                CoSoLuuTruID, MaLoaiPhong, TenLoaiPhong, MoTa,
                SoKhachToiDa, SoNguoiLonToiDa, SoTreEmToiDa,
                CauHinhGiuongJSON, DienTichM2, ChoPhepHutThuoc, AnhJSON, TrangThai
             )
             SELECT cs.CoSoLuuTruID, :code, :name, :description,
                    :maxGuests, :maxAdults, :maxChildren,
                    :bedsJson, :areaM2, :smokingAllowed, :imagesJson, :status
             FROM CoSoLuuTru cs
             WHERE cs.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $roomTypeId, int $organizationId, array $data): bool
    {
        self::assertIds($roomTypeId, $organizationId);
        $statement = $this->pdo->prepare(
            'UPDATE LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             SET lp.MaLoaiPhong = :code, lp.TenLoaiPhong = :name, lp.MoTa = :description,
                 lp.SoKhachToiDa = :maxGuests, lp.SoNguoiLonToiDa = :maxAdults,
                 lp.SoTreEmToiDa = :maxChildren, lp.CauHinhGiuongJSON = :bedsJson,
                 lp.DienTichM2 = :areaM2, lp.ChoPhepHutThuoc = :smokingAllowed,
                 lp.AnhJSON = :imagesJson, lp.TrangThai = :status
             WHERE lp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['roomTypeId' => $roomTypeId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() <= 1 && $this->findForOrganization($roomTypeId, $organizationId) !== null;
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }
}

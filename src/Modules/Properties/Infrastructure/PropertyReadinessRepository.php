<?php

declare(strict_types=1);

namespace App\Modules\Properties\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class PropertyReadinessRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isOrganizationActive(int $propertyId): bool
    {
        $this->assertPropertyId($propertyId);
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM CoSoLuuTru cs
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE cs.CoSoLuuTruID = :propertyId AND tc.TrangThai = 'HOAT_DONG'"
        );
        $statement->execute(['propertyId' => $propertyId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{bedsJson: string, imagesJson: string}> */
    /** @return list<array{id: int, code: string, name: string, maxGuests: int, areaM2: string|null, bedsJson: string, imagesJson: string}> */
    public function activeRooms(int $propertyId): array
    {
        $this->assertPropertyId($propertyId);
        $statement = $this->pdo->prepare(
            "SELECT LoaiPhongID AS id, MaLoaiPhong AS code, TenLoaiPhong AS name,
                    SoKhachToiDa AS maxGuests, DienTichM2 AS areaM2,
                    CauHinhGiuongJSON AS bedsJson, AnhJSON AS imagesJson
             FROM LoaiPhong WHERE CoSoLuuTruID = :propertyId AND TrangThai = 'ACTIVE'"
        );
        $statement->execute(['propertyId' => $propertyId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{hasCancellationPolicy: bool, hasProduct: bool, priceDays: int, inventoryDays: int, hasCrossPropertyRelation: bool} */
    public function commercialFacts(int $propertyId): array
    {
        $this->assertPropertyId($propertyId);

        $policy = $this->pdo->prepare(
            "SELECT EXISTS(SELECT 1 FROM ChinhSachHuy
             WHERE CoSoLuuTruID = :propertyId AND TrangThai = 'ACTIVE')"
        );
        $policy->execute(['propertyId' => $propertyId]);

        $product = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM SanPhamPhong sp
                INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
                INNER JOIN ChinhSachHuy cs ON cs.ChinhSachHuyID = sp.ChinhSachHuyID
                WHERE lp.CoSoLuuTruID = :roomPropertyId AND cs.CoSoLuuTruID = :policyPropertyId
                  AND lp.TrangThai = 'ACTIVE' AND cs.TrangThai = 'ACTIVE' AND sp.TrangThai = 'ACTIVE'
             )"
        );
        $product->execute([
            'roomPropertyId' => $propertyId,
            'policyPropertyId' => $propertyId,
        ]);

        $priceDays = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT gpn.NgayLuuTru)
             FROM GiaPhongNgay gpn
             INNER JOIN SanPhamPhong sp ON sp.SanPhamPhongID = gpn.SanPhamPhongID AND sp.TrangThai = 'ACTIVE'
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID AND lp.TrangThai = 'ACTIVE'
             WHERE lp.CoSoLuuTruID = :propertyId AND gpn.DongBan = 0
               AND gpn.GiaCoBan > 0 AND gpn.NgayLuuTru >= CURRENT_DATE
               AND gpn.NgayLuuTru < DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)"
        );
        $priceDays->execute(['propertyId' => $propertyId]);

        $inventoryDays = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT tpn.NgayLuuTru)
             FROM TonPhongNgay tpn
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = tpn.LoaiPhongID AND lp.TrangThai = 'ACTIVE'
             WHERE lp.CoSoLuuTruID = :propertyId AND tpn.TongSoLuong >= tpn.SoLuongDaGiu
               AND tpn.NgayLuuTru >= CURRENT_DATE
               AND tpn.NgayLuuTru < DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)"
        );
        $inventoryDays->execute(['propertyId' => $propertyId]);

        $crossProperty = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM SanPhamPhong sp
                INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
                INNER JOIN ChinhSachHuy cs ON cs.ChinhSachHuyID = sp.ChinhSachHuyID
                WHERE (lp.CoSoLuuTruID = :roomPropertyId OR cs.CoSoLuuTruID = :policyPropertyId)
                  AND lp.CoSoLuuTruID <> cs.CoSoLuuTruID
             )'
        );
        $crossProperty->execute([
            'roomPropertyId' => $propertyId,
            'policyPropertyId' => $propertyId,
        ]);

        return [
            'hasCancellationPolicy' => (bool) $policy->fetchColumn(),
            'hasProduct' => (bool) $product->fetchColumn(),
            'priceDays' => (int) $priceDays->fetchColumn(),
            'inventoryDays' => (int) $inventoryDays->fetchColumn(),
            'hasCrossPropertyRelation' => (bool) $crossProperty->fetchColumn(),
        ];
    }

    private function assertPropertyId(int $propertyId): void
    {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('ID cơ sở lưu trú phải là số dương.');
        }
    }
}

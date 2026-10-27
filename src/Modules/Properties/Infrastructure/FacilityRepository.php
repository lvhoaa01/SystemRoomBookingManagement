<?php

declare(strict_types=1);

namespace App\Modules\Properties\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class FacilityRepository
{
    public const SCOPE_PROPERTY = 'PROPERTY';
    public const SCOPE_ROOM = 'ROOM';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listActiveCatalog(string $scope): array
    {
        self::assertScope($scope);
        $statement = $this->pdo->prepare(
            "SELECT TienNghiID AS id, MaTienNghi AS code, TenTienNghi AS name, PhamVi AS scope
             FROM TienNghi
             WHERE PhamVi = :scope AND TrangThai = 'HOAT_DONG'
             ORDER BY TenTienNghi ASC, TienNghiID ASC"
        );
        $statement->execute(['scope' => $scope]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function listForProperty(int $propertyId, int $organizationId): array
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT tn.TienNghiID AS id, tn.MaTienNghi AS code, tn.TenTienNghi AS name,
                    cstn.MienPhi AS free, cstn.GhiChu AS note
             FROM CoSoTienNghi cstn
             INNER JOIN TienNghi tn ON tn.TienNghiID = cstn.TienNghiID AND tn.PhamVi = :scope
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = cstn.CoSoLuuTruID
             WHERE cstn.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY tn.TenTienNghi ASC'
        );
        $statement->execute([
            'scope' => self::SCOPE_PROPERTY,
            'propertyId' => $propertyId,
            'organizationId' => $organizationId,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function listForRoomType(int $roomTypeId, int $organizationId): array
    {
        self::assertIds($roomTypeId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT tn.TienNghiID AS id, tn.MaTienNghi AS code, tn.TenTienNghi AS name,
                    lptn.SoLuong AS quantity, lptn.GhiChu AS note
             FROM LoaiPhongTienNghi lptn
             INNER JOIN TienNghi tn ON tn.TienNghiID = lptn.TienNghiID AND tn.PhamVi = :scope
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = lptn.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lptn.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY tn.TenTienNghi ASC'
        );
        $statement->execute([
            'scope' => self::SCOPE_ROOM,
            'roomTypeId' => $roomTypeId,
            'organizationId' => $organizationId,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function propertyBelongsToOrganization(int $propertyId, int $organizationId): bool
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM CoSoLuuTru WHERE CoSoLuuTruID = :propertyId AND ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param list<array{id: int, free: bool, note: ?string}> $facilities */
    public function replaceForProperty(int $propertyId, int $organizationId, array $facilities): bool
    {
        if (!$this->propertyBelongsToOrganization($propertyId, $organizationId)) {
            return false;
        }

        $delete = $this->pdo->prepare('DELETE FROM CoSoTienNghi WHERE CoSoLuuTruID = :propertyId');
        $delete->execute(['propertyId' => $propertyId]);

        $insert = $this->pdo->prepare(
            "INSERT INTO CoSoTienNghi (CoSoLuuTruID, TienNghiID, MienPhi, GhiChu)
             SELECT :propertyId, tn.TienNghiID, :free, :note
             FROM TienNghi tn
             WHERE tn.TienNghiID = :facilityId AND tn.PhamVi = 'PROPERTY' AND tn.TrangThai = 'HOAT_DONG'"
        );
        foreach ($facilities as $facility) {
            $insert->execute([
                'propertyId' => $propertyId,
                'facilityId' => $facility['id'],
                'free' => $facility['free'] ? 1 : 0,
                'note' => $facility['note'],
            ]);
            if ($insert->rowCount() !== 1) {
                throw new InvalidArgumentException('Tiện nghi cơ sở lưu trú không hợp lệ.');
            }
        }
        return true;
    }

    /** @param list<array{id: int, quantity: ?int, note: ?string}> $facilities */
    public function replaceForRoomType(int $roomTypeId, int $organizationId, array $facilities): bool
    {
        $owner = $this->pdo->prepare(
            'SELECT 1 FROM LoaiPhong lp INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId'
        );
        $owner->execute(['roomTypeId' => $roomTypeId, 'organizationId' => $organizationId]);
        if ($owner->fetchColumn() === false) {
            return false;
        }

        $delete = $this->pdo->prepare('DELETE FROM LoaiPhongTienNghi WHERE LoaiPhongID = :roomTypeId');
        $delete->execute(['roomTypeId' => $roomTypeId]);

        $insert = $this->pdo->prepare(
            "INSERT INTO LoaiPhongTienNghi (LoaiPhongID, TienNghiID, SoLuong, GhiChu)
             SELECT :roomTypeId, tn.TienNghiID, :quantity, :note
             FROM TienNghi tn
             WHERE tn.TienNghiID = :facilityId AND tn.PhamVi = 'ROOM' AND tn.TrangThai = 'HOAT_DONG'"
        );
        foreach ($facilities as $facility) {
            $insert->execute([
                'roomTypeId' => $roomTypeId,
                'facilityId' => $facility['id'],
                'quantity' => $facility['quantity'],
                'note' => $facility['note'],
            ]);
            if ($insert->rowCount() !== 1) {
                throw new InvalidArgumentException('Tiện nghi loại phòng không hợp lệ.');
            }
        }
        return true;
    }

    private static function assertScope(string $scope): void
    {
        if (!in_array($scope, [self::SCOPE_PROPERTY, self::SCOPE_ROOM], true)) {
            throw new InvalidArgumentException('Phạm vi tiện nghi không hợp lệ.');
        }
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }
}

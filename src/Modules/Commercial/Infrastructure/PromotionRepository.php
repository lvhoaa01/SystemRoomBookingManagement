<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class PromotionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForProperty(int $propertyId, int $organizationId): array
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT km.KhuyenMaiID AS id, km.CoSoLuuTruID AS propertyId,
                    km.MaKhuyenMai AS code, km.TenKhuyenMai AS name,
                    km.PhanTramGiam AS discountRate, km.DatTu AS bookingFrom,
                    km.DatDen AS bookingUntil, km.LuuTruTu AS stayFrom,
                    km.LuuTruDen AS stayUntil, km.TrangThai AS status
             FROM KhuyenMai km
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = km.CoSoLuuTruID
             WHERE km.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY km.KhuyenMaiID ASC'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $promotionId, int $organizationId): ?array
    {
        self::assertIds($promotionId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT km.KhuyenMaiID AS id, km.CoSoLuuTruID AS propertyId,
                    km.MaKhuyenMai AS code, km.TenKhuyenMai AS name,
                    km.PhanTramGiam AS discountRate, km.DatTu AS bookingFrom,
                    km.DatDen AS bookingUntil, km.LuuTruTu AS stayFrom,
                    km.LuuTruDen AS stayUntil, km.TrangThai AS status
             FROM KhuyenMai km
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = km.CoSoLuuTruID
             WHERE km.KhuyenMaiID = :promotionId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['promotionId' => $promotionId, 'organizationId' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $propertyId, int $organizationId, array $data): ?int
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'INSERT INTO KhuyenMai (
                CoSoLuuTruID, MaKhuyenMai, TenKhuyenMai, PhanTramGiam,
                DatTu, DatDen, LuuTruTu, LuuTruDen, TrangThai
             )
             SELECT cs.CoSoLuuTruID, :code, :name, :discountRate,
                    :bookingFrom, :bookingUntil, :stayFrom, :stayUntil, :status
             FROM CoSoLuuTru cs
             WHERE cs.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $promotionId, int $organizationId, array $data): bool
    {
        self::assertIds($promotionId, $organizationId);
        $statement = $this->pdo->prepare(
            'UPDATE KhuyenMai km
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = km.CoSoLuuTruID
             SET km.MaKhuyenMai = :code, km.TenKhuyenMai = :name,
                 km.PhanTramGiam = :discountRate, km.DatTu = :bookingFrom,
                 km.DatDen = :bookingUntil, km.LuuTruTu = :stayFrom,
                 km.LuuTruDen = :stayUntil, km.TrangThai = :status
             WHERE km.KhuyenMaiID = :promotionId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['promotionId' => $promotionId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() <= 1 && $this->findForOrganization($promotionId, $organizationId) !== null;
    }

    /** @return list<array{id: int, discountRate: string}> */
    public function listApplicable(
        int $propertyId,
        string $bookedAt,
        string $firstStayDate,
        string $lastStayDate,
    ): array {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('ID cơ sở phải là số dương.');
        }
        $statement = $this->pdo->prepare(
            "SELECT KhuyenMaiID AS id, PhanTramGiam AS discountRate
             FROM KhuyenMai
             WHERE CoSoLuuTruID = :propertyId AND TrangThai = 'ACTIVE'
                 AND DatTu <= :bookedFrom AND DatDen >= :bookedUntil
                 AND LuuTruTu <= :firstStayDate AND LuuTruDen >= :lastStayDate
             ORDER BY PhanTramGiam DESC, KhuyenMaiID ASC"
        );
        $statement->execute([
            'propertyId' => $propertyId,
            'bookedFrom' => $bookedAt,
            'bookedUntil' => $bookedAt,
            'firstStayDate' => $firstStayDate,
            'lastStayDate' => $lastStayDate,
        ]);
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'discountRate' => (string) $row['discountRate'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }
}

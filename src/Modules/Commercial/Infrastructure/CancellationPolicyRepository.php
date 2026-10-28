<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Infrastructure;

use InvalidArgumentException;
use PDO;

final readonly class CancellationPolicyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForProperty(int $propertyId, int $organizationId): array
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT csh.ChinhSachHuyID AS id, csh.CoSoLuuTruID AS propertyId,
                    csh.MaChinhSach AS code, csh.TenChinhSach AS name,
                    csh.LoaiChinhSach AS policyType, csh.SoGioHuyMienPhi AS freeCancellationHours,
                    csh.LoaiPhat AS penaltyType, csh.GiaTriPhat AS penaltyValue,
                    csh.TyLePhatNoShow AS noShowPenaltyRate, csh.TrangThai AS status
             FROM ChinhSachHuy csh
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = csh.CoSoLuuTruID
             WHERE csh.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId
             ORDER BY csh.ChinhSachHuyID ASC'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $policyId, int $organizationId): ?array
    {
        self::assertIds($policyId, $organizationId);
        $statement = $this->pdo->prepare(
            'SELECT csh.ChinhSachHuyID AS id, csh.CoSoLuuTruID AS propertyId,
                    csh.MaChinhSach AS code, csh.TenChinhSach AS name,
                    csh.LoaiChinhSach AS policyType, csh.SoGioHuyMienPhi AS freeCancellationHours,
                    csh.LoaiPhat AS penaltyType, csh.GiaTriPhat AS penaltyValue,
                    csh.TyLePhatNoShow AS noShowPenaltyRate, csh.TrangThai AS status
             FROM ChinhSachHuy csh
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = csh.CoSoLuuTruID
             WHERE csh.ChinhSachHuyID = :policyId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['policyId' => $policyId, 'organizationId' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $propertyId, int $organizationId, array $data): ?int
    {
        self::assertIds($propertyId, $organizationId);
        $statement = $this->pdo->prepare(
            'INSERT INTO ChinhSachHuy (
                CoSoLuuTruID, MaChinhSach, TenChinhSach, LoaiChinhSach,
                SoGioHuyMienPhi, LoaiPhat, GiaTriPhat, TyLePhatNoShow, TrangThai
             )
             SELECT cs.CoSoLuuTruID, :code, :name, :policyType,
                    :freeCancellationHours, :penaltyType, :penaltyValue, :noShowPenaltyRate, :status
             FROM CoSoLuuTru cs
             WHERE cs.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['propertyId' => $propertyId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $policyId, int $organizationId, array $data): bool
    {
        self::assertIds($policyId, $organizationId);
        $statement = $this->pdo->prepare(
            'UPDATE ChinhSachHuy csh
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = csh.CoSoLuuTruID
             SET csh.MaChinhSach = :code, csh.TenChinhSach = :name,
                 csh.LoaiChinhSach = :policyType,
                 csh.SoGioHuyMienPhi = :freeCancellationHours,
                 csh.LoaiPhat = :penaltyType, csh.GiaTriPhat = :penaltyValue,
                 csh.TyLePhatNoShow = :noShowPenaltyRate, csh.TrangThai = :status
             WHERE csh.ChinhSachHuyID = :policyId AND cs.ToChucDoiTacID = :organizationId'
        );
        $statement->execute(['policyId' => $policyId, 'organizationId' => $organizationId, ...$data]);
        return $statement->rowCount() <= 1 && $this->findForOrganization($policyId, $organizationId) !== null;
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }
}

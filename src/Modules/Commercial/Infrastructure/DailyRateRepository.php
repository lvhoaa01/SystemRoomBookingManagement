<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final readonly class DailyRateRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForProduct(
        int $productId,
        int $organizationId,
        string $fromDate,
        string $toDate,
    ): array {
        self::assertIds($productId, $organizationId);
        self::assertDateRange($fromDate, $toDate);
        $statement = $this->pdo->prepare(
            'SELECT gpn.SanPhamPhongID AS productId, lp.CoSoLuuTruID AS propertyId,
                    gpn.NgayLuuTru AS stayDate, gpn.GiaCoBan AS basePrice,
                    gpn.DongBan AS closed, gpn.SoDemToiThieu AS minimumStay,
                    gpn.SoDemToiDa AS maximumStay, gpn.SoNgayDatTruoc AS minimumLeadDays,
                    gpn.PhienBan AS version, gpn.CapNhatLuc AS updatedAt
             FROM GiaPhongNgay gpn
             INNER JOIN SanPhamPhong sp ON sp.SanPhamPhongID = gpn.SanPhamPhongID
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE gpn.SanPhamPhongID = :productId AND cs.ToChucDoiTacID = :organizationId
                 AND gpn.NgayLuuTru BETWEEN :fromDate AND :toDate
             ORDER BY gpn.NgayLuuTru ASC'
        );
        $statement->execute([
            'productId' => $productId,
            'organizationId' => $organizationId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $productId, string $stayDate, int $organizationId): ?array
    {
        $rows = $this->listForProduct($productId, $organizationId, $stayDate, $stayDate);
        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function upsert(int $productId, int $organizationId, array $data): ?array
    {
        self::assertIds($productId, $organizationId);
        $statement = $this->pdo->prepare(
            'INSERT INTO GiaPhongNgay (
                SanPhamPhongID, NgayLuuTru, GiaCoBan, DongBan,
                SoDemToiThieu, SoDemToiDa, SoNgayDatTruoc, PhienBan, CapNhatLuc
             )
             SELECT sp.SanPhamPhongID, :stayDate, :basePrice, :closed,
                    :minimumStay, :maximumStay, :minimumLeadDays, 1, CURRENT_TIMESTAMP(6)
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE sp.SanPhamPhongID = :productId AND cs.ToChucDoiTacID = :organizationId
             ON DUPLICATE KEY UPDATE
                GiaCoBan = VALUES(GiaCoBan), DongBan = VALUES(DongBan),
                SoDemToiThieu = VALUES(SoDemToiThieu), SoDemToiDa = VALUES(SoDemToiDa),
                SoNgayDatTruoc = VALUES(SoNgayDatTruoc),
                PhienBan = GiaPhongNgay.PhienBan + 1,
                CapNhatLuc = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute(['productId' => $productId, 'organizationId' => $organizationId, ...$data]);
        return $this->findForOrganization($productId, (string) $data['stayDate'], $organizationId);
    }

    private static function assertIds(int $resourceId, int $organizationId): void
    {
        if ($resourceId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('ID tài nguyên và tổ chức phải là số dương.');
        }
    }

    private static function assertDateRange(string $fromDate, string $toDate): void
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
        if ($from === false || $to === false
            || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate
            || $from > $to) {
            throw new InvalidArgumentException('Khoảng ngày lịch giá không hợp lệ.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final readonly class InventoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForRoomType(
        int $roomTypeId,
        int $organizationId,
        string $fromDate,
        string $toDate,
    ): array {
        self::assertIds($roomTypeId, $organizationId);
        self::assertDateRange($fromDate, $toDate);
        $statement = $this->pdo->prepare(
            'SELECT tp.LoaiPhongID AS roomTypeId, lp.CoSoLuuTruID AS propertyId,
                    tp.NgayLuuTru AS stayDate, tp.TongSoLuong AS totalQuantity,
                    tp.SoLuongDaGiu AS heldQuantity,
                    tp.TongSoLuong - tp.SoLuongDaGiu AS availableQuantity,
                    tp.PhienBan AS version, tp.CapNhatLuc AS updatedAt
             FROM TonPhongNgay tp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = tp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE tp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId
                 AND tp.NgayLuuTru BETWEEN :fromDate AND :toDate
             ORDER BY tp.NgayLuuTru ASC'
        );
        $statement->execute([
            'roomTypeId' => $roomTypeId,
            'organizationId' => $organizationId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $roomTypeId, string $stayDate, int $organizationId): ?array
    {
        $rows = $this->listForRoomType($roomTypeId, $organizationId, $stayDate, $stayDate);
        return $rows[0] ?? null;
    }

    /**
     * Ghi tổng tồn bằng một statement nguyên tử. Khi counter đã lớn hơn tổng mới,
     * statement giữ nguyên dữ liệu và trả về null.
     *
     * @return array<string, mixed>|null
     */
    public function upsertTotal(
        int $roomTypeId,
        int $organizationId,
        string $stayDate,
        int $totalQuantity,
    ): ?array {
        self::assertIds($roomTypeId, $organizationId);
        self::assertDateRange($stayDate, $stayDate);
        if ($totalQuantity < 0 || $totalQuantity > 65_535) {
            throw new InvalidArgumentException('Tổng số phòng nằm ngoài giới hạn lưu trữ.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO TonPhongNgay
                (LoaiPhongID, NgayLuuTru, TongSoLuong, SoLuongDaGiu, PhienBan, CapNhatLuc)
             SELECT lp.LoaiPhongID, :stayDate, :totalQuantity, 0, 1, CURRENT_TIMESTAMP(6)
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId
             ON DUPLICATE KEY UPDATE
                TongSoLuong = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    VALUES(TongSoLuong), TonPhongNgay.TongSoLuong
                ),
                PhienBan = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    TonPhongNgay.PhienBan + 1, TonPhongNgay.PhienBan
                ),
                CapNhatLuc = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    CURRENT_TIMESTAMP(6), TonPhongNgay.CapNhatLuc
                )'
        );
        $statement->execute([
            'roomTypeId' => $roomTypeId,
            'organizationId' => $organizationId,
            'stayDate' => $stayDate,
            'totalQuantity' => $totalQuantity,
        ]);
        if ($statement->rowCount() === 0) {
            return null;
        }
        return $this->findForOrganization($roomTypeId, $stayDate, $organizationId);
    }

    private static function assertIds(int $roomTypeId, int $organizationId): void
    {
        if ($roomTypeId < 1 || $organizationId < 1) {
            throw new InvalidArgumentException('ID loại phòng và tổ chức phải là số dương.');
        }
    }

    private static function assertDateRange(string $fromDate, string $toDate): void
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
        if ($from === false || $to === false
            || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate
            || $from > $to) {
            throw new InvalidArgumentException('Khoảng ngày tồn phòng không hợp lệ.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Properties\Infrastructure;

use App\Modules\Properties\Domain\PropertyStatus;
use InvalidArgumentException;
use PDO;

final readonly class PropertyRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
        SELECT
            cs.CoSoLuuTruID AS id,
            cs.MaCoSo AS code,
            cs.ToChucDoiTacID AS organizationId,
            cs.MaThanhPho AS cityCode,
            cs.LoaiCoSo AS type,
            cs.TenCoSo AS name,
            cs.TenTrongNguon AS sourceName,
            cs.NguonTen AS nameSource,
            cs.MaBanGhiNguon AS sourceRecordCode,
            cs.Slug AS slug,
            cs.DiaChi AS address,
            cs.NguonDiaChi AS addressSource,
            cs.DiaChiXacMinhLuc AS addressVerifiedAt,
            cs.MoTa AS description,
            cs.AnhJSON AS imagesJson,
            cs.ViDo AS latitude,
            cs.KinhDo AS longitude,
            cs.NguonToaDo AS coordinatesSource,
            cs.ToaDoXacMinhLuc AS coordinatesVerifiedAt,
            cs.TrangThaiDoiChieu AS reconciliationStatus,
            cs.DuongDanBanDo AS mapUrl,
            cs.SoSao AS starRating,
            cs.TyLeThue AS taxRate,
            cs.ThueDaBaoGom AS taxIncluded,
            cs.TyLePhiDichVu AS serviceFeeRate,
            cs.PhiDichVuDaBaoGom AS serviceFeeIncluded,
            cs.GioNhanPhong AS checkInTime,
            cs.GioTraPhong AS checkOutTime,
            cs.MuiGio AS timezone,
            cs.TrangThai AS status,
            cs.NguoiDuyetID AS reviewerId,
            cs.DuyetLuc AS reviewedAt,
            cs.GhiChuDuyet AS reviewNote,
            cs.TaoLuc AS createdAt,
            cs.CapNhatLuc AS updatedAt
        FROM CoSoLuuTru cs
        SQL;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForOrganization(int $organizationId): array
    {
        self::assertPositiveId($organizationId, 'ID tổ chức');

        $statement = $this->pdo->prepare(
            self::SELECT_COLUMNS . ' WHERE cs.ToChucDoiTacID = :organizationId ORDER BY cs.CapNhatLuc DESC, cs.CoSoLuuTruID DESC'
        );
        $statement->execute(['organizationId' => $organizationId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $propertyId, int $organizationId, bool $forUpdate = false): ?array
    {
        self::assertPositiveId($propertyId, 'ID cơ sở lưu trú');
        self::assertPositiveId($organizationId, 'ID tổ chức');

        $statement = $this->pdo->prepare(
            self::SELECT_COLUMNS
            . ' WHERE cs.CoSoLuuTruID = :propertyId AND cs.ToChucDoiTacID = :organizationId'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'propertyId' => $propertyId,
            'organizationId' => $organizationId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $propertyId, bool $forUpdate = false): ?array
    {
        self::assertPositiveId($propertyId, 'ID cơ sở lưu trú');

        $statement = $this->pdo->prepare(
            self::SELECT_COLUMNS . ' WHERE cs.CoSoLuuTruID = :propertyId' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['propertyId' => $propertyId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function listPendingReview(): array
    {
        $statement = $this->pdo->query(
            self::SELECT_COLUMNS . " WHERE cs.TrangThai = 'PENDING_REVIEW' ORDER BY cs.CapNhatLuc ASC, cs.CoSoLuuTruID ASC"
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function createDraft(int $organizationId, array $data): int
    {
        self::assertPositiveId($organizationId, 'ID tổ chức');

        $statement = $this->pdo->prepare(
            "INSERT INTO CoSoLuuTru (
                MaCoSo, ToChucDoiTacID, MaThanhPho, LoaiCoSo, TenCoSo,
                TenTrongNguon, NguonTen, MaBanGhiNguon, Slug,
                DiaChi, NguonDiaChi, DiaChiXacMinhLuc, MoTa, AnhJSON,
                ViDo, KinhDo, NguonToaDo, ToaDoXacMinhLuc,
                TrangThaiDoiChieu, DuongDanBanDo, SoSao,
                TyLeThue, ThueDaBaoGom, TyLePhiDichVu, PhiDichVuDaBaoGom,
                GioNhanPhong, GioTraPhong, MuiGio, TrangThai,
                NguoiDuyetID, DuyetLuc, GhiChuDuyet
             ) VALUES (
                :code, :organizationId, 'HCM', :type, :name,
                :sourceName, :nameSource, :sourceRecordCode, :slug,
                :address, :addressSource, :addressVerifiedAt, :description, :imagesJson,
                :latitude, :longitude, :coordinatesSource, :coordinatesVerifiedAt,
                :reconciliationStatus, :mapUrl, :starRating,
                :taxRate, :taxIncluded, :serviceFeeRate, :serviceFeeIncluded,
                :checkInTime, :checkOutTime, :timezone, 'DRAFT',
                NULL, NULL, NULL
             )"
        );
        $statement->execute(['organizationId' => $organizationId, ...$data]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateForOrganization(int $propertyId, int $organizationId, array $data): bool
    {
        self::assertPositiveId($propertyId, 'ID cơ sở lưu trú');
        self::assertPositiveId($organizationId, 'ID tổ chức');

        $statement = $this->pdo->prepare(
            'UPDATE CoSoLuuTru SET
                LoaiCoSo = :type,
                TenCoSo = :name,
                TenTrongNguon = :sourceName,
                NguonTen = :nameSource,
                MaBanGhiNguon = :sourceRecordCode,
                Slug = :slug,
                DiaChi = :address,
                NguonDiaChi = :addressSource,
                DiaChiXacMinhLuc = :addressVerifiedAt,
                MoTa = :description,
                AnhJSON = :imagesJson,
                ViDo = :latitude,
                KinhDo = :longitude,
                NguonToaDo = :coordinatesSource,
                ToaDoXacMinhLuc = :coordinatesVerifiedAt,
                TrangThaiDoiChieu = :reconciliationStatus,
                DuongDanBanDo = :mapUrl,
                SoSao = :starRating,
                TyLeThue = :taxRate,
                ThueDaBaoGom = :taxIncluded,
                TyLePhiDichVu = :serviceFeeRate,
                PhiDichVuDaBaoGom = :serviceFeeIncluded,
                GioNhanPhong = :checkInTime,
                GioTraPhong = :checkOutTime,
                MuiGio = :timezone,
                TrangThai = :status,
                NguoiDuyetID = :reviewerId,
                DuyetLuc = :reviewedAt,
                GhiChuDuyet = :reviewNote
             WHERE CoSoLuuTruID = :propertyId AND ToChucDoiTacID = :organizationId'
        );
        $statement->execute([
            'propertyId' => $propertyId,
            'organizationId' => $organizationId,
            ...$data,
        ]);

        return $statement->rowCount() === 1;
    }

    public function transition(
        int $propertyId,
        PropertyStatus $expected,
        PropertyStatus $target,
        ?int $reviewerId,
        ?string $reviewNote,
    ): bool {
        self::assertPositiveId($propertyId, 'ID cơ sở lưu trú');
        if (!$expected->canTransitionTo($target)) {
            throw new InvalidArgumentException('Chuyển trạng thái cơ sở lưu trú không hợp lệ.');
        }
        if ($target->requiresReviewMetadata() && $reviewerId === null) {
            throw new InvalidArgumentException('Trạng thái đích yêu cầu người duyệt.');
        }

        $reviewedAt = $target->requiresReviewMetadata() ? gmdate('Y-m-d H:i:s.u') : null;
        $statement = $this->pdo->prepare(
            'UPDATE CoSoLuuTru
             SET TrangThai = :target, NguoiDuyetID = :reviewerId, DuyetLuc = :reviewedAt, GhiChuDuyet = :reviewNote
             WHERE CoSoLuuTruID = :propertyId AND TrangThai = :expected'
        );
        $statement->execute([
            'target' => $target->value,
            'reviewerId' => $reviewerId,
            'reviewedAt' => $reviewedAt,
            'reviewNote' => $reviewNote,
            'propertyId' => $propertyId,
            'expected' => $expected->value,
        ]);

        return $statement->rowCount() === 1;
    }

    private static function assertPositiveId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException($label . ' phải là số dương.');
        }
    }
}

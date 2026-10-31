<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use App\Modules\Search\Application\PropertySearchSnapshot;
use App\Modules\Search\Application\RoomProductSearchSnapshot;
use App\Shared\Contract\SearchCriteria;
use DateTimeImmutable;
use JsonException;
use PDO;

final readonly class SearchCatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<PropertySearchSnapshot> */
    public function findCandidates(SearchCriteria $criteria, DateTimeImmutable $bookedAt): array
    {
        return $this->load($criteria, $bookedAt, null);
    }

    public function findBySlug(string $slug, SearchCriteria $criteria, DateTimeImmutable $bookedAt): ?PropertySearchSnapshot
    {
        $matches = $this->load($criteria, $bookedAt, trim($slug));
        return $matches[0] ?? null;
    }

    /** @return list<PropertySearchSnapshot> */
    private function load(SearchCriteria $criteria, DateTimeImmutable $bookedAt, ?string $slug): array
    {
        $propertyRows = $this->propertyRows($criteria, $slug);
        if ($propertyRows === []) {
            return [];
        }

        $propertyIds = array_map(static fn (array $row): int => (int) $row['propertyId'], $propertyRows);
        $facilities = $this->propertyFacilities($propertyIds);
        $products = $this->roomProducts($propertyIds, $criteria, $bookedAt);

        $snapshots = [];
        foreach ($propertyRows as $row) {
            $propertyId = (int) $row['propertyId'];
            if (($products[$propertyId] ?? []) === []) {
                continue;
            }
            $snapshots[] = new PropertySearchSnapshot(
                $propertyId,
                (string) $row['slug'],
                (string) $row['name'],
                (string) $row['propertyType'],
                (string) $row['address'],
                $row['description'] === null ? null : (string) $row['description'],
                $this->jsonList((string) $row['imagesJson']),
                $row['starRating'] === null ? null : (string) $row['starRating'],
                (string) $row['taxRate'],
                (bool) $row['taxIncluded'],
                (string) $row['serviceFeeRate'],
                (bool) $row['serviceFeeIncluded'],
                (string) $row['checkInTime'],
                (string) $row['checkOutTime'],
                (string) $row['timezone'],
                $row['mapUrl'] === null ? null : (string) $row['mapUrl'],
                number_format((float) $row['reviewScore'], 1, '.', ''),
                (int) $row['reviewCount'],
                $facilities[$propertyId] ?? [],
                $products[$propertyId],
            );
        }
        return $snapshots;
    }

    /** @return list<array<string, mixed>> */
    private function propertyRows(SearchCriteria $criteria, ?string $slug): array
    {
        $params = [];
        $where = [
            "cs.MaThanhPho = 'HCM'",
            "cs.TrangThai = 'ACTIVE'",
            "tc.TrangThai = 'HOAT_DONG'",
        ];
        if ($slug !== null) {
            $where[] = 'cs.Slug = :slug';
            $params['slug'] = $slug;
        } elseif (!$this->isCityWideDestination($criteria->destination)) {
            $where[] = '(cs.TenCoSo LIKE :destinationName OR cs.DiaChi LIKE :destinationAddress)';
            $params['destinationName'] = '%' . $criteria->destination . '%';
            $params['destinationAddress'] = '%' . $criteria->destination . '%';
        }

        if (($criteria->filters['propertyTypes'] ?? []) !== []) {
            $where[] = 'cs.LoaiCoSo IN (' . $this->placeholders($params, 'propertyType', $criteria->filters['propertyTypes']) . ')';
        }
        if (($criteria->filters['starRatings'] ?? []) !== []) {
            $where[] = 'FLOOR(cs.SoSao) IN (' . $this->placeholders($params, 'starRating', $criteria->filters['starRatings']) . ')';
        }
        if (($criteria->filters['facilityIds'] ?? []) !== []) {
            $ids = $criteria->filters['facilityIds'];
            $where[] = '(SELECT COUNT(DISTINCT cstn.TienNghiID) FROM CoSoTienNghi cstn'
                . ' WHERE cstn.CoSoLuuTruID = cs.CoSoLuuTruID AND cstn.TienNghiID IN ('
                . $this->placeholders($params, 'facility', $ids) . ')) = ' . count($ids);
        }
        if (isset($criteria->filters['minReviewScore'])) {
            $where[] = 'COALESCE((SELECT AVG(dg.DiemTong) FROM DanhGia dg'
                . " WHERE dg.CoSoLuuTruID = cs.CoSoLuuTruID AND dg.TrangThai = 'PUBLISHED'), 0) >= :minReviewScore";
            $params['minReviewScore'] = $criteria->filters['minReviewScore'];
        }

        $statement = $this->pdo->prepare(
            'SELECT cs.CoSoLuuTruID AS propertyId, cs.Slug AS slug, cs.TenCoSo AS name,
                    cs.LoaiCoSo AS propertyType, cs.DiaChi AS address, cs.MoTa AS description,
                    cs.AnhJSON AS imagesJson, cs.SoSao AS starRating,
                    cs.TyLeThue AS taxRate, cs.ThueDaBaoGom AS taxIncluded,
                    cs.TyLePhiDichVu AS serviceFeeRate, cs.PhiDichVuDaBaoGom AS serviceFeeIncluded,
                    cs.GioNhanPhong AS checkInTime, cs.GioTraPhong AS checkOutTime,
                    cs.MuiGio AS timezone, cs.DuongDanBanDo AS mapUrl,
                    COALESCE((SELECT AVG(dg.DiemTong) FROM DanhGia dg
                        WHERE dg.CoSoLuuTruID = cs.CoSoLuuTruID AND dg.TrangThai = \'PUBLISHED\'), 0) AS reviewScore,
                    (SELECT COUNT(*) FROM DanhGia dg
                        WHERE dg.CoSoLuuTruID = cs.CoSoLuuTruID AND dg.TrangThai = \'PUBLISHED\') AS reviewCount
             FROM CoSoLuuTru cs
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY cs.CoSoLuuTruID ASC'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $propertyIds @return array<int, list<array<string, mixed>>> */
    private function propertyFacilities(array $propertyIds): array
    {
        $params = [];
        $statement = $this->pdo->prepare(
            'SELECT cstn.CoSoLuuTruID AS propertyId, tn.TienNghiID AS facilityId,
                    tn.MaTienNghi AS code, tn.TenTienNghi AS name, cstn.MienPhi AS free, cstn.GhiChu AS note
             FROM CoSoTienNghi cstn
             INNER JOIN TienNghi tn ON tn.TienNghiID = cstn.TienNghiID
             WHERE tn.TrangThai = \'HOAT_DONG\' AND tn.PhamVi = \'PROPERTY\'
               AND cstn.CoSoLuuTruID IN (' . $this->placeholders($params, 'propertyFacility', $propertyIds) . ')
             ORDER BY cstn.CoSoLuuTruID, tn.TienNghiID'
        );
        $statement->execute($params);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['propertyId']][] = [
                'id' => (int) $row['facilityId'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'free' => (bool) $row['free'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
            ];
        }
        return $map;
    }

    /**
     * @param list<int> $propertyIds
     * @return array<int, list<RoomProductSearchSnapshot>>
     */
    private function roomProducts(array $propertyIds, SearchCriteria $criteria, DateTimeImmutable $bookedAt): array
    {
        $params = [
            'checkIn' => $criteria->stayPeriod->checkIn,
            'checkOut' => $criteria->stayPeriod->checkOut,
        ];
        $where = [
            'lp.CoSoLuuTruID IN (' . $this->placeholders($params, 'productProperty', $propertyIds) . ')',
            "cs.TrangThai = 'ACTIVE'",
            "tc.TrangThai = 'HOAT_DONG'",
            "lp.TrangThai = 'ACTIVE'",
            "sp.TrangThai = 'ACTIVE'",
            "csh.TrangThai = 'ACTIVE'",
            'gpn.NgayLuuTru >= :checkIn',
            'gpn.NgayLuuTru < :checkOut',
            'gpn.DongBan = 0',
        ];
        if (($criteria->filters['mealTypes'] ?? []) !== []) {
            $where[] = 'sp.LoaiBuaAn IN (' . $this->placeholders($params, 'mealType', $criteria->filters['mealTypes']) . ')';
        }
        if (isset($criteria->filters['paymentTiming'])) {
            $where[] = 'sp.ThoiDiemThanhToan = :paymentTiming';
            $params['paymentTiming'] = $criteria->filters['paymentTiming'];
        }
        if (($criteria->filters['freeCancellation'] ?? false) === true) {
            $where[] = "csh.LoaiChinhSach = 'FLEXIBLE' AND csh.SoGioHuyMienPhi IS NOT NULL AND csh.SoGioHuyMienPhi > 0";
        }

        $statement = $this->pdo->prepare(
            'SELECT lp.CoSoLuuTruID AS propertyId, lp.LoaiPhongID AS roomTypeId,
                    lp.TenLoaiPhong AS roomTypeName, lp.MoTa AS roomDescription,
                    lp.SoKhachToiDa AS maximumGuests, lp.SoNguoiLonToiDa AS maximumAdults,
                    lp.SoTreEmToiDa AS maximumChildren, lp.CauHinhGiuongJSON AS bedConfigurationJson,
                    lp.DienTichM2 AS areaSquareMeters, lp.ChoPhepHutThuoc AS smokingAllowed,
                    lp.AnhJSON AS roomImagesJson,
                    sp.SanPhamPhongID AS productId, sp.TenSanPham AS productName,
                    sp.LoaiBuaAn AS mealType, sp.ThoiDiemThanhToan AS paymentTiming,
                    csh.ChinhSachHuyID AS cancellationPolicyId, csh.TenChinhSach AS cancellationPolicyName,
                    csh.LoaiChinhSach AS cancellationPolicyType, csh.SoGioHuyMienPhi AS freeCancellationHours,
                    csh.LoaiPhat AS penaltyType, csh.GiaTriPhat AS penaltyValue,
                    csh.TyLePhatNoShow AS noShowPenaltyRate,
                    gpn.NgayLuuTru AS stayDate, gpn.GiaCoBan AS basePrice,
                    gpn.SoDemToiThieu AS minimumStay, gpn.SoDemToiDa AS maximumStay,
                    gpn.SoNgayDatTruoc AS minimumLeadDays, gpn.PhienBan AS rateVersion,
                    tpn.TongSoLuong - tpn.SoLuongDaGiu AS available,
                    tpn.PhienBan AS inventoryVersion
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             INNER JOIN SanPhamPhong sp ON sp.LoaiPhongID = lp.LoaiPhongID
             INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID = sp.ChinhSachHuyID
                AND csh.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN GiaPhongNgay gpn ON gpn.SanPhamPhongID = sp.SanPhamPhongID
             INNER JOIN TonPhongNgay tpn ON tpn.LoaiPhongID = lp.LoaiPhongID
                AND tpn.NgayLuuTru = gpn.NgayLuuTru
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY lp.CoSoLuuTruID, lp.LoaiPhongID, sp.SanPhamPhongID, gpn.NgayLuuTru'
        );
        $statement->execute($params);

        $grouped = [];
        $roomTypeIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $propertyId = (int) $row['propertyId'];
            $productId = (int) $row['productId'];
            $roomTypeId = (int) $row['roomTypeId'];
            $date = (string) $row['stayDate'];
            $roomTypeIds[$roomTypeId] = $roomTypeId;
            $grouped[$propertyId][$productId]['row'] = $row;
            $grouped[$propertyId][$productId]['rates'][$date] = [
                'stayDate' => $date,
                'basePrice' => (string) $row['basePrice'],
                'closed' => false,
                'minimumStay' => (int) $row['minimumStay'],
                'maximumStay' => $row['maximumStay'] === null ? null : (int) $row['maximumStay'],
                'minimumLeadDays' => (int) $row['minimumLeadDays'],
                'version' => (int) $row['rateVersion'],
            ];
            $grouped[$propertyId][$productId]['inventory'][$date] = (int) $row['available'];
            $grouped[$propertyId][$productId]['inventoryVersions'][$date] = (int) $row['inventoryVersion'];
        }

        $roomFacilities = $this->roomFacilities(array_values($roomTypeIds));
        $promotions = $this->promotions($propertyIds, $criteria, $bookedAt);
        $propertyRows = $this->pricingPropertyRows($propertyIds);
        $requiredNights = $criteria->stayPeriod->nights();
        $result = [];
        foreach ($grouped as $propertyId => $propertyProducts) {
            foreach ($propertyProducts as $data) {
                $row = $data['row'];
                $rates = $data['rates'];
                $inventory = $data['inventory'];
                ksort($rates, SORT_STRING);
                ksort($inventory, SORT_STRING);
                if (array_keys($rates) !== $requiredNights || array_keys($inventory) !== $requiredNights) {
                    continue;
                }
                $minimumAvailable = min($inventory);
                if ($minimumAvailable < 1) {
                    continue;
                }
                $property = $propertyRows[(int) $propertyId];
                $cancellationSnapshot = [
                    'id' => (int) $row['cancellationPolicyId'],
                    'name' => (string) $row['cancellationPolicyName'],
                    'policyType' => (string) $row['cancellationPolicyType'],
                    'freeCancellationHours' => $row['freeCancellationHours'] === null ? null : (int) $row['freeCancellationHours'],
                    'penaltyType' => (string) $row['penaltyType'],
                    'penaltyValue' => (string) $row['penaltyValue'],
                    'noShowPenaltyRate' => (string) $row['noShowPenaltyRate'],
                ];
                $pricing = new ProductPricingSnapshot(
                    (string) $row['paymentTiming'],
                    (string) $property['timezone'],
                    (string) $property['taxRate'],
                    (bool) $property['taxIncluded'],
                    (string) $property['serviceFeeRate'],
                    (bool) $property['serviceFeeIncluded'],
                    array_values($rates),
                    $promotions[(int) $propertyId] ?? [],
                    $cancellationSnapshot,
                );
                $roomTypeId = (int) $row['roomTypeId'];
                $inventoryVersions = $data['inventoryVersions'];
                ksort($inventoryVersions, SORT_STRING);
                $result[(int) $propertyId][] = new RoomProductSearchSnapshot(
                    $roomTypeId,
                    (string) $row['roomTypeName'],
                    $row['roomDescription'] === null ? null : (string) $row['roomDescription'],
                    (int) $row['maximumGuests'],
                    (int) $row['maximumAdults'],
                    (int) $row['maximumChildren'],
                    $this->jsonObject((string) $row['bedConfigurationJson']),
                    $row['areaSquareMeters'] === null ? null : (string) $row['areaSquareMeters'],
                    (bool) $row['smokingAllowed'],
                    $this->jsonList((string) $row['roomImagesJson']),
                    $roomFacilities[$roomTypeId] ?? [],
                    (int) $row['productId'],
                    (string) $row['productName'],
                    (string) $row['mealType'],
                    (string) $row['paymentTiming'],
                    $pricing,
                    $minimumAvailable,
                    $inventoryVersions,
                );
            }
        }
        return $result;
    }

    /** @param list<int> $roomTypeIds @return array<int, list<array<string, mixed>>> */
    private function roomFacilities(array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }
        $params = [];
        $statement = $this->pdo->prepare(
            'SELECT lptn.LoaiPhongID AS roomTypeId, tn.TienNghiID AS facilityId,
                    tn.MaTienNghi AS code, tn.TenTienNghi AS name,
                    lptn.SoLuong AS quantity, lptn.GhiChu AS note
             FROM LoaiPhongTienNghi lptn
             INNER JOIN TienNghi tn ON tn.TienNghiID = lptn.TienNghiID
             WHERE tn.TrangThai = \'HOAT_DONG\' AND tn.PhamVi = \'ROOM\'
               AND lptn.LoaiPhongID IN (' . $this->placeholders($params, 'roomFacility', $roomTypeIds) . ')
             ORDER BY lptn.LoaiPhongID, tn.TienNghiID'
        );
        $statement->execute($params);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['roomTypeId']][] = [
                'id' => (int) $row['facilityId'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'quantity' => $row['quantity'] === null ? null : (int) $row['quantity'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
            ];
        }
        return $map;
    }

    /** @param list<int> $propertyIds @return array<int, list<array{id: int, discountRate: string}>> */
    private function promotions(array $propertyIds, SearchCriteria $criteria, DateTimeImmutable $bookedAt): array
    {
        $params = [
            'bookedAtFrom' => $bookedAt->format('Y-m-d H:i:s.u'),
            'bookedAtUntil' => $bookedAt->format('Y-m-d H:i:s.u'),
            'checkIn' => $criteria->stayPeriod->checkIn,
            'lastNight' => $criteria->stayPeriod->nights()[count($criteria->stayPeriod->nights()) - 1],
        ];
        $statement = $this->pdo->prepare(
            'SELECT CoSoLuuTruID AS propertyId, KhuyenMaiID AS promotionId, PhanTramGiam AS discountRate
             FROM KhuyenMai
             WHERE TrangThai = \'ACTIVE\'
               AND CoSoLuuTruID IN (' . $this->placeholders($params, 'promotionProperty', $propertyIds) . ')
               AND DatTu <= :bookedAtFrom AND DatDen >= :bookedAtUntil
               AND LuuTruTu <= :checkIn AND LuuTruDen >= :lastNight
             ORDER BY CoSoLuuTruID, PhanTramGiam DESC, KhuyenMaiID ASC'
        );
        $statement->execute($params);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['propertyId']][] = [
                'id' => (int) $row['promotionId'],
                'discountRate' => (string) $row['discountRate'],
            ];
        }
        return $map;
    }

    /** @param list<int> $propertyIds @return array<int, array<string, mixed>> */
    private function pricingPropertyRows(array $propertyIds): array
    {
        $params = [];
        $statement = $this->pdo->prepare(
            'SELECT CoSoLuuTruID AS propertyId, MuiGio AS timezone,
                    TyLeThue AS taxRate, ThueDaBaoGom AS taxIncluded,
                    TyLePhiDichVu AS serviceFeeRate, PhiDichVuDaBaoGom AS serviceFeeIncluded
             FROM CoSoLuuTru
             WHERE CoSoLuuTruID IN (' . $this->placeholders($params, 'pricingProperty', $propertyIds) . ')'
        );
        $statement->execute($params);
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['propertyId']] = $row;
        }
        return $map;
    }

    /** @param array<string, mixed> $params @param list<mixed> $values */
    private function placeholders(array &$params, string $prefix, array $values): string
    {
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return implode(',', $placeholders);
    }

    private function isCityWideDestination(string $destination): bool
    {
        $normalized = mb_strtolower(trim($destination));
        return in_array($normalized, [
            'hcm', 'tp.hcm', 'tp hcm', 'tphcm', 'thành phố hồ chí minh',
            'thanh pho ho chi minh', 'hồ chí minh', 'ho chi minh', 'sài gòn', 'sai gon', 'saigon',
        ], true);
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Application\ReportCriteria;
use PDO;

final readonly class ReportRepository
{
    private const BOOKED_STATUSES = "'CONFIRMED','COMPLETED','NO_SHOW'";
    private const DUE_STATUSES = "'CONFIRMED','COMPLETED','CANCELLED','NO_SHOW'";

    public function __construct(private PDO $pdo)
    {
    }

    public function organizationExists(int $organizationId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM ToChucDoiTac WHERE ToChucDoiTacID=?');
        $statement->execute([$organizationId]);
        return $statement->fetchColumn() !== false;
    }

    public function propertyInScope(int $propertyId, ?int $organizationId): bool
    {
        $sql = 'SELECT 1 FROM CoSoLuuTru WHERE CoSoLuuTruID=:propertyId';
        $params = ['propertyId' => $propertyId];
        if ($organizationId !== null) {
            $sql .= ' AND ToChucDoiTacID=:organizationId';
            $params['organizationId'] = $organizationId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn() !== false;
    }

    /** @return array{organizations: list<array<string,mixed>>, properties: list<array<string,mixed>>} */
    public function filterOptions(?int $organizationId): array
    {
        $organizations = $this->pdo->query(
            'SELECT ToChucDoiTacID AS id, MaToChuc AS code, TenToChuc AS name
             FROM ToChucDoiTac ORDER BY TenToChuc, ToChucDoiTacID'
        )->fetchAll(PDO::FETCH_ASSOC);
        $sql = 'SELECT CoSoLuuTruID AS id, ToChucDoiTacID AS organizationId, MaCoSo AS code, TenCoSo AS name
                FROM CoSoLuuTru';
        $params = [];
        if ($organizationId !== null) {
            $sql .= ' WHERE ToChucDoiTacID=:organizationId';
            $params['organizationId'] = $organizationId;
        }
        $sql .= ' ORDER BY TenCoSo, CoSoLuuTruID';
        $properties = $this->pdo->prepare($sql);
        $properties->execute($params);
        return ['organizations' => $organizations, 'properties' => $properties->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @return array<string, mixed> */
    public function snapshot(ReportCriteria $criteria, ?int $forcedOrganizationId): array
    {
        $organizationId = $forcedOrganizationId ?? $criteria->organizationId;
        $params = ['from' => $criteria->from, 'to' => $criteria->to];
        [$scope, $scopeParams] = $this->scope('cs', $organizationId, $criteria->propertyId);
        $params += $scopeParams;

        $status = $this->pdo->prepare(
            'SELECT dc.TrangThai AS status, COUNT(*) AS bookingCount, COALESCE(SUM(dc.TongTien),0) AS bookingValue
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.NgayNhanPhong BETWEEN :from AND :to' . $scope . '
             GROUP BY dc.TrangThai ORDER BY dc.TrangThai'
        );
        $status->execute($params);

        $money = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN dc.TrangThai IN (' . self::BOOKED_STATUSES . ') THEN dc.TongTien ELSE 0 END),0) AS bookedValue,
                COALESCE(AVG(CASE WHEN dc.TrangThai IN (' . self::BOOKED_STATUSES . ') THEN dc.TongTien END),0) AS averageBookedValue,
                COALESCE(SUM(CASE WHEN tt.ThoiDiemThanhToan=\'PAY_ONLINE\' THEN tt.DaThanhToan ELSE 0 END),0) AS onlineGrossPaid,
                COALESCE(SUM(CASE WHEN tt.ThoiDiemThanhToan=\'PAY_ONLINE\' THEN tt.DaHoan ELSE 0 END),0) AS onlineRefunded,
                COALESCE(SUM(CASE WHEN tt.ThoiDiemThanhToan=\'PAY_ONLINE\' THEN tt.DaThanhToan-tt.DaHoan ELSE 0 END),0) AS onlineNetCollected,
                COALESCE(SUM(CASE WHEN dc.ThoiDiemThanhToan=\'PAY_AT_PROPERTY\' AND dc.TrangThai=\'CANCELLED\' THEN dc.PhiHuy ELSE 0 END),0) AS assessedCancellationFee,
                COALESCE(SUM(CASE WHEN dc.ThoiDiemThanhToan=\'PAY_AT_PROPERTY\' AND dc.TrangThai=\'NO_SHOW\' THEN dc.PhiNoShow ELSE 0 END),0) AS assessedNoShowFee
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.NgayNhanPhong BETWEEN :from AND :to' . $scope
        );
        $money->execute($params);

        $rates = $this->pdo->prepare(
            'SELECT
                SUM(CASE WHEN dc.TrangThai IN (' . self::DUE_STATUSES . ') THEN 1 ELSE 0 END) AS dueBookings,
                SUM(CASE WHEN dc.TrangThai=\'CANCELLED\' THEN 1 ELSE 0 END) AS cancelledBookings,
                SUM(CASE WHEN dc.TrangThai=\'NO_SHOW\' THEN 1 ELSE 0 END) AS noShowBookings
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.NgayNhanPhong BETWEEN :from AND :to' . $scope
        );
        $rates->execute($params);

        $bookedNights = $this->pdo->prepare(
            'SELECT COUNT(*) FROM GiaDemDatCho gd
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID
             INNER JOIN DatCho dc ON dc.DatChoID=hm.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE gd.NgayLuuTru BETWEEN :from AND :to AND dc.TrangThai IN (' . self::BOOKED_STATUSES . ')' . $scope
        );
        $bookedNights->execute($params);

        $offeredNights = $this->pdo->prepare(
            'SELECT COALESCE(SUM(tpn.TongSoLuong),0) FROM TonPhongNgay tpn
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID=tpn.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=lp.CoSoLuuTruID
             WHERE tpn.NgayLuuTru BETWEEN :from AND :to' . $scope . '
               AND EXISTS (
                   SELECT 1 FROM SanPhamPhong sp
                   INNER JOIN GiaPhongNgay gpn ON gpn.SanPhamPhongID=sp.SanPhamPhongID
                       AND gpn.NgayLuuTru=tpn.NgayLuuTru
                   WHERE sp.LoaiPhongID=lp.LoaiPhongID AND sp.TrangThai=\'ACTIVE\' AND gpn.DongBan=0
               )'
        );
        $offeredNights->execute($params);

        $reviews = $this->pdo->prepare(
            'SELECT COUNT(*) AS publishedCount, COALESCE(AVG(dg.DiemTong),0) AS averageScore
             FROM DanhGia dg INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dg.CoSoLuuTruID
             WHERE dg.TrangThai=\'PUBLISHED\' AND DATE(dg.TaoLuc) BETWEEN :from AND :to' . $scope
        );
        $reviews->execute($params);

        $promotions = $this->pdo->prepare(
            'SELECT COUNT(*) AS promotedRoomNights, COUNT(DISTINCT dc.DatChoID) AS promotedBookings
             FROM GiaDemDatCho gd
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID
             INNER JOIN DatCho dc ON dc.DatChoID=hm.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE gd.KhuyenMaiID IS NOT NULL AND gd.NgayLuuTru BETWEEN :from AND :to
               AND dc.TrangThai IN (' . self::BOOKED_STATUSES . ')' . $scope
        );
        $promotions->execute($params);

        $booked = (int) $bookedNights->fetchColumn();
        $offered = (int) $offeredNights->fetchColumn();
        $rateRow = $rates->fetch(PDO::FETCH_ASSOC) ?: [];
        $due = (int) ($rateRow['dueBookings'] ?? 0);

        return [
            'criteria' => ['from' => $criteria->from, 'to' => $criteria->to,
                'organizationId' => $organizationId, 'propertyId' => $criteria->propertyId],
            'bookingByStatus' => $status->fetchAll(PDO::FETCH_ASSOC),
            'money' => $money->fetch(PDO::FETCH_ASSOC) ?: [],
            'rates' => [
                'dueBookings' => $due,
                'cancelledBookings' => (int) ($rateRow['cancelledBookings'] ?? 0),
                'noShowBookings' => (int) ($rateRow['noShowBookings'] ?? 0),
                'cancellationRate' => $due === 0 ? 0.0 : (int) $rateRow['cancelledBookings'] * 100 / $due,
                'noShowRate' => $due === 0 ? 0.0 : (int) $rateRow['noShowBookings'] * 100 / $due,
            ],
            'inventory' => [
                'bookedRoomNights' => $booked,
                'offeredRoomNights' => $offered,
                'soldOnOfferedRate' => $offered === 0 ? 0.0 : $booked * 100 / $offered,
            ],
            'reviews' => $reviews->fetch(PDO::FETCH_ASSOC) ?: [],
            'promotions' => $promotions->fetch(PDO::FETCH_ASSOC) ?: [],
            'properties' => $this->propertyBreakdown($criteria, $organizationId),
        ];
    }

    /** @return array<string, mixed> */
    public function adminOperationalSummary(ReportCriteria $criteria): array
    {
        $property = $this->pdo->query(
            'SELECT TrangThai AS status, COUNT(*) AS propertyCount FROM CoSoLuuTru GROUP BY TrangThai ORDER BY TrangThai'
        )->fetchAll(PDO::FETCH_ASSOC);
        $waiver = $this->pdo->prepare(
            'SELECT COUNT(*) AS totalRequests,
                    SUM(CASE WHEN TrangThai=\'PENDING\' THEN 1 ELSE 0 END) AS pendingRequests,
                    COALESCE(AVG(CASE WHEN XuLyLuc IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,TaoLuc,XuLyLuc) END),0) AS averageResolutionMinutes
             FROM YeuCauHuyMienPhi WHERE DATE(TaoLuc) BETWEEN :from AND :to'
        );
        $waiver->execute(['from' => $criteria->from, 'to' => $criteria->to]);
        $mismatch = $this->pdo->query(
            "SELECT COUNT(*) FROM TonPhongNgay tpn
             WHERE tpn.SoLuongDaGiu <> (
                SELECT COUNT(*) FROM GiuTonPhongDem gt
                WHERE gt.LoaiPhongID=tpn.LoaiPhongID AND gt.NgayLuuTru=tpn.NgayLuuTru AND gt.TrangThai='ACTIVE'
             )"
        )->fetchColumn();
        return [
            'propertyByStatus' => $property,
            'waivers' => $waiver->fetch(PDO::FETCH_ASSOC) ?: [],
            'inventoryMismatchCount' => (int) $mismatch,
        ];
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function paymentIssues(int $page, int $perPage): array
    {
        $where = "tt.ThoiDiemThanhToan='PAY_ONLINE' AND ((tt.TrangThai='FAILED' AND tt.MaLoiCuoi IS NOT NULL)
                  OR tt.TrangThaiHoanTien IN ('PENDING','FAILED'))";
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM ThanhToan tt WHERE ' . $where)->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $rows = $this->pdo->query(
            'SELECT tt.ThanhToanID AS id, dc.MaDatCho AS bookingCode,
                    cs.TenCoSo AS propertyName, tc.TenToChuc AS organizationName,
                    tt.TrangThai AS paymentStatus, tt.DaThanhToan AS paid,
                    tt.DaHoan AS refunded, tt.MaLoiCuoi AS lastError,
                    tt.TrangThaiHoanTien AS refundStatus, tt.LyDoHoanTien AS refundReason,
                    tt.SoLanThu AS attempts, tt.CapNhatLuc AS updatedAt
             FROM ThanhToan tt INNER JOIN DatCho dc ON dc.DatChoID=tt.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID=cs.ToChucDoiTacID
             WHERE ' . $where . ' ORDER BY tt.CapNhatLuc DESC, tt.ThanhToanID DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => $rows, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    private function propertyBreakdown(ReportCriteria $criteria, ?int $organizationId): array
    {
        $params = ['from' => $criteria->from, 'to' => $criteria->to];
        [$scope, $scopeParams] = $this->scope('cs', $organizationId, $criteria->propertyId);
        $params += $scopeParams;
        $statement = $this->pdo->prepare(
            'SELECT cs.CoSoLuuTruID AS id, cs.ToChucDoiTacID AS organizationId,
                    cs.TenCoSo AS name, tc.TenToChuc AS organizationName,
                    COUNT(DISTINCT CASE WHEN dc.TrangThai IN (' . self::BOOKED_STATUSES . ') THEN dc.DatChoID END) AS bookings,
                    COALESCE(SUM(CASE WHEN dc.TrangThai IN (' . self::BOOKED_STATUSES . ') THEN dc.TongTien ELSE 0 END),0) AS bookedValue
             FROM CoSoLuuTru cs INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID=cs.ToChucDoiTacID
             LEFT JOIN DatCho dc ON dc.CoSoLuuTruID=cs.CoSoLuuTruID AND dc.NgayNhanPhong BETWEEN :from AND :to
             WHERE 1=1' . $scope . '
             GROUP BY cs.CoSoLuuTruID,cs.ToChucDoiTacID,cs.TenCoSo,tc.TenToChuc
             ORDER BY bookedValue DESC,cs.CoSoLuuTruID LIMIT 100'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{string, array<string,int>} */
    private function scope(string $alias, ?int $organizationId, ?int $propertyId): array
    {
        $sql = '';
        $params = [];
        if ($organizationId !== null) {
            $sql .= ' AND ' . $alias . '.ToChucDoiTacID=:organizationId';
            $params['organizationId'] = $organizationId;
        }
        if ($propertyId !== null) {
            $sql .= ' AND ' . $alias . '.CoSoLuuTruID=:propertyId';
            $params['propertyId'] = $propertyId;
        }
        return [$sql, $params];
    }
}

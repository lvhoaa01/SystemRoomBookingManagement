<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure;

use App\Modules\Reports\Domain\RevenueSettlement;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class RevenueSettlementRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function propertyBelongsTo(int $propertyId, int $organizationId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM CoSoLuuTru WHERE CoSoLuuTruID=? AND ToChucDoiTacID=?'
        );
        $statement->execute([$propertyId, $organizationId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{id:int, name:string}> */
    public function properties(int $organizationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT CoSoLuuTruID AS id, TenCoSo AS name FROM CoSoLuuTru
             WHERE ToChucDoiTacID=? ORDER BY TenCoSo, CoSoLuuTruID'
        );
        $statement->execute([$organizationId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function sourceLines(int $propertyId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            "SELECT h.HoaDonID AS invoiceId, h.SoHoaDon AS invoiceNumber,
                    b.MaDatCho AS bookingCode, b.ThoiDiemThanhToan AS paymentTiming,
                    h.TongTien AS total, p.DaHoan AS refunded, h.PhatHanhLuc AS issuedAt,
                    CASE WHEN b.ThoiDiemThanhToan='PAY_ONLINE' THEN 'ONLINE'
                         ELSE COALESCE(g.PhuongThuc, 'CASH') END AS collectionChannel,
                    h.SnapshotJSON AS invoiceSnapshot
             FROM HoaDon h
             INNER JOIN DatCho b ON b.DatChoID=h.DatChoID
             INNER JOIN ThanhToan p ON p.DatChoID=b.DatChoID
             LEFT JOIN GiaoDichThanhToan g ON g.ThanhToanID=p.ThanhToanID
                  AND g.LoaiGiaoDich='COLLECTION' AND g.TrangThai='SUCCEEDED'
             WHERE h.CoSoLuuTruID=:propertyId AND h.TrangThai='ISSUED'
               AND DATE(h.PhatHanhLuc) BETWEEN :from AND :to
             ORDER BY h.PhatHanhLuc, h.HoaDonID"
        );
        $statement->execute(['propertyId' => $propertyId, 'from' => $from, 'to' => $to]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hasFinalizedOverlap(int $propertyId, string $from, string $to, ?int $exceptId = null): bool
    {
        $sql = "SELECT 1 FROM KyDoiSoat
                WHERE CoSoLuuTruID=:propertyId AND TrangThai='FINALIZED'
                  AND TuNgay<=:to AND DenNgay>=:from";
        $params = ['propertyId' => $propertyId, 'from' => $from, 'to' => $to];
        if ($exceptId !== null) {
            $sql .= ' AND KyDoiSoatID<>:exceptId';
            $params['exceptId'] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        return $statement->fetchColumn() !== false;
    }

    /** @param list<array<string, mixed>> $lines */
    public function saveDraft(
        int $organizationId,
        int $propertyId,
        string $from,
        string $to,
        array $lines,
        int $actorId,
        DateTimeImmutable $at,
    ): RevenueSettlement {
        $totals = $this->totals($lines);
        $select = $this->pdo->prepare(
            'SELECT KyDoiSoatID, TrangThai FROM KyDoiSoat
             WHERE CoSoLuuTruID=? AND TuNgay=? AND DenNgay=? FOR UPDATE'
        );
        $select->execute([$propertyId, $from, $to]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing) && $existing['TrangThai'] === 'FINALIZED') {
            throw new RuntimeException('Kỳ đối soát đã chốt nên không thể tạo lại.');
        }
        $parameters = [
            'organizationId' => $organizationId, 'propertyId' => $propertyId,
            'from' => $from, 'to' => $to, 'bookingCount' => count($lines),
            'gross' => $totals['gross']->decimal(), 'online' => $totals['online']->decimal(),
            'atProperty' => $totals['atProperty']->decimal(), 'refunded' => $totals['refunded']->decimal(),
            'net' => $totals['net']->decimal(), 'actorId' => $actorId, 'at' => self::utc($at),
        ];
        if (is_array($existing)) {
            $settlementId = (int) $existing['KyDoiSoatID'];
            $parameters['id'] = $settlementId;
            $this->pdo->prepare(
                "UPDATE KyDoiSoat SET ToChucDoiTacID=:organizationId, SoDatCho=:bookingCount,
                    DoanhThuGop=:gross, ThuTrucTuyen=:online, ThuTaiCoSo=:atProperty,
                    DaHoan=:refunded, DoanhThuThuan=:net, NguoiTaoID=:actorId,
                    TaoLuc=:at, CapNhatLuc=:at WHERE KyDoiSoatID=:id AND TrangThai='DRAFT'"
            )->execute($parameters);
            $this->pdo->prepare('DELETE FROM ChiTietDoiSoat WHERE KyDoiSoatID=?')->execute([$settlementId]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO KyDoiSoat
                    (ToChucDoiTacID, CoSoLuuTruID, TuNgay, DenNgay, TrangThai, TienTe,
                     SoDatCho, DoanhThuGop, ThuTrucTuyen, ThuTaiCoSo, DaHoan, DoanhThuThuan,
                     NguoiTaoID, TaoLuc)
                 VALUES (:organizationId,:propertyId,:from,:to,'DRAFT','VND',:bookingCount,
                         :gross,:online,:atProperty,:refunded,:net,:actorId,:at)"
            )->execute($parameters);
            $settlementId = (int) $this->pdo->lastInsertId();
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO ChiTietDoiSoat
                (KyDoiSoatID,HoaDonID,MaDatCho,SoHoaDon,ThoiDiemThanhToan,KenhThu,
                 TongTien,DaHoan,DoanhThuThuan,PhatHanhLuc,SnapshotJSON)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($lines as $line) {
            $total = Money::vnd((string) $line['total']);
            $refunded = Money::vnd((string) $line['refunded']);
            $insert->execute([
                $settlementId, $line['invoiceId'], $line['bookingCode'], $line['invoiceNumber'],
                $line['paymentTiming'], $line['collectionChannel'], $total->decimal(),
                $refunded->decimal(), $total->subtract($refunded)->decimal(), $line['issuedAt'],
                CanonicalJson::encode([
                    'version' => 1,
                    'invoice' => $this->decode((string) $line['invoiceSnapshot']),
                    'collectionChannel' => $line['collectionChannel'],
                ]),
            ]);
        }
        return $this->findForOrganization($settlementId, $organizationId)
            ?? throw new RuntimeException('Không thể đọc kỳ đối soát vừa tạo.');
    }

    public function finalize(int $id, int $organizationId, int $actorId, DateTimeImmutable $at): RevenueSettlement
    {
        $statement = $this->pdo->prepare(
            'SELECT TrangThai FROM KyDoiSoat WHERE KyDoiSoatID=? AND ToChucDoiTacID=? FOR UPDATE'
        );
        $statement->execute([$id, $organizationId]);
        $status = $statement->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('Không tìm thấy kỳ đối soát.');
        }
        if ($status === 'DRAFT') {
            $this->pdo->prepare(
                "UPDATE KyDoiSoat SET TrangThai='FINALIZED', NguoiChotID=?, ChotLuc=?, CapNhatLuc=?
                 WHERE KyDoiSoatID=? AND TrangThai='DRAFT'"
            )->execute([$actorId, self::utc($at), self::utc($at), $id]);
        }
        return $this->findForOrganization($id, $organizationId)
            ?? throw new RuntimeException('Không thể đọc kỳ đối soát đã chốt.');
    }

    /** @return list<RevenueSettlement> */
    public function listForOrganization(int $organizationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT KyDoiSoatID FROM KyDoiSoat WHERE ToChucDoiTacID=? ORDER BY DenNgay DESC, KyDoiSoatID DESC LIMIT 100'
        );
        $statement->execute([$organizationId]);
        return array_values(array_filter(array_map(
            fn (string|int $id): ?RevenueSettlement => $this->findForOrganization((int) $id, $organizationId),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        )));
    }

    public function findForOrganization(int $id, int $organizationId): ?RevenueSettlement
    {
        $statement = $this->pdo->prepare(
            'SELECT k.*, c.TenCoSo AS PropertyName FROM KyDoiSoat k
             INNER JOIN CoSoLuuTru c ON c.CoSoLuuTruID=k.CoSoLuuTruID
             WHERE k.KyDoiSoatID=? AND k.ToChucDoiTacID=?'
        );
        $statement->execute([$id, $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lines = $this->pdo->prepare(
            'SELECT HoaDonID AS invoiceId, MaDatCho AS bookingCode, SoHoaDon AS invoiceNumber,
                    ThoiDiemThanhToan AS paymentTiming, KenhThu AS collectionChannel,
                    TongTien AS total, DaHoan AS refunded, DoanhThuThuan AS net,
                    PhatHanhLuc AS issuedAt FROM ChiTietDoiSoat WHERE KyDoiSoatID=?
             ORDER BY PhatHanhLuc, ChiTietDoiSoatID'
        );
        $lines->execute([$id]);
        return new RevenueSettlement(
            (int) $row['KyDoiSoatID'], (int) $row['ToChucDoiTacID'], (int) $row['CoSoLuuTruID'],
            (string) $row['PropertyName'], (string) $row['TuNgay'], (string) $row['DenNgay'],
            (string) $row['TrangThai'], (int) $row['SoDatCho'], Money::vnd((string) $row['DoanhThuGop']),
            Money::vnd((string) $row['ThuTrucTuyen']), Money::vnd((string) $row['ThuTaiCoSo']),
            Money::vnd((string) $row['DaHoan']), Money::vnd((string) $row['DoanhThuThuan']),
            self::date((string) $row['TaoLuc']),
            $row['ChotLuc'] === null ? null : self::date((string) $row['ChotLuc']),
            $lines->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @param list<array<string, mixed>> $lines @return array{gross:Money,online:Money,atProperty:Money,refunded:Money,net:Money} */
    private function totals(array $lines): array
    {
        $gross = $online = $atProperty = $refunded = Money::vnd(0);
        foreach ($lines as $line) {
            $total = Money::vnd((string) $line['total']);
            $refund = Money::vnd((string) $line['refunded']);
            $gross = $gross->add($total);
            $refunded = $refunded->add($refund);
            if ($line['paymentTiming'] === 'PAY_ONLINE') {
                $online = $online->add($total);
            } else {
                $atProperty = $atProperty->add($total);
            }
        }
        return ['gross' => $gross, 'online' => $online, 'atProperty' => $atProperty,
            'refunded' => $refunded, 'net' => $gross->subtract($refunded)];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot invoice đối soát không hợp lệ.', 0, $exception);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

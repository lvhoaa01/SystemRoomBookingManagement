<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Domain\Invoice;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class InvoiceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByBookingId(int $bookingId): ?Invoice
    {
        return $this->find('hd.DatChoID=:value', ['value' => $bookingId]);
    }

    public function findForCustomer(string $bookingCode, int $customerId): ?Invoice
    {
        return $this->find(
            'dc.MaDatCho=:value AND hd.KhachHangID=:customerId',
            ['value' => $bookingCode, 'customerId' => $customerId],
        );
    }

    public function findForOrganization(string $bookingCode, int $organizationId): ?Invoice
    {
        return $this->find(
            'dc.MaDatCho=:value AND hd.ToChucDoiTacID=:organizationId',
            ['value' => $bookingCode, 'organizationId' => $organizationId],
        );
    }

    /** @return array<string, mixed> */
    public function source(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT dc.DatChoID AS bookingId, dc.MaDatCho AS bookingCode,
                    dc.KhachHangID AS customerId, dc.CoSoLuuTruID AS propertyId,
                    cs.ToChucDoiTacID AS organizationId, cs.TenCoSo AS propertyName,
                    cs.DiaChi AS propertyAddress, NULL AS propertyTaxCode,
                    dc.NgayNhanPhong AS checkIn, dc.NgayTraPhong AS checkOut,
                    dc.TenNguoiDat AS bookerName, dc.EmailNguoiDat AS bookerEmail,
                    dc.DienThoaiNguoiDat AS bookerPhone, dc.TongTien AS total,
                    dc.TrangThai AS status,
                    tt.DaThanhToan AS paid, tt.DaHoan AS refunded,
                    tt.ThoiDiemThanhToan AS paymentTiming, tt.TrangThai AS paymentStatus
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.DatChoID=?"
        );
        $statement->execute([$bookingId]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source)) {
            throw new RuntimeException('Không tìm thấy dữ liệu nguồn để phát hành hóa đơn.');
        }
        $items = $this->pdo->prepare(
            'SELECT HangMucDatChoID AS id, ThuTuPhong AS roomOrder,
                    TenLoaiPhongSnapshot AS roomTypeName, TenSanPhamSnapshot AS productName,
                    LoaiBuaAnSnapshot AS mealType, TongTien AS total,
                    KhachJSON AS partyJson, SnapshotDieuKienJSON AS conditionsJson
             FROM HangMucDatCho WHERE DatChoID=? ORDER BY ThuTuPhong'
        );
        $items->execute([$bookingId]);
        $source['items'] = array_map(function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['roomOrder'] = (int) $item['roomOrder'];
            $item['party'] = $this->decode((string) $item['partyJson']);
            $item['conditions'] = $this->decode((string) $item['conditionsJson']);
            unset($item['partyJson'], $item['conditionsJson']);
            return $item;
        }, $items->fetchAll(PDO::FETCH_ASSOC));
        return $source;
    }

    /** @param array<string, mixed> $source */
    public function create(array $source, ?int $actorId, DateTimeImmutable $at): Invoice
    {
        $bookingId = (int) $source['bookingId'];
        $number = 'SB-' . $at->setTimezone(new DateTimeZone('UTC'))->format('Y')
            . '-' . str_pad((string) $bookingId, 8, '0', STR_PAD_LEFT);
        $snapshot = [
            'version' => 1,
            'bookingCode' => $source['bookingCode'],
            'property' => [
                'id' => (int) $source['propertyId'],
                'name' => $source['propertyName'],
                'address' => $source['propertyAddress'],
                'taxCode' => $source['propertyTaxCode'],
            ],
            'booker' => [
                'name' => $source['bookerName'],
                'email' => $source['bookerEmail'],
                'phone' => $source['bookerPhone'],
            ],
            'stay' => ['checkIn' => $source['checkIn'], 'checkOut' => $source['checkOut']],
            'paymentTiming' => $source['paymentTiming'],
            'items' => $source['items'],
        ];
        $statement = $this->pdo->prepare(
            "INSERT INTO HoaDon
                (SoHoaDon, DatChoID, ToChucDoiTacID, CoSoLuuTruID, KhachHangID,
                 TrangThai, TienTe, TongTien, DaThanhToan, PhatHanhLuc,
                 NguoiPhatHanhID, SnapshotJSON)
             VALUES (:number, :bookingId, :organizationId, :propertyId, :customerId,
                     'ISSUED', 'VND', :total, :paid, :issuedAt, :actorId, :snapshot)"
        );
        $statement->execute([
            'number' => $number,
            'bookingId' => $bookingId,
            'organizationId' => $source['organizationId'],
            'propertyId' => $source['propertyId'],
            'customerId' => $source['customerId'],
            'total' => $source['total'],
            'paid' => $source['paid'],
            'issuedAt' => self::utc($at),
            'actorId' => $actorId,
            'snapshot' => CanonicalJson::encode($snapshot),
        ]);
        $invoiceId = (int) $this->pdo->lastInsertId();
        $lineStatement = $this->pdo->prepare(
            'INSERT INTO ChiTietHoaDon
                (HoaDonID, ThuTu, MoTa, SoLuong, DonGia, ThanhTien, SnapshotJSON)
             VALUES (:invoiceId, :position, :description, 1, :unitPrice, :lineTotal, :snapshot)'
        );
        foreach ($source['items'] as $item) {
            $lineStatement->execute([
                'invoiceId' => $invoiceId,
                'position' => $item['roomOrder'],
                'description' => $item['roomTypeName'] . ' — ' . $item['productName'],
                'unitPrice' => $item['total'],
                'lineTotal' => $item['total'],
                'snapshot' => CanonicalJson::encode($item),
            ]);
        }
        $invoice = $this->findByBookingId($bookingId);
        if (!$invoice instanceof Invoice) {
            throw new RuntimeException('Không thể đọc hóa đơn vừa phát hành.');
        }
        return $invoice;
    }

    /** @param array<string, int|string> $parameters */
    private function find(string $where, array $parameters): ?Invoice
    {
        $statement = $this->pdo->prepare(
            "SELECT hd.HoaDonID AS id, hd.SoHoaDon AS number, hd.DatChoID AS bookingId,
                    dc.MaDatCho AS bookingCode, hd.KhachHangID AS customerId,
                    hd.ToChucDoiTacID AS organizationId, hd.CoSoLuuTruID AS propertyId,
                    cs.TenCoSo AS propertyName, hd.TongTien AS total, hd.DaThanhToan AS paid,
                    hd.PhatHanhLuc AS issuedAt, hd.SnapshotJSON AS snapshotJson
             FROM HoaDon hd
             INNER JOIN DatCho dc ON dc.DatChoID=hd.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=hd.CoSoLuuTruID
             WHERE {$where}"
        );
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $lines = $this->pdo->prepare(
            'SELECT ThuTu AS position, MoTa AS description, SoLuong AS quantity,
                    DonGia AS unitPrice, ThanhTien AS lineTotal, SnapshotJSON AS snapshotJson
             FROM ChiTietHoaDon WHERE HoaDonID=? ORDER BY ThuTu'
        );
        $lines->execute([(int) $row['id']]);
        $invoiceLines = array_map(fn (array $line): array => [
            'position' => (int) $line['position'],
            'description' => (string) $line['description'],
            'quantity' => (int) $line['quantity'],
            'unitPrice' => (string) $line['unitPrice'],
            'lineTotal' => (string) $line['lineTotal'],
            'snapshot' => $this->decode((string) $line['snapshotJson']),
        ], $lines->fetchAll(PDO::FETCH_ASSOC));
        return new Invoice(
            (int) $row['id'],
            (string) $row['number'],
            (int) $row['bookingId'],
            (string) $row['bookingCode'],
            (int) $row['customerId'],
            (int) $row['organizationId'],
            (int) $row['propertyId'],
            (string) $row['propertyName'],
            Money::vnd((string) $row['total']),
            Money::vnd((string) $row['paid']),
            new DateTimeImmutable((string) $row['issuedAt'], new DateTimeZone('UTC')),
            $invoiceLines,
            $this->decode((string) $row['snapshotJson']),
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot hóa đơn không hợp lệ.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot hóa đơn phải là object.');
        }
        return $value;
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

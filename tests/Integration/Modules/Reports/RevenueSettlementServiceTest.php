<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Reports;

use App\Bootstrap\App;
use App\Modules\Reports\Application\RevenueSettlementRequest;
use App\Modules\Reports\Application\RevenueSettlementService;
use App\Modules\Reports\Infrastructure\RevenueSettlementRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class RevenueSettlementServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_partner_generates_finalizes_and_cannot_overlap_settlement(): void
    {
        $booking = $this->row(
            "SELECT b.*, c.ToChucDoiTacID organizationId,
                    (SELECT MIN(m.NguoiDungID) FROM ThanhVienDoiTac m
                     WHERE m.ToChucDoiTacID=c.ToChucDoiTacID) actorId
             FROM DatCho b
             INNER JOIN CoSoLuuTru c ON c.CoSoLuuTruID=b.CoSoLuuTruID
             INNER JOIN ThanhToan p ON p.DatChoID=b.DatChoID
             LEFT JOIN HoaDon h ON h.DatChoID=b.DatChoID
             WHERE b.TrangThai='COMPLETED' AND p.TrangThai='PAID' AND p.DaHoan=0
               AND h.HoaDonID IS NULL
             ORDER BY b.DatChoID LIMIT 1"
        );
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-04T08:30:00Z'));
        $invoiceNumber = 'TEST-SET-' . bin2hex(random_bytes(4));
        $requestGenerate = 'settlement-generate-' . bin2hex(random_bytes(4));
        $requestFinalize = 'settlement-finalize-' . bin2hex(random_bytes(4));
        $invoiceId = 0;
        $settlementId = 0;
        try {
            $this->pdo->beginTransaction();
            $snapshot = json_encode([
                'version' => 1,
                'bookingCode' => $booking['MaDatCho'],
                'booker' => ['name' => $booking['TenNguoiDat'], 'email' => $booking['EmailNguoiDat']],
                'property' => ['id' => (int) $booking['CoSoLuuTruID']],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $statement = $this->pdo->prepare(
                "INSERT INTO HoaDon
                    (SoHoaDon,DatChoID,ToChucDoiTacID,CoSoLuuTruID,KhachHangID,TrangThai,TienTe,
                     TongTien,DaThanhToan,PhatHanhLuc,NguoiPhatHanhID,SnapshotJSON)
                 VALUES (?,?,?,?,?,'ISSUED','VND',?,?,?, ?,?)"
            );
            $statement->execute([
                $invoiceNumber, $booking['DatChoID'], $booking['organizationId'], $booking['CoSoLuuTruID'],
                $booking['KhachHangID'], $booking['TongTien'], $booking['TongTien'],
                '2026-11-04 08:30:00.000000', $booking['actorId'], $snapshot,
            ]);
            $invoiceId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO ChiTietHoaDon (HoaDonID,ThuTu,MoTa,SoLuong,DonGia,ThanhTien,SnapshotJSON)
                 VALUES (?,1,?,1,?,?,?)'
            )->execute([$invoiceId, 'Phòng kiểm thử đối soát', $booking['TongTien'], $booking['TongTien'], '{}']);
            $this->pdo->commit();

            $repository = new RevenueSettlementRepository($this->pdo);
            $service = new RevenueSettlementService(
                $repository,
                new TransactionManager($this->pdo, static fn (int $_): null => null),
                new DatabaseAuditTrail($this->pdo),
                $clock,
            );
            $request = new RevenueSettlementRequest((int) $booking['CoSoLuuTruID'], '2026-11-04', '2026-11-04');

            try {
                $service->generate($request, (int) $booking['organizationId'] + 999, (int) $booking['actorId'],
                    'wrong-tenant-' . bin2hex(random_bytes(4)), '127.0.0.1');
                self::fail('Tenant khác không được tạo đối soát.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('không thuộc', $exception->getMessage());
            }

            $draft = $service->generate($request, (int) $booking['organizationId'], (int) $booking['actorId'],
                $requestGenerate, '127.0.0.1');
            $settlementId = $draft->id;
            self::assertSame('DRAFT', $draft->status);
            self::assertSame(1, $draft->bookingCount);
            self::assertSame($invoiceId, (int) $draft->lines[0]['invoiceId']);
            self::assertTrue($draft->grossRevenue->equals($draft->netRevenue));
            self::assertTrue(
                $draft->grossRevenue->equals($draft->onlineCollected->add($draft->atPropertyCollected)),
            );

            $finalized = $service->finalize($settlementId, (int) $booking['organizationId'],
                (int) $booking['actorId'], $requestFinalize, '127.0.0.1');
            self::assertSame('FINALIZED', $finalized->status);
            self::assertNotNull($finalized->finalizedAt);

            try {
                $service->generate($request, (int) $booking['organizationId'], (int) $booking['actorId'],
                    'settlement-regenerate-' . bin2hex(random_bytes(4)), '127.0.0.1');
                self::fail('Không được tạo lại kỳ đã chốt.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('giao với', $exception->getMessage());
            }
            self::assertSame('1', (string) $this->pdo->query(
                'SELECT COUNT(*) FROM ChiTietDoiSoat WHERE KyDoiSoatID=' . $settlementId
            )->fetchColumn());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->beginTransaction();
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID IN (?,?)')
                ->execute([$requestGenerate, $requestFinalize]);
            if ($settlementId > 0) {
                $this->pdo->prepare('DELETE FROM ChiTietDoiSoat WHERE KyDoiSoatID=?')->execute([$settlementId]);
                $this->pdo->prepare('DELETE FROM KyDoiSoat WHERE KyDoiSoatID=?')->execute([$settlementId]);
            }
            if ($invoiceId > 0) {
                $this->pdo->prepare('DELETE FROM ChiTietHoaDon WHERE HoaDonID=?')->execute([$invoiceId]);
                $this->pdo->prepare('DELETE FROM HoaDon WHERE HoaDonID=?')->execute([$invoiceId]);
            }
            $this->pdo->commit();
        }
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }
}

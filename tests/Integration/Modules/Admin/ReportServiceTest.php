<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use App\Bootstrap\App;
use App\Modules\Admin\Application\ReportCriteria;
use App\Modules\Admin\Application\ReportService;
use App\Modules\Admin\Infrastructure\ReportRepository;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PDO;
use Tests\Support\TestCase;

final class ReportServiceTest extends TestCase
{
    private PDO $pdo;
    private ReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->service = new ReportService(new ReportRepository($this->pdo));
    }

    public function test_partner_report_is_tenant_scoped_and_uses_explicit_financial_formulas(): void
    {
        $criteria = new ReportCriteria('2026-07-01', '2027-04-01');
        $report = $this->service->partner($criteria, 1);

        self::assertSame(1, $report['criteria']['organizationId']);
        self::assertNotEmpty($report['properties']);
        foreach ($report['properties'] as $property) {
            self::assertSame(1, (int) $property['organizationId']);
        }

        $expectedBookedValue = $this->scalar(
            "SELECT COALESCE(SUM(dc.TongTien),0) FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE cs.ToChucDoiTacID=1 AND dc.NgayNhanPhong BETWEEN '2026-07-01' AND '2027-04-01'
               AND dc.TrangThai IN ('CONFIRMED','COMPLETED','NO_SHOW')"
        );
        $expectedOnlineNet = $this->scalar(
            "SELECT COALESCE(SUM(tt.DaThanhToan-tt.DaHoan),0) FROM ThanhToan tt
             INNER JOIN DatCho dc ON dc.DatChoID=tt.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE cs.ToChucDoiTacID=1 AND dc.NgayNhanPhong BETWEEN '2026-07-01' AND '2027-04-01'
               AND tt.ThoiDiemThanhToan='PAY_ONLINE'"
        );
        $expectedAssessed = $this->scalar(
            "SELECT COALESCE(SUM(CASE WHEN dc.TrangThai='CANCELLED' THEN dc.PhiHuy
                     WHEN dc.TrangThai='NO_SHOW' THEN dc.PhiNoShow ELSE 0 END),0)
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE cs.ToChucDoiTacID=1 AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND dc.NgayNhanPhong BETWEEN '2026-07-01' AND '2027-04-01'"
        );

        self::assertSame($expectedBookedValue, (string) $report['money']['bookedValue']);
        self::assertSame($expectedOnlineNet, (string) $report['money']['onlineNetCollected']);
        self::assertSame($expectedAssessed, Money::vnd((string) $report['money']['assessedCancellationFee'])
            ->add(Money::vnd((string) $report['money']['assessedNoShowFee']))->decimal());
        self::assertArrayHasKey('soldOnOfferedRate', $report['inventory']);
        self::assertGreaterThan(0, (int) $report['inventory']['offeredRoomNights']);
    }

    public function test_partner_cannot_select_foreign_property_and_admin_gets_operational_summary(): void
    {
        $foreignProperty = (int) $this->pdo->query(
            'SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE ToChucDoiTacID<>1 ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetchColumn();
        try {
            $this->service->partner(new ReportCriteria('2026-10-01', '2026-10-31', null, $foreignProperty), 1);
            self::fail('Partner không được xem report cơ sở ngoài tổ chức.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('không thuộc phạm vi', $exception->getMessage());
        }

        $admin = $this->service->admin(new ReportCriteria('2026-10-01', '2026-10-31'));
        self::assertArrayHasKey('propertyByStatus', $admin['operations']);
        self::assertArrayHasKey('waivers', $admin['operations']);
        self::assertSame(0, $admin['operations']['inventoryMismatchCount']);

        $issues = $this->service->paymentIssues(1);
        $expectedIssues = (int) $this->scalar(
            "SELECT COUNT(*) FROM ThanhToan
             WHERE ThoiDiemThanhToan='PAY_ONLINE' AND ((TrangThai='FAILED' AND MaLoiCuoi IS NOT NULL)
                OR TrangThaiHoanTien IN ('PENDING','FAILED'))"
        );
        self::assertSame($expectedIssues, $issues->total);
    }

    public function test_report_range_is_limited_to_366_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tối đa 366 ngày');
        new ReportCriteria('2025-01-01', '2027-01-02');
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}

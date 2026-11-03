<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use App\Bootstrap\App;
use PDO;
use Tests\Support\TestCase;

final class AdminQueryPlanTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        self::assertGreaterThanOrEqual(30, (int) $this->pdo->query('SELECT COUNT(*) FROM CoSoLuuTru')->fetchColumn());
    }

    public function test_customer_booking_list_uses_customer_created_at_index(): void
    {
        $plan = $this->explain(
            'SELECT dc.DatChoID FROM DatCho dc
             WHERE dc.KhachHangID=:customerId
             ORDER BY dc.TaoLuc DESC,dc.DatChoID DESC LIMIT 20',
            ['customerId' => 1],
        );

        $booking = $this->tablePlan($plan, 'dc');
        self::assertSame('IX_DatCho_Khach', $booking['key']);
        self::assertSame('ref', $booking['type']);
    }

    public function test_property_booking_list_uses_property_status_check_in_index(): void
    {
        $plan = $this->explain(
            'SELECT dc.DatChoID FROM DatCho dc
             WHERE dc.CoSoLuuTruID=:propertyId AND dc.TrangThai=:status
               AND dc.NgayNhanPhong BETWEEN :from AND :to
             ORDER BY dc.NgayNhanPhong LIMIT 20',
            ['propertyId' => 1, 'status' => 'CONFIRMED', 'from' => '2026-07-01', 'to' => '2027-04-01'],
        );

        $booking = $this->tablePlan($plan, 'dc');
        self::assertSame('IX_DatCho_CoSo', $booking['key']);
        self::assertSame('range', $booking['type']);
    }

    public function test_active_ledger_queries_use_reconciliation_and_expiry_indexes(): void
    {
        $reconciliation = $this->explain(
            "SELECT gt.GiuTonPhongDemID FROM GiuTonPhongDem gt
             WHERE gt.LoaiPhongID=:roomTypeId AND gt.NgayLuuTru BETWEEN :from AND :to
               AND gt.TrangThai='ACTIVE'",
            ['roomTypeId' => 1, 'from' => '2026-07-01', 'to' => '2027-04-01'],
        );
        $expiry = $this->explain(
            "SELECT gt.GiuTonPhongDemID FROM GiuTonPhongDem gt
             WHERE gt.TrangThai='ACTIVE' AND gt.HetHanLuc<=:expiredAt",
            ['expiredAt' => '2026-11-02 12:00:00'],
        );

        self::assertSame('IX_GiuTon_DoiSoat', $this->tablePlan($reconciliation, 'gt')['key']);
        self::assertSame('IX_GiuTon_HetHan', $this->tablePlan($expiry, 'gt')['key']);
    }

    public function test_public_review_query_uses_property_status_created_at_index(): void
    {
        $plan = $this->explain(
            "SELECT dg.DanhGiaID FROM DanhGia dg
             WHERE dg.CoSoLuuTruID=:propertyId AND dg.TrangThai='PUBLISHED'
             ORDER BY dg.TaoLuc DESC LIMIT 20",
            ['propertyId' => 1],
        );

        $review = $this->tablePlan($plan, 'dg');
        self::assertSame('IX_DanhGia_CoSo', $review['key']);
        self::assertSame('ref', $review['type']);
    }

    public function test_sold_on_offered_report_follows_existing_join_keys(): void
    {
        $sold = $this->explain(
            "SELECT COUNT(*) FROM GiaDemDatCho gd
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID
             INNER JOIN DatCho dc ON dc.DatChoID=hm.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE gd.NgayLuuTru BETWEEN :from AND :to
               AND dc.TrangThai IN ('CONFIRMED','COMPLETED','NO_SHOW')",
            ['from' => '2026-07-01', 'to' => '2027-04-01'],
        );
        $offered = $this->explain(
            "SELECT SUM(tpn.TongSoLuong) FROM TonPhongNgay tpn
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID=tpn.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=lp.CoSoLuuTruID
             WHERE tpn.NgayLuuTru BETWEEN :from AND :to AND EXISTS (
                 SELECT 1 FROM SanPhamPhong sp
                 INNER JOIN GiaPhongNgay gpn ON gpn.SanPhamPhongID=sp.SanPhamPhongID
                     AND gpn.NgayLuuTru=tpn.NgayLuuTru
                 WHERE sp.LoaiPhongID=lp.LoaiPhongID AND sp.TrangThai='ACTIVE' AND gpn.DongBan=0
             )",
            ['from' => '2026-07-01', 'to' => '2027-04-01'],
        );

        $soldBooking = $this->tablePlan($sold, 'dc');
        $soldItem = $this->tablePlan($sold, 'hm');
        $soldNight = $this->tablePlan($sold, 'gd');
        self::assertContains($soldBooking['key'], ['PRIMARY', 'IX_DatCho_CoSo']);
        self::assertContains($soldBooking['type'], ['eq_ref', 'ref', 'range']);
        self::assertContains($soldItem['key'], ['PRIMARY', 'UQ_HangMuc_ThuTu']);
        self::assertSame('eq_ref', $soldItem['type']);
        self::assertNotNull($soldNight['key']);
        self::assertContains($soldNight['type'], ['eq_ref', 'ref', 'range', 'index']);
        self::assertSame('PRIMARY', $this->tablePlan($offered, 'gpn')['key']);
        self::assertSame('PRIMARY', $this->tablePlan($offered, 'tpn')['key']);
        self::assertSame('eq_ref', $this->tablePlan($offered, 'tpn')['type']);
    }

    /** @param array<string, scalar|null> $params @return list<array<string, mixed>> */
    private function explain(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare('EXPLAIN ' . $sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $plan @return array<string, mixed> */
    private function tablePlan(array $plan, string $table): array
    {
        foreach ($plan as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }

        self::fail(sprintf('Không tìm thấy bảng %s trong EXPLAIN.', $table));
    }
}

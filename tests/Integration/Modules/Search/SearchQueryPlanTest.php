<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use App\Bootstrap\App;
use PDO;
use Tests\Support\TestCase;

final class SearchQueryPlanTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_search_uses_composite_keys_for_daily_rate_and_inventory(): void
    {
        $plan = $this->explain(
            <<<'SQL'
                SELECT lp.CoSoLuuTruID, lp.LoaiPhongID, sp.SanPhamPhongID,
                       gpn.NgayLuuTru, tpn.TongSoLuong - tpn.SoLuongDaGiu AS available
                FROM LoaiPhong lp
                INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
                INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
                INNER JOIN SanPhamPhong sp ON sp.LoaiPhongID = lp.LoaiPhongID
                INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID = sp.ChinhSachHuyID
                    AND csh.CoSoLuuTruID = lp.CoSoLuuTruID
                INNER JOIN GiaPhongNgay gpn ON gpn.SanPhamPhongID = sp.SanPhamPhongID
                INNER JOIN TonPhongNgay tpn ON tpn.LoaiPhongID = lp.LoaiPhongID
                    AND tpn.NgayLuuTru = gpn.NgayLuuTru
                WHERE cs.MaThanhPho = 'HCM'
                  AND cs.TrangThai = 'ACTIVE'
                  AND tc.TrangThai = 'HOAT_DONG'
                  AND lp.TrangThai = 'ACTIVE'
                  AND sp.TrangThai = 'ACTIVE'
                  AND csh.TrangThai = 'ACTIVE'
                  AND gpn.NgayLuuTru >= :checkIn
                  AND gpn.NgayLuuTru < :checkOut
                  AND gpn.DongBan = 0
                SQL,
            ['checkIn' => '2026-12-10', 'checkOut' => '2026-12-12'],
        );

        $rate = $this->tablePlan($plan, 'gpn');
        $inventory = $this->tablePlan($plan, 'tpn');

        self::assertNotNull($rate['key']);
        self::assertNotSame('ALL', $rate['type']);
        self::assertSame('PRIMARY', $inventory['key']);
        self::assertSame('eq_ref', $inventory['type']);
    }

    public function test_facility_filter_uses_property_facility_primary_key(): void
    {
        $plan = $this->explain(
            <<<'SQL'
                SELECT cs.CoSoLuuTruID
                FROM CoSoLuuTru cs
                INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
                WHERE cs.MaThanhPho = 'HCM'
                  AND cs.TrangThai = 'ACTIVE'
                  AND tc.TrangThai = 'HOAT_DONG'
                  AND (
                      SELECT COUNT(DISTINCT cstn.TienNghiID)
                      FROM CoSoTienNghi cstn
                      WHERE cstn.CoSoLuuTruID = cs.CoSoLuuTruID
                        AND cstn.TienNghiID IN (1, 2)
                  ) = 2
                SQL,
        );

        $facility = $this->tablePlan($plan, 'cstn');

        self::assertSame('PRIMARY', $facility['key']);
        self::assertSame('ref', $facility['type']);
        self::assertLessThanOrEqual(10, (int) $facility['rows']);
    }

    public function test_detail_availability_uses_slug_rate_and_inventory_keys(): void
    {
        $plan = $this->explain(
            <<<'SQL'
                SELECT sp.SanPhamPhongID, gpn.NgayLuuTru,
                       tpn.TongSoLuong - tpn.SoLuongDaGiu AS available
                FROM CoSoLuuTru cs
                INNER JOIN LoaiPhong lp ON lp.CoSoLuuTruID = cs.CoSoLuuTruID
                INNER JOIN SanPhamPhong sp ON sp.LoaiPhongID = lp.LoaiPhongID
                INNER JOIN GiaPhongNgay gpn ON gpn.SanPhamPhongID = sp.SanPhamPhongID
                INNER JOIN TonPhongNgay tpn ON tpn.LoaiPhongID = lp.LoaiPhongID
                    AND tpn.NgayLuuTru = gpn.NgayLuuTru
                WHERE cs.Slug = :slug
                  AND cs.TrangThai = 'ACTIVE'
                  AND lp.TrangThai = 'ACTIVE'
                  AND sp.TrangThai = 'ACTIVE'
                  AND gpn.NgayLuuTru >= :checkIn
                  AND gpn.NgayLuuTru < :checkOut
                  AND gpn.DongBan = 0
                SQL,
            [
                'slug' => 'CS011-hotel-grand-saigon',
                'checkIn' => '2026-12-10',
                'checkOut' => '2026-12-12',
            ],
        );

        self::assertSame('UQ_CoSo_Slug', $this->tablePlan($plan, 'cs')['key']);
        self::assertNotNull($this->tablePlan($plan, 'gpn')['key']);
        self::assertSame('PRIMARY', $this->tablePlan($plan, 'tpn')['key']);
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

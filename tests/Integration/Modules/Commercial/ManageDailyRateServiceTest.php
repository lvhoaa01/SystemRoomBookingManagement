<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\ManageDailyRateService;
use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use PDO;
use Tests\Support\TestCase;

final class ManageDailyRateServiceTest extends TestCase
{
    private const DATES = ['2031-05-01', '2031-05-02'];

    private PDO $pdo;
    private ManageDailyRateService $service;
    private ?int $productId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->service = new ManageDailyRateService(
            new PropertyRepository($this->pdo),
            new RoomProductRepository($this->pdo),
            new DailyRateRepository($this->pdo),
            new TransactionManager($this->pdo),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->productId !== null) {
            $placeholders = implode(',', array_fill(0, count(self::DATES), '?'));
            $this->pdo->prepare(
                'DELETE FROM GiaPhongNgay WHERE SanPhamPhongID = ? AND NgayLuuTru IN (' . $placeholders . ')'
            )->execute([$this->productId, ...self::DATES]);
            $this->pdo->prepare(
                "DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'ROOM_PRODUCT_RATES' AND DoiTuID = :id"
            )->execute(['id' => (string) $this->productId]);
        }
        parent::tearDown();
    }

    public function test_updates_multiple_dates_atomically_with_one_audit_record(): void
    {
        $context = $this->context();
        $this->productId = $context['productId'];
        $updated = $this->service->updateCalendar(
            $this->productId,
            $context['organization'],
            $context['actorId'],
            [
                $this->rate(self::DATES[0], '900000'),
                $this->rate(self::DATES[1], '950000'),
            ],
            'rate-batch-request',
            '127.0.0.1',
        );

        self::assertCount(2, $updated);
        self::assertSame([1, 1], array_map(static fn (array $row): int => (int) $row['version'], $updated));
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM NhatKyHeThong
             WHERE LoaiDoiTu = 'ROOM_PRODUCT_RATES' AND DoiTuID = '" . $this->productId . "'"
        )->fetchColumn());
    }

    /** @return array{productId: int, actorId: int, organization: OrganizationContext} */
    private function context(): array
    {
        $row = $this->pdo->query(
            "SELECT sp.SanPhamPhongID, cs.CoSoLuuTruID, tc.ToChucDoiTacID,
                    tc.MaToChuc, tc.TenToChuc, tc.TrangThai,
                    (SELECT tv.NguoiDungID FROM ThanhVienDoiTac tv
                     WHERE tv.ToChucDoiTacID = tc.ToChucDoiTacID ORDER BY tv.NguoiDungID LIMIT 1) AS NguoiDungID
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE cs.TrangThai IN ('DRAFT','ACTIVE','SUSPENDED') AND tc.TrangThai = 'HOAT_DONG'
             HAVING NguoiDungID IS NOT NULL
             ORDER BY sp.SanPhamPhongID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [
            'productId' => (int) $row['SanPhamPhongID'],
            'actorId' => (int) $row['NguoiDungID'],
            'organization' => new OrganizationContext(
                (int) $row['ToChucDoiTacID'],
                (string) $row['MaToChuc'],
                (string) $row['TenToChuc'],
                (string) $row['TrangThai'],
                true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function rate(string $date, string $price): array
    {
        return [
            'stayDate' => $date, 'basePrice' => $price, 'closed' => false,
            'minimumStay' => 1, 'maximumStay' => 14, 'minimumLeadDays' => 0,
        ];
    }
}

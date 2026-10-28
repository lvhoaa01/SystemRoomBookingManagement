<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\DailyRateData;
use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use PDO;
use Tests\Support\TestCase;

final class DailyRateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DailyRateRepository $rates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->rates = new DailyRateRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_upsert_increments_version_and_is_tenant_scoped(): void
    {
        $product = $this->pdo->query(
            'SELECT sp.SanPhamPhongID, cs.ToChucDoiTacID
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             ORDER BY sp.SanPhamPhongID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($product);
        $productId = (int) $product['SanPhamPhongID'];
        $organizationId = (int) $product['ToChucDoiTacID'];
        $stayDate = '2030-01-15';

        $first = $this->rates->upsert($productId, $organizationId, $this->data($stayDate, '700000'));
        self::assertNotNull($first);
        self::assertSame(1, (int) $first['version']);
        self::assertSame('700000.00', $first['basePrice']);

        $second = $this->rates->upsert($productId, $organizationId, $this->data($stayDate, '750000'));
        self::assertNotNull($second);
        self::assertSame(2, (int) $second['version']);
        self::assertSame('750000.00', $second['basePrice']);

        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . $organizationId . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();
        self::assertNull($this->rates->findForOrganization($productId, $stayDate, $otherOrganizationId));
        self::assertNull($this->rates->upsert(
            $productId,
            $otherOrganizationId,
            $this->data('2030-01-16', '800000'),
        ));
    }

    /** @return array<string, mixed> */
    private function data(string $stayDate, string $basePrice): array
    {
        return DailyRateData::fromArray([
            'stayDate' => $stayDate, 'basePrice' => $basePrice, 'closed' => false,
            'minimumStay' => 1, 'maximumStay' => 14, 'minimumLeadDays' => 0,
        ])->persistenceData();
    }
}

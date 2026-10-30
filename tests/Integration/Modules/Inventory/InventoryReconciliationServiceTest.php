<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Inventory\Application\InventoryReconciliationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use PDO;
use Tests\Support\TestCase;

final class InventoryReconciliationServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_reports_mismatch_without_silently_repairing_counter(): void
    {
        $context = $this->pdo->query(
            'SELECT lp.LoaiPhongID, cs.ToChucDoiTacID
             FROM LoaiPhong lp INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             ORDER BY lp.LoaiPhongID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($context);
        $repository = new InventoryRepository($this->pdo);
        $date = '2035-04-01';
        self::assertNotNull($repository->upsertTotal(
            (int) $context['LoaiPhongID'], (int) $context['ToChucDoiTacID'], $date, 5,
        ));
        $this->pdo->prepare(
            'UPDATE TonPhongNgay SET SoLuongDaGiu = 2 WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        )->execute([(int) $context['LoaiPhongID'], $date]);

        $issues = (new InventoryReconciliationService($repository))->reconcile(
            $date,
            $date,
            (int) $context['LoaiPhongID'],
        );

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->counterHeldQuantity);
        self::assertSame(0, $issues[0]->ledgerHeldQuantity);
        self::assertSame(2, $issues[0]->difference());
        self::assertSame(2, (int) $repository->findForOrganization(
            (int) $context['LoaiPhongID'], $date, (int) $context['ToChucDoiTacID'],
        )['heldQuantity']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use PDO;
use Tests\Support\TestCase;

final class InventoryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private InventoryRepository $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->inventory = new InventoryRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_upsert_is_tenant_scoped_and_cannot_reduce_below_held_counter(): void
    {
        $context = $this->roomContext();
        $date = '2034-02-01';

        $created = $this->inventory->upsertTotal(
            $context['roomTypeId'], $context['organizationId'], $date, 5,
        );
        self::assertNotNull($created);
        self::assertSame(5, (int) $created['totalQuantity']);
        self::assertSame(1, (int) $created['version']);

        $this->pdo->prepare(
            'UPDATE TonPhongNgay SET SoLuongDaGiu = 3 WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        )->execute([$context['roomTypeId'], $date]);
        self::assertNull($this->inventory->upsertTotal(
            $context['roomTypeId'], $context['organizationId'], $date, 2,
        ));
        self::assertSame(5, (int) $this->inventory->findForOrganization(
            $context['roomTypeId'], $date, $context['organizationId'],
        )['totalQuantity']);

        self::assertNull($this->inventory->upsertTotal(
            $context['roomTypeId'], $context['otherOrganizationId'], '2034-02-02', 10,
        ));
    }

    /** @return array{roomTypeId: int, organizationId: int, otherOrganizationId: int} */
    private function roomContext(): array
    {
        $row = $this->pdo->query(
            'SELECT lp.LoaiPhongID, cs.ToChucDoiTacID
             FROM LoaiPhong lp INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             ORDER BY lp.LoaiPhongID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $other = $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $row['ToChucDoiTacID'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();
        return [
            'roomTypeId' => (int) $row['LoaiPhongID'],
            'organizationId' => (int) $row['ToChucDoiTacID'],
            'otherOrganizationId' => (int) $other,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Inventory\Application\InventoryCalendarService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use DomainException;
use PDO;
use Tests\Support\TestCase;

final class InventoryCalendarServiceTest extends TestCase
{
    private const DATES = ['2034-03-01', '2034-03-02'];

    private PDO $pdo;
    private InventoryCalendarService $service;
    private ?int $roomTypeId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $app = App::boot(dirname(__DIR__, 4));
        $this->pdo = $app->container()->get(PDO::class);
        $this->service = $app->container()->get(InventoryCalendarService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->roomTypeId !== null) {
            $placeholders = implode(',', array_fill(0, count(self::DATES), '?'));
            $this->pdo->prepare(
                'DELETE FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru IN (' . $placeholders . ')'
            )->execute([$this->roomTypeId, ...self::DATES]);
            $this->pdo->prepare(
                "DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'ROOM_TYPE_INVENTORY' AND DoiTuID = ?"
            )->execute([(string) $this->roomTypeId]);
        }
        parent::tearDown();
    }

    public function test_batch_is_atomic_when_one_day_would_drop_below_held_and_success_is_audited(): void
    {
        $context = $this->context();
        $this->roomTypeId = $context['roomTypeId'];
        $repository = new InventoryRepository($this->pdo);
        foreach (self::DATES as $date) {
            self::assertNotNull($repository->upsertTotal(
                $this->roomTypeId, $context['organization']->id, $date, 5,
            ));
        }
        $this->pdo->prepare(
            'UPDATE TonPhongNgay SET SoLuongDaGiu = 3 WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        )->execute([$this->roomTypeId, self::DATES[1]]);

        try {
            $this->service->updateCalendar(
                $this->roomTypeId,
                $context['organization'],
                $context['actorId'],
                [
                    ['stayDate' => self::DATES[0], 'totalQuantity' => 7],
                    ['stayDate' => self::DATES[1], 'totalQuantity' => 2],
                ],
                'inventory-failed-request',
                '127.0.0.1',
            );
            self::fail('Batch không hợp lệ phải bị từ chối.');
        } catch (DomainException) {
            self::assertSame(5, (int) $repository->findForOrganization(
                $this->roomTypeId, self::DATES[0], $context['organization']->id,
            )['totalQuantity']);
        }

        $updated = $this->service->updateCalendar(
            $this->roomTypeId,
            $context['organization'],
            $context['actorId'],
            [
                ['stayDate' => self::DATES[1], 'totalQuantity' => 3],
                ['stayDate' => self::DATES[0], 'totalQuantity' => 7],
            ],
            'inventory-success-request',
            '127.0.0.1',
        );

        self::assertSame(self::DATES, array_column($updated, 'stayDate'));
        self::assertSame([7, 3], array_map('intval', array_column($updated, 'totalQuantity')));
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM NhatKyHeThong
             WHERE LoaiDoiTu = 'ROOM_TYPE_INVENTORY' AND DoiTuID = '" . $this->roomTypeId . "'"
        )->fetchColumn());
    }

    /** @return array{roomTypeId: int, actorId: int, organization: OrganizationContext} */
    private function context(): array
    {
        $row = $this->pdo->query(
            "SELECT lp.LoaiPhongID, tc.ToChucDoiTacID, tc.MaToChuc, tc.TenToChuc, tc.TrangThai,
                    (SELECT tv.NguoiDungID FROM ThanhVienDoiTac tv
                     WHERE tv.ToChucDoiTacID = tc.ToChucDoiTacID ORDER BY tv.NguoiDungID LIMIT 1) AS NguoiDungID
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE cs.TrangThai IN ('DRAFT','ACTIVE','SUSPENDED') AND tc.TrangThai = 'HOAT_DONG'
             HAVING NguoiDungID IS NOT NULL
             ORDER BY lp.LoaiPhongID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [
            'roomTypeId' => (int) $row['LoaiPhongID'],
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
}

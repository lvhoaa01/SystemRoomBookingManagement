<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\ManageRoomProductService;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use PDO;
use Tests\Support\TestCase;

final class ManageRoomProductServiceTest extends TestCase
{
    private PDO $pdo;
    private ManageRoomProductService $service;
    private RoomProductRepository $products;
    private ?int $createdProductId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->products = new RoomProductRepository($this->pdo);
        $this->service = new ManageRoomProductService(
            new PropertyRepository($this->pdo),
            $this->products,
            new TransactionManager($this->pdo),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->createdProductId !== null) {
            $this->pdo->prepare(
                "DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'ROOM_PRODUCT' AND DoiTuID = :id"
            )->execute(['id' => (string) $this->createdProductId]);
            $this->pdo->prepare('DELETE FROM SanPhamPhong WHERE SanPhamPhongID = :id')
                ->execute(['id' => $this->createdProductId]);
        }
        parent::tearDown();
    }

    public function test_create_and_update_room_product_are_transactional_and_audited(): void
    {
        $context = $this->context();
        $this->createdProductId = $this->service->create(
            $context['propertyId'],
            $context['organization'],
            $context['actorId'],
            $this->input($context['roomTypeId'], $context['policyId'], 'CREATE'),
            'product-create-request',
            '127.0.0.1',
        );
        self::assertGreaterThan(0, $this->createdProductId);

        $updated = $this->input($context['roomTypeId'], $context['policyId'], 'UPDATE');
        $updated['name'] = 'Sản phẩm sau cập nhật';
        $this->service->update(
            $this->createdProductId,
            $context['organization'],
            $context['actorId'],
            $updated,
            'product-update-request',
            '127.0.0.1',
        );

        self::assertSame(
            'Sản phẩm sau cập nhật',
            $this->products->findForOrganization(
                $this->createdProductId,
                $context['organization']->id,
            )['name'] ?? null,
        );
        $statement = $this->pdo->prepare(
            "SELECT HanhDong FROM NhatKyHeThong
             WHERE LoaiDoiTu = 'ROOM_PRODUCT' AND DoiTuID = :id ORDER BY NhatKyHeThongID"
        );
        $statement->execute(['id' => (string) $this->createdProductId]);
        self::assertSame(
            ['ROOM_PRODUCT_CREATED', 'ROOM_PRODUCT_UPDATED'],
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /** @return array{propertyId: int, roomTypeId: int, policyId: int, actorId: int, organization: OrganizationContext} */
    private function context(): array
    {
        $row = $this->pdo->query(
            "SELECT cs.CoSoLuuTruID, tc.ToChucDoiTacID, tc.MaToChuc, tc.TenToChuc, tc.TrangThai,
                    (SELECT lp.LoaiPhongID FROM LoaiPhong lp
                     WHERE lp.CoSoLuuTruID = cs.CoSoLuuTruID ORDER BY lp.LoaiPhongID LIMIT 1) AS LoaiPhongID,
                    (SELECT csh.ChinhSachHuyID FROM ChinhSachHuy csh
                     WHERE csh.CoSoLuuTruID = cs.CoSoLuuTruID ORDER BY csh.ChinhSachHuyID LIMIT 1) AS ChinhSachHuyID,
                    (SELECT tv.NguoiDungID FROM ThanhVienDoiTac tv
                     WHERE tv.ToChucDoiTacID = tc.ToChucDoiTacID ORDER BY tv.NguoiDungID LIMIT 1) AS NguoiDungID
             FROM CoSoLuuTru cs
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE cs.TrangThai IN ('DRAFT','ACTIVE','SUSPENDED') AND tc.TrangThai = 'HOAT_DONG'
             HAVING LoaiPhongID IS NOT NULL AND ChinhSachHuyID IS NOT NULL AND NguoiDungID IS NOT NULL
             ORDER BY cs.CoSoLuuTruID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [
            'propertyId' => (int) $row['CoSoLuuTruID'],
            'roomTypeId' => (int) $row['LoaiPhongID'],
            'policyId' => (int) $row['ChinhSachHuyID'],
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
    private function input(int $roomTypeId, int $policyId, string $suffix): array
    {
        return [
            'roomTypeId' => $roomTypeId, 'cancellationPolicyId' => $policyId,
            'code' => $suffix . '_' . bin2hex(random_bytes(2)),
            'name' => 'Sản phẩm kiểm thử', 'mealType' => 'ROOM_ONLY',
            'paymentTiming' => 'PAY_AT_PROPERTY', 'status' => 'ACTIVE',
        ];
    }
}

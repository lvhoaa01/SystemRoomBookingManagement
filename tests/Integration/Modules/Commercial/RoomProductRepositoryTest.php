<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\RoomProductData;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use PDO;
use Tests\Support\TestCase;

final class RoomProductRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RoomProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->products = new RoomProductRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_create_requires_room_and_policy_from_the_same_property_and_tenant(): void
    {
        $source = $this->pdo->query(
            'SELECT lp.LoaiPhongID, lp.CoSoLuuTruID, cs.ToChucDoiTacID
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             ORDER BY lp.LoaiPhongID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($source);
        $policyId = (int) $this->pdo->query(
            'SELECT ChinhSachHuyID FROM ChinhSachHuy WHERE CoSoLuuTruID = '
            . (int) $source['CoSoLuuTruID'] . ' ORDER BY ChinhSachHuyID LIMIT 1'
        )->fetchColumn();
        $data = $this->data((int) $source['LoaiPhongID'], $policyId);

        $productId = $this->products->create(
            (int) $source['CoSoLuuTruID'],
            (int) $source['ToChucDoiTacID'],
            $data,
        );
        self::assertNotNull($productId);
        self::assertSame(
            (int) $source['CoSoLuuTruID'],
            (int) ($this->products->findForOrganization($productId, (int) $source['ToChucDoiTacID'])['propertyId'] ?? 0),
        );

        $otherPropertyPolicyId = (int) $this->pdo->query(
            'SELECT ChinhSachHuyID FROM ChinhSachHuy WHERE CoSoLuuTruID <> '
            . (int) $source['CoSoLuuTruID'] . ' ORDER BY ChinhSachHuyID LIMIT 1'
        )->fetchColumn();
        $crossProperty = $this->data((int) $source['LoaiPhongID'], $otherPropertyPolicyId);
        self::assertNull($this->products->create(
            (int) $source['CoSoLuuTruID'],
            (int) $source['ToChucDoiTacID'],
            $crossProperty,
        ));

        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $source['ToChucDoiTacID'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();
        self::assertNull($this->products->findForOrganization($productId, $otherOrganizationId));
        self::assertSame([], $this->products->listForProperty((int) $source['CoSoLuuTruID'], $otherOrganizationId));
    }

    /** @return array<string, mixed> */
    private function data(int $roomTypeId, int $policyId): array
    {
        return RoomProductData::fromArray([
            'roomTypeId' => $roomTypeId,
            'cancellationPolicyId' => $policyId,
            'code' => 'TEST_' . bin2hex(random_bytes(3)),
            'name' => 'Sản phẩm kiểm thử',
            'mealType' => 'ROOM_ONLY',
            'paymentTiming' => 'PAY_AT_PROPERTY',
            'status' => 'ACTIVE',
        ])->persistenceData();
    }
}

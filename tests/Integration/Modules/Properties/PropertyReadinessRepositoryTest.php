<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use PDO;
use Tests\Support\TestCase;

final class PropertyReadinessRepositoryTest extends TestCase
{
    public function test_reads_commercial_readiness_facts_without_cross_property_links(): void
    {
        $pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $propertyId = (int) $pdo->query('SELECT CoSoLuuTruID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1')->fetchColumn();
        $repository = new PropertyReadinessRepository($pdo);

        self::assertTrue($repository->isOrganizationActive($propertyId));
        self::assertNotEmpty($repository->activeRooms($propertyId));
        $facts = $repository->commercialFacts($propertyId);
        self::assertArrayHasKey('hasCancellationPolicy', $facts);
        self::assertArrayHasKey('priceDays', $facts);
        self::assertFalse($facts['hasCrossPropertyRelation']);
    }

    public function test_detects_product_linking_room_and_policy_from_different_properties(): void
    {
        $pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $pdo->beginTransaction();
        try {
            $room = $pdo->query(
                'SELECT LoaiPhongID, CoSoLuuTruID FROM LoaiPhong ORDER BY LoaiPhongID LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($room);
            $policy = $pdo->query(
                'SELECT ChinhSachHuyID, CoSoLuuTruID FROM ChinhSachHuy WHERE CoSoLuuTruID <> '
                . (int) $room['CoSoLuuTruID'] . ' ORDER BY ChinhSachHuyID LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($policy);

            $statement = $pdo->prepare(
                "INSERT INTO SanPhamPhong (
                    LoaiPhongID, ChinhSachHuyID, MaSanPham, TenSanPham,
                    LoaiBuaAn, ThoiDiemThanhToan, TrangThai
                 ) VALUES (:roomId, :policyId, :code, 'Sản phẩm liên kết chéo',
                    'ROOM_ONLY', 'PAY_AT_PROPERTY', 'ACTIVE')"
            );
            $statement->execute([
                'roomId' => (int) $room['LoaiPhongID'],
                'policyId' => (int) $policy['ChinhSachHuyID'],
                'code' => 'X' . strtoupper(bin2hex(random_bytes(5))),
            ]);

            $facts = (new PropertyReadinessRepository($pdo))->commercialFacts((int) $room['CoSoLuuTruID']);
            self::assertTrue($facts['hasCrossPropertyRelation']);
        } finally {
            $pdo->rollBack();
        }
    }
}

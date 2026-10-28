<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\PromotionData;
use App\Modules\Commercial\Infrastructure\PromotionRepository;
use PDO;
use Tests\Support\TestCase;

final class PromotionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PromotionRepository $promotions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->promotions = new PromotionRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_crud_is_tenant_scoped_and_applicable_order_is_deterministic(): void
    {
        $property = $this->pdo->query(
            'SELECT CoSoLuuTruID, ToChucDoiTacID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($property);
        $propertyId = (int) $property['CoSoLuuTruID'];
        $organizationId = (int) $property['ToChucDoiTacID'];

        $firstId = $this->promotions->create($propertyId, $organizationId, $this->data('TEST_A', '15'));
        $secondId = $this->promotions->create($propertyId, $organizationId, $this->data('TEST_B', '15'));
        self::assertNotNull($firstId);
        self::assertNotNull($secondId);

        $applicable = $this->promotions->listApplicable(
            $propertyId,
            '2027-01-01 12:00:00',
            '2027-01-10',
            '2027-01-12',
        );
        $createdIds = array_values(array_filter(
            array_column($applicable, 'id'),
            static fn (int $id): bool => in_array($id, [$firstId, $secondId], true),
        ));
        self::assertSame([$firstId, $secondId], $createdIds);

        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . $organizationId . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();
        self::assertNull($this->promotions->findForOrganization($firstId, $otherOrganizationId));
        self::assertSame([], $this->promotions->listForProperty($propertyId, $otherOrganizationId));
    }

    /** @return array<string, mixed> */
    private function data(string $code, string $rate): array
    {
        return PromotionData::fromArray([
            'code' => $code, 'name' => 'Khuyến mãi kiểm thử', 'discountRate' => $rate,
            'bookingFrom' => '2026-12-01 00:00:00',
            'bookingUntil' => '2027-02-01 00:00:00',
            'stayFrom' => '2027-01-01', 'stayUntil' => '2027-01-31',
            'status' => 'ACTIVE',
        ])->persistenceData();
    }
}

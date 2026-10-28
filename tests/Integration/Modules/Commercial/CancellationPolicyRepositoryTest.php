<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\CancellationPolicyData;
use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use PDO;
use Tests\Support\TestCase;

final class CancellationPolicyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CancellationPolicyRepository $policies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->policies = new CancellationPolicyRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_create_read_and_update_are_tenant_scoped(): void
    {
        $property = $this->pdo->query(
            'SELECT CoSoLuuTruID, ToChucDoiTacID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($property);
        $propertyId = (int) $property['CoSoLuuTruID'];
        $organizationId = (int) $property['ToChucDoiTacID'];
        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . $organizationId . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        $data = CancellationPolicyData::fromArray([
            'code' => 'TEST_' . bin2hex(random_bytes(3)),
            'name' => 'Chính sách kiểm thử', 'policyType' => 'FLEXIBLE',
            'freeCancellationHours' => 24, 'penaltyType' => 'PERCENT',
            'penaltyValue' => '50', 'noShowPenaltyRate' => '100', 'status' => 'ACTIVE',
        ])->persistenceData();

        $policyId = $this->policies->create($propertyId, $organizationId, $data);
        self::assertNotNull($policyId);
        self::assertCount(4, $this->policies->listForProperty($propertyId, $organizationId));
        self::assertNull($this->policies->findForOrganization($policyId, $otherOrganizationId));
        self::assertSame([], $this->policies->listForProperty($propertyId, $otherOrganizationId));
        self::assertNull($this->policies->create($propertyId, $otherOrganizationId, $data));

        $data['name'] = 'Chính sách đã sửa';
        self::assertFalse($this->policies->update($policyId, $otherOrganizationId, $data));
        self::assertTrue($this->policies->update($policyId, $organizationId, $data));
        self::assertSame(
            'Chính sách đã sửa',
            $this->policies->findForOrganization($policyId, $organizationId)['name'] ?? null,
        );
    }
}

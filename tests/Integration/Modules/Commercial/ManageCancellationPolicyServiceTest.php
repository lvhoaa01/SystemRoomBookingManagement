<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\ManageCancellationPolicyService;
use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;
use PDO;
use Tests\Support\TestCase;

final class ManageCancellationPolicyServiceTest extends TestCase
{
    private PDO $pdo;
    private ManageCancellationPolicyService $service;
    private CancellationPolicyRepository $policies;
    private ?int $createdPolicyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->policies = new CancellationPolicyRepository($this->pdo);
        $this->service = new ManageCancellationPolicyService(
            new PropertyRepository($this->pdo),
            $this->policies,
            new TransactionManager($this->pdo),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->createdPolicyId !== null) {
            $this->pdo->prepare(
                "DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'CANCELLATION_POLICY' AND DoiTuID = :id"
            )->execute(['id' => (string) $this->createdPolicyId]);
            $this->pdo->prepare('DELETE FROM ChinhSachHuy WHERE ChinhSachHuyID = :id')
                ->execute(['id' => $this->createdPolicyId]);
        }
        parent::tearDown();
    }

    public function test_create_and_update_policy_are_audited(): void
    {
        [$propertyId, $organization] = $this->editableProperty();
        $this->createdPolicyId = $this->service->create(
            $propertyId,
            $organization,
            2,
            $this->input('CREATE_' . bin2hex(random_bytes(2))),
            'policy-create-request',
            '127.0.0.1',
        );
        $policyId = $this->createdPolicyId;
        self::assertGreaterThan(0, $policyId);

        $updated = $this->input('UPDATE_' . bin2hex(random_bytes(2)));
        $updated['name'] = 'Chính sách sau cập nhật';
        $this->service->update(
            $policyId,
            $organization,
            2,
            $updated,
            'policy-update-request',
            '127.0.0.1',
        );

        self::assertSame(
            'Chính sách sau cập nhật',
            $this->policies->findForOrganization($policyId, $organization->id)['name'] ?? null,
        );
        $actions = $this->pdo->query(
            "SELECT HanhDong FROM NhatKyHeThong
             WHERE LoaiDoiTu = 'CANCELLATION_POLICY' AND DoiTuID = '" . $policyId . "'
             ORDER BY NhatKyHeThongID"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['CANCELLATION_POLICY_CREATED', 'CANCELLATION_POLICY_UPDATED'], $actions);
    }

    public function test_suspended_organization_cannot_manage_policy(): void
    {
        [$propertyId, $organization] = $this->editableProperty();
        $suspended = new OrganizationContext(
            $organization->id,
            $organization->code,
            $organization->name,
            OrganizationContext::STATUS_SUSPENDED,
            $organization->isOwner,
        );

        $this->expectException(DomainException::class);
        $this->service->create(
            $propertyId,
            $suspended,
            2,
            $this->input('LOCKED'),
            'policy-locked-request',
            '127.0.0.1',
        );
    }

    /** @return array{int, OrganizationContext} */
    private function editableProperty(): array
    {
        $row = $this->pdo->query(
            "SELECT cs.CoSoLuuTruID, tc.ToChucDoiTacID, tc.MaToChuc, tc.TenToChuc, tc.TrangThai
             FROM CoSoLuuTru cs
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = cs.ToChucDoiTacID
             WHERE cs.TrangThai IN ('DRAFT','ACTIVE','SUSPENDED') AND tc.TrangThai = 'HOAT_DONG'
             ORDER BY cs.CoSoLuuTruID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [
            (int) $row['CoSoLuuTruID'],
            new OrganizationContext(
                (int) $row['ToChucDoiTacID'],
                (string) $row['MaToChuc'],
                (string) $row['TenToChuc'],
                (string) $row['TrangThai'],
                true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function input(string $code): array
    {
        return [
            'code' => $code, 'name' => 'Chính sách kiểm thử',
            'policyType' => 'FLEXIBLE', 'freeCancellationHours' => 24,
            'penaltyType' => 'FIRST_NIGHT', 'penaltyValue' => 1,
            'noShowPenaltyRate' => 100, 'status' => 'ACTIVE',
        ];
    }
}

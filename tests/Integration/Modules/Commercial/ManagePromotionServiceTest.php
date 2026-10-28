<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Commercial;

use App\Bootstrap\App;
use App\Modules\Commercial\Application\ManagePromotionService;
use App\Modules\Commercial\Infrastructure\PromotionRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;
use PDO;
use Tests\Support\TestCase;

final class ManagePromotionServiceTest extends TestCase
{
    private PDO $pdo;
    private ManagePromotionService $service;
    private PromotionRepository $promotions;
    private ?int $createdPromotionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->promotions = new PromotionRepository($this->pdo);
        $this->service = new ManagePromotionService(
            new PropertyRepository($this->pdo),
            $this->promotions,
            new TransactionManager($this->pdo),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->createdPromotionId !== null) {
            $this->pdo->prepare(
                "DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'PROMOTION' AND DoiTuID = :id"
            )->execute(['id' => (string) $this->createdPromotionId]);
            $this->pdo->prepare('DELETE FROM KhuyenMai WHERE KhuyenMaiID = :id')
                ->execute(['id' => $this->createdPromotionId]);
        }
        parent::tearDown();
    }

    public function test_create_and_update_promotion_are_audited(): void
    {
        [$propertyId, $organization] = $this->editableProperty();
        $this->createdPromotionId = $this->service->create(
            $propertyId,
            $organization,
            2,
            $this->input('CREATE_' . bin2hex(random_bytes(2))),
            'promotion-create-request',
            '127.0.0.1',
        );
        $promotionId = $this->createdPromotionId;
        self::assertGreaterThan(0, $promotionId);

        $updated = $this->input('UPDATE_' . bin2hex(random_bytes(2)));
        $updated['name'] = 'Khuyến mãi sau cập nhật';
        $this->service->update(
            $promotionId,
            $organization,
            2,
            $updated,
            'promotion-update-request',
            '127.0.0.1',
        );

        self::assertSame(
            'Khuyến mãi sau cập nhật',
            $this->promotions->findForOrganization($promotionId, $organization->id)['name'] ?? null,
        );
        $actions = $this->pdo->query(
            "SELECT HanhDong FROM NhatKyHeThong
             WHERE LoaiDoiTu = 'PROMOTION' AND DoiTuID = '" . $promotionId . "'
             ORDER BY NhatKyHeThongID"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['PROMOTION_CREATED', 'PROMOTION_UPDATED'], $actions);
    }

    public function test_suspended_organization_cannot_manage_promotion(): void
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
            'promotion-locked-request',
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
            'code' => $code,
            'name' => 'Khuyến mãi kiểm thử',
            'discountRate' => '15',
            'bookingFrom' => '2026-12-01 00:00:00',
            'bookingUntil' => '2027-02-01 00:00:00',
            'stayFrom' => '2027-01-01',
            'stayUntil' => '2027-01-31',
            'status' => 'ACTIVE',
        ];
    }
}

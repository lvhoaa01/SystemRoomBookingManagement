<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Modules\Properties\Application\CreatePropertyService;
use App\Modules\Properties\Application\ReviewPropertyService;
use App\Modules\Properties\Application\SubmitPropertyForReviewService;
use App\Modules\Properties\Domain\PropertyReadinessChecker;
use App\Modules\Properties\Domain\PropertyReviewAction;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use App\Shared\Security\TokenGenerator;
use PDO;
use Tests\Support\TestCase;

final class PropertyWorkflowServiceTest extends TestCase
{
    private PDO $pdo;
    private ?int $propertyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    protected function tearDown(): void
    {
        if ($this->propertyId !== null) {
            $this->pdo->prepare("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'PROPERTY' AND DoiTuID = :id")
                ->execute(['id' => (string) $this->propertyId]);
            $this->pdo->prepare('DELETE FROM CoSoLuuTru WHERE CoSoLuuTruID = :id')
                ->execute(['id' => $this->propertyId]);
        }
        parent::tearDown();
    }

    public function test_not_ready_draft_stays_draft_and_admin_can_request_changes_from_pending(): void
    {
        $repository = new PropertyRepository($this->pdo);
        $transactions = new TransactionManager($this->pdo);
        $audit = new DatabaseAuditTrail($this->pdo);
        $organization = $this->organization();
        $partnerId = (int) $this->pdo->query(
            'SELECT NguoiDungID FROM ThanhVienDoiTac WHERE ToChucDoiTacID = ' . $organization->id . ' LIMIT 1'
        )->fetchColumn();

        $this->propertyId = (new CreatePropertyService(
            $repository, $transactions, $audit, new TokenGenerator(),
        ))->create(
            $organization, $partnerId, $this->input(),
            'workflow-create-' . bin2hex(random_bytes(5)), '127.0.0.1',
        );

        $submit = new SubmitPropertyForReviewService(
            $repository,
            new PropertyReadinessRepository($this->pdo),
            new PropertyReadinessChecker(),
            $transactions,
            $audit,
        );
        $report = $submit->submit(
            $this->propertyId, $organization, $partnerId,
            'workflow-submit-' . bin2hex(random_bytes(5)), '127.0.0.1',
        );
        self::assertFalse($report->isReady());
        self::assertNotEmpty($report->missing);
        self::assertSame('DRAFT', $repository->findById($this->propertyId)['status']);

        self::assertTrue($repository->transition(
            $this->propertyId, PropertyStatus::DRAFT, PropertyStatus::PENDING_REVIEW, null, null,
        ));
        $adminId = (int) $this->pdo->query("SELECT NguoiDungID FROM NguoiDung WHERE VaiTro = 'ADMIN' LIMIT 1")->fetchColumn();
        $admin = (new UserRepository($this->pdo))->findById($adminId);
        self::assertNotNull($admin);

        (new ReviewPropertyService($repository, $transactions, $audit))->review(
            $this->propertyId,
            $admin->toCurrentUser(),
            PropertyReviewAction::REQUEST_CHANGES,
            'Bổ sung ảnh phòng.',
            'workflow-review-' . bin2hex(random_bytes(5)),
            '127.0.0.1',
        );
        $after = $repository->findById($this->propertyId);
        self::assertSame('DRAFT', $after['status']);
        self::assertNull($after['reviewerId']);
        self::assertNull($after['reviewedAt']);
    }

    private function organization(): OrganizationContext
    {
        $row = $this->pdo->query(
            "SELECT ToChucDoiTacID, MaToChuc, TenToChuc, TrangThai
             FROM ToChucDoiTac WHERE TrangThai = 'HOAT_DONG' ORDER BY ToChucDoiTacID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return new OrganizationContext(
            (int) $row['ToChucDoiTacID'], (string) $row['MaToChuc'],
            (string) $row['TenToChuc'], (string) $row['TrangThai'], true,
        );
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'type' => 'HOTEL', 'name' => 'Khách sạn quy trình', 'address' => '2 Đồng Khởi, Quận 1',
            'description' => 'Mô tả hợp lệ phục vụ kiểm thử quy trình.',
            'imagesJson' => '[{"path":"/uploads/properties/workflow.webp","alt":"Mặt tiền","sort":1,"cover":true}]',
            'taxRate' => '8', 'taxIncluded' => true, 'serviceFeeRate' => '5',
            'checkInTime' => '14:00:00', 'checkOutTime' => '12:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }
}

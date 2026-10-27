<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Application\CreatePropertyService;
use App\Modules\Properties\Application\UpdatePropertyService;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use App\Shared\Security\TokenGenerator;
use PDO;
use Tests\Support\TestCase;

final class PropertyMutationServiceTest extends TestCase
{
    private PDO $pdo;
    private ?int $createdPropertyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    protected function tearDown(): void
    {
        if ($this->createdPropertyId !== null) {
            $this->pdo->prepare("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'PROPERTY' AND DoiTuID = :id")
                ->execute(['id' => (string) $this->createdPropertyId]);
            $this->pdo->prepare('DELETE FROM CoSoLuuTru WHERE CoSoLuuTruID = :id')
                ->execute(['id' => $this->createdPropertyId]);
        }
        parent::tearDown();
    }

    public function test_create_and_update_draft_are_transactional_and_audited(): void
    {
        $organization = $this->organization();
        $actorId = (int) $this->pdo->query(
            'SELECT NguoiDungID FROM ThanhVienDoiTac WHERE ToChucDoiTacID = ' . $organization->id . ' LIMIT 1'
        )->fetchColumn();
        $repository = new PropertyRepository($this->pdo);
        $transactions = new TransactionManager($this->pdo);
        $audit = new DatabaseAuditTrail($this->pdo);

        $create = new CreatePropertyService($repository, $transactions, $audit, new TokenGenerator());
        $this->createdPropertyId = $create->create(
            $organization,
            $actorId,
            $this->input(),
            'phase4-create-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );
        $created = $repository->findForOrganization($this->createdPropertyId, $organization->id);
        self::assertNotNull($created);
        self::assertSame('DRAFT', $created['status']);

        $input = $this->input();
        $input['description'] = 'Mô tả đã được cập nhật nhưng không phải trường trọng yếu.';
        $update = new UpdatePropertyService($repository, $transactions, $audit);
        $status = $update->update(
            $this->createdPropertyId,
            $organization,
            $actorId,
            $input,
            'phase4-update-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );

        self::assertSame(PropertyStatus::DRAFT, $status);
        self::assertSame(2, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM NhatKyHeThong WHERE LoaiDoiTu = 'PROPERTY' AND DoiTuID = " . $this->createdPropertyId
        )->fetchColumn());
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
            'type' => 'HOTEL', 'name' => 'Khách sạn tích hợp', 'address' => '1 Đồng Khởi, Quận 1',
            'description' => 'Mô tả hợp lệ phục vụ bài kiểm thử tích hợp.',
            'imagesJson' => '[{"path":"/uploads/properties/integration.webp","alt":"Mặt tiền","sort":1,"cover":true}]',
            'taxRate' => '8', 'taxIncluded' => true, 'serviceFeeRate' => '5',
            'serviceFeeIncluded' => false, 'checkInTime' => '14:00:00',
            'checkOutTime' => '12:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }
}

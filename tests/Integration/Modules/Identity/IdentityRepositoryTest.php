<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use App\Bootstrap\App;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use PDO;
use Tests\Support\TestCase;

final class IdentityRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_user_repository_finds_by_email_or_code_and_never_serializes_password_hash(): void
    {
        $repository = new UserRepository($this->pdo);

        $byEmail = $repository->findByEmailOrCode('customer0001@example.test');
        $byCode = $repository->findByEmailOrCode('KH0001');

        self::assertNotNull($byEmail);
        self::assertNotNull($byCode);
        self::assertSame($byEmail->id, $byCode->id);
        self::assertTrue($byEmail->verifyPassword('Ntu@123456'));
        self::assertArrayNotHasKey('passwordHash', get_object_vars($byEmail->toCurrentUser()));
    }

    public function test_user_repository_creates_customer_and_updates_status(): void
    {
        $repository = new UserRepository($this->pdo);
        $email = 'phase3-' . bin2hex(random_bytes(5)) . '@example.test';

        $created = $repository->createCustomer(
            'KH' . strtoupper(bin2hex(random_bytes(5))),
            'Khách kiểm thử',
            $email,
            '0901234567',
            password_hash('Secret123', PASSWORD_DEFAULT),
        );

        self::assertSame(UserAccount::ROLE_CUSTOMER, $created->role);
        self::assertTrue($repository->emailExists($email));

        $repository->updateStatus($created->id, UserAccount::STATUS_LOCKED);
        self::assertSame(UserAccount::STATUS_LOCKED, $repository->findById($created->id)?->status);
    }

    public function test_partner_membership_returns_the_single_server_side_organization_context(): void
    {
        $userId = (int) $this->pdo->query(
            'SELECT NguoiDungID FROM ThanhVienDoiTac ORDER BY NguoiDungID LIMIT 1'
        )->fetchColumn();

        $context = (new PartnerMembershipRepository($this->pdo))->findOrganizationForUser($userId);

        self::assertNotNull($context);
        self::assertGreaterThan(0, $context->id);
        self::assertNotSame('', $context->code);
    }
}

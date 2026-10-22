<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use App\Bootstrap\App;
use App\Modules\Identity\Application\RegisterCustomerService;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Shared\Security\TokenGenerator;
use PDO;
use Tests\Support\TestCase;

final class RegisterCustomerServiceTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private RegisterCustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->users = new UserRepository($this->pdo);
        $this->service = new RegisterCustomerService($this->users, new TokenGenerator());
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_registers_customer_with_normalized_values_and_hashed_password(): void
    {
        $email = 'Phase3-' . bin2hex(random_bytes(4)) . '@Example.Test';

        $result = $this->service->register(
            '  Nguyễn   Minh Anh  ',
            $email,
            '090 123 4567',
            'Secret123',
            'Secret123',
        );

        self::assertTrue($result->succeeded());
        self::assertSame('Nguyễn Minh Anh', $result->user?->fullName);
        self::assertSame(strtolower($email), $result->user?->email);
        self::assertSame(UserAccount::ROLE_CUSTOMER, $result->user?->role);

        $stored = $this->users->findByEmailOrCode(strtolower($email));
        self::assertNotNull($stored);
        self::assertTrue($stored->verifyPassword('Secret123'));
        self::assertSame('0901234567', $stored->phone);
    }

    public function test_rejects_invalid_fields_without_inserting_account(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM NguoiDung')->fetchColumn();

        $result = $this->service->register(
            'A',
            'not-an-email',
            'abc',
            'short',
            'different',
        );

        self::assertFalse($result->succeeded());
        self::assertArrayHasKey('fullName', $result->validation->errors());
        self::assertArrayHasKey('email', $result->validation->errors());
        self::assertArrayHasKey('phone', $result->validation->errors());
        self::assertArrayHasKey('password', $result->validation->errors());
        self::assertArrayHasKey('passwordConfirmation', $result->validation->errors());
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM NguoiDung')->fetchColumn());
    }

    public function test_rejects_duplicate_email_after_normalization(): void
    {
        $result = $this->service->register(
            'Khách trùng email',
            '  CUSTOMER0001@EXAMPLE.TEST ',
            null,
            'Secret123',
            'Secret123',
        );

        self::assertFalse($result->succeeded());
        self::assertSame(['Email này đã được sử dụng.'], $result->validation->errorsFor('email'));
    }
}

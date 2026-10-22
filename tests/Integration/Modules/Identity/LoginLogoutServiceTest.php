<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Application\LogoutService;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\LoginThrottle;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Shared\Clock\ClockInterface;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginLogoutServiceTest extends TestCase
{
    private PDO $pdo;
    private SessionBootstrap $session;
    private IdentitySession $identitySession;
    private LoginService $login;

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->identitySession = new IdentitySession($this->session);
        $this->login = new LoginService(
            new UserRepository($this->pdo),
            new PartnerMembershipRepository($this->pdo),
            $this->identitySession,
            new LoginThrottle($container->get(ClockInterface::class)),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_logs_in_customer_and_regenerates_session_id(): void
    {
        $before = session_id();

        $result = $this->login->login('CUSTOMER0001@EXAMPLE.TEST', 'Ntu@123456');

        self::assertTrue($result->succeeded());
        self::assertNotSame($before, session_id());
        self::assertSame(UserAccount::ROLE_CUSTOMER, $this->identitySession->currentUser()?->role);
        self::assertNull($this->identitySession->organization());
    }

    public function test_returns_the_same_error_for_unknown_wrong_password_and_locked_account(): void
    {
        $unknown = $this->login->login('missing@example.test', 'Wrong123');
        $wrongPassword = $this->login->login('customer0001@example.test', 'Wrong123');

        $user = (new UserRepository($this->pdo))->findByEmailOrCode('customer0001@example.test');
        self::assertNotNull($user);
        (new UserRepository($this->pdo))->updateStatus($user->id, UserAccount::STATUS_LOCKED);
        $locked = $this->login->login('customer0001@example.test', 'Ntu@123456');

        self::assertFalse($unknown->succeeded());
        self::assertSame($unknown->validation->errors(), $wrongPassword->validation->errors());
        self::assertSame($unknown->validation->errors(), $locked->validation->errors());
        self::assertNull($this->identitySession->currentUser());
    }

    public function test_partner_organization_comes_from_membership_not_login_input(): void
    {
        $result = $this->login->login('partner01@accommodation.local', 'Ntu@123456');

        self::assertTrue($result->succeeded());
        self::assertSame(UserAccount::ROLE_PARTNER, $result->user?->role);
        self::assertNotNull($result->organization);
        self::assertEquals($result->organization, $this->identitySession->organization());
    }

    public function test_partner_without_membership_logs_in_without_organization_context(): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO NguoiDung (MaNguoiDung, HoTen, Email, MatKhauHash, VaiTro, TrangThai)
             VALUES (:code, 'Đối tác chưa liên kết', :email, :hash, 'PARTNER', 'HOAT_DONG')"
        );
        $statement->execute([
            'code' => 'DT' . strtoupper(bin2hex(random_bytes(5))),
            'email' => 'orphan-' . bin2hex(random_bytes(4)) . '@example.test',
            'hash' => password_hash('Secret123', PASSWORD_DEFAULT),
        ]);
        $email = (string) $this->pdo->query(
            'SELECT Email FROM NguoiDung WHERE NguoiDungID = LAST_INSERT_ID()'
        )->fetchColumn();

        $result = $this->login->login($email, 'Secret123');

        self::assertTrue($result->succeeded());
        self::assertNull($result->organization);
        self::assertNull($this->identitySession->organization());
    }

    public function test_logout_destroys_identity_session(): void
    {
        self::assertTrue($this->login->login('customer0001@example.test', 'Ntu@123456')->succeeded());

        (new LogoutService($this->identitySession))->logout();

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }
}

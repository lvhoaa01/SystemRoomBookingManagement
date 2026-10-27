<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Identity;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Application\LogoutService;
use App\Modules\Identity\Application\RegisterCustomerService;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\RegisterController;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\LoginThrottle;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use App\Shared\Clock\ClockInterface;
use App\Shared\Security\TokenGenerator;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthControllerTest extends TestCase
{
    private PDO $pdo;
    private SessionBootstrap $session;
    private IdentitySession $identitySession;
    private RegisterController $register;
    private LoginController $login;
    private LogoutController $logout;

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->identitySession = new IdentitySession($this->session);

        $users = new UserRepository($this->pdo);
        $memberships = new PartnerMembershipRepository($this->pdo);
        $views = $container->get(ViewRenderer::class);
        $flash = $container->get(FlashBag::class);
        $config = $container->get(AppConfig::class);

        $this->register = new RegisterController(
            new RegisterCustomerService($users, new TokenGenerator()),
            $this->identitySession,
            $this->session,
            $views,
            $flash,
            $config,
        );
        $this->login = new LoginController(
            new LoginService(
                $users,
                $memberships,
                $this->identitySession,
                new LoginThrottle($container->get(ClockInterface::class)),
            ),
            $this->identitySession,
            $this->session,
            $views,
            $flash,
            $config,
        );
        $this->logout = new LogoutController(new LogoutService($this->identitySession), $config);
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

    public function test_registration_form_uses_shared_blue_ui_and_csrf_token(): void
    {
        $response = $this->register->show(new Request('GET', '/auth/register'));

        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('~/assets/css/app\.css\?v=\d+~', $response->body());
        self::assertMatchesRegularExpression('~/assets/js/properties\.js\?v=\d+~', $response->body());
        self::assertMatchesRegularExpression('~/assets/images/accommodation/shared/logoNew\.png\?v=\d+~', $response->body());
        self::assertStringContainsString('.app-icon { width: 20px; height: 20px;', $response->body());
        self::assertStringContainsString('name="_token"', $response->body());
        self::assertStringContainsString('Tạo tài khoản', $response->body());
    }

    public function test_registration_renders_field_errors_without_repeating_password(): void
    {
        $response = $this->register->store(new Request('POST', '/auth/register', body: [
            'fullName' => 'A',
            'email' => 'bad',
            'phone' => 'abc',
            'password' => 'secret-value',
            'passwordConfirmation' => 'different-value',
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Email không hợp lệ.', $response->body());
        self::assertStringNotContainsString('secret-value', $response->body());
        self::assertStringNotContainsString('different-value', $response->body());
    }

    public function test_successful_registration_redirects_to_login_with_flash_message(): void
    {
        $response = $this->register->store(new Request('POST', '/auth/register', body: [
            'fullName' => 'Khách HTTP',
            'email' => 'http-' . bin2hex(random_bytes(4)) . '@example.test',
            'phone' => '',
            'password' => 'Secret123',
            'passwordConfirmation' => 'Secret123',
        ]));

        self::assertSame(303, $response->status());
        self::assertSame('http://localhost/auth/login', $response->headers()['Location']);
        self::assertStringContainsString('Tạo tài khoản thành công', $this->login->show(
            new Request('GET', '/auth/login')
        )->body());
    }

    public function test_login_failure_is_generic_and_success_creates_identity_session(): void
    {
        $failed = $this->login->store(new Request('POST', '/auth/login', body: [
            'identifier' => 'missing@example.test',
            'password' => 'Wrong123',
        ]));
        self::assertSame(422, $failed->status());
        self::assertStringContainsString('Thông tin đăng nhập không chính xác', $failed->body());

        $success = $this->login->store(new Request('POST', '/auth/login', body: [
            'identifier' => 'customer0001@example.test',
            'password' => 'Ntu@123456',
        ]));
        self::assertSame(303, $success->status());
        self::assertNotNull($this->identitySession->currentUser());
    }

    public function test_logout_controller_destroys_authenticated_session(): void
    {
        $this->login->store(new Request('POST', '/auth/login', body: [
            'identifier' => 'customer0001@example.test',
            'password' => 'Ntu@123456',
        ]));

        $response = ($this->logout)(new Request('POST', '/auth/logout'));

        self::assertSame(303, $response->status());
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use App\Modules\Identity\Infrastructure\IdentitySession;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 3));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
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

    public function test_auth_forms_are_routed_and_every_auth_post_requires_csrf(): void
    {
        $form = $this->app->handle(new Request('GET', '/auth/login', requestId: 'auth-form-request'));

        self::assertSame(200, $form->status());
        self::assertStringContainsString('Đăng nhập', $form->body());

        foreach (['/auth/register', '/auth/login', '/auth/logout'] as $index => $path) {
            $rejected = $this->app->handle(new Request(
                'POST',
                $path,
                requestId: 'auth-csrf-request-' . $index,
            ));
            self::assertSame(403, $rejected->status(), "Route {$path} phải yêu cầu CSRF.");
            self::assertStringContainsString('Phiên biểu mẫu không hợp lệ', $rejected->body());
        }
    }

    public function test_login_home_and_logout_complete_the_browser_flow(): void
    {
        $login = $this->app->handle(new Request('POST', '/auth/login', body: [
            '_token' => $this->session->csrfToken(),
            'identifier' => 'customer0001@example.test',
            'password' => 'Ntu@123456',
        ], requestId: 'auth-login-request'));

        self::assertSame(303, $login->status());
        self::assertNotNull($this->app->container()->get(IdentitySession::class)->currentUser());

        $home = $this->app->handle(new Request('GET', '/', requestId: 'auth-home-request'));
        self::assertSame(200, $home->status());
        self::assertStringContainsString('Khách hàng 0001', $home->body());
        self::assertStringContainsString('/auth/logout', $home->body());

        $logout = $this->app->handle(new Request('POST', '/auth/logout', body: [
            '_token' => $this->session->csrfToken(),
        ], requestId: 'auth-logout-request'));
        self::assertSame(303, $logout->status());
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function test_registration_route_creates_customer_and_redirects_to_login(): void
    {
        $email = 'route-' . bin2hex(random_bytes(4)) . '@example.test';
        $response = $this->app->handle(new Request('POST', '/auth/register', body: [
            '_token' => $this->session->csrfToken(),
            'fullName' => 'Khách route',
            'email' => $email,
            'phone' => '0901234567',
            'password' => 'Secret123',
            'passwordConfirmation' => 'Secret123',
        ], requestId: 'auth-register-request'));

        self::assertSame(303, $response->status());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM NguoiDung WHERE Email = " . $this->pdo->quote($email)
        )->fetchColumn());
    }
}

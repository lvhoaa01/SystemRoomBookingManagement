<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Request;
use App\Http\Router;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\UserRepository;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecurityContractTest extends TestCase
{
    private App $app;
    private SessionBootstrap $session;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 3));
        $container = $this->app->container();
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->pdo = $container->get(PDO::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_every_mutation_route_has_csrf_middleware(): void
    {
        $property = new ReflectionProperty(Router::class, 'routes');
        /** @var list<array{method:string,path:string,middleware:list<object>}> $routes */
        $routes = $property->getValue($this->app->container()->get(Router::class));
        $mutations = 0;
        foreach ($routes as $route) {
            if ($route['method'] === 'GET') {
                continue;
            }
            ++$mutations;
            self::assertNotEmpty(array_filter(
                $route['middleware'],
                static fn (object $middleware): bool => $middleware instanceof CsrfMiddleware,
            ), $route['method'] . ' ' . $route['path'] . ' thiếu CSRF middleware.');
        }
        self::assertGreaterThan(20, $mutations);
    }

    public function test_global_response_uses_strict_csp_without_inline_markup(): void
    {
        $response = $this->app->handle(new Request('GET', '/', requestId: 'security-home-request'));

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('unsafe-inline', $response->headers()['Content-Security-Policy']);
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertStringNotContainsString('<style', $response->body());
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $response->body());
    }

    public function test_preview_endpoint_returns_429_after_session_ip_limit(): void
    {
        $customerId = (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro='CUSTOMER' AND TrangThai='HOAT_DONG' LIMIT 1"
        )->fetchColumn();
        $customer = (new UserRepository($this->pdo))->findById($customerId);
        self::assertNotNull($customer);
        $this->app->container()->get(IdentitySession::class)->signIn($customer->toCurrentUser(), null);

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $response = $this->app->handle(new Request(
                'POST', '/previews',
                body: ['_token' => $this->session->csrfToken()],
                clientIp: '127.0.0.7', requestId: 'security-preview-' . $attempt,
            ));
            self::assertSame(400, $response->status());
        }
        $blocked = $this->app->handle(new Request(
            'POST', '/previews',
            body: ['_token' => $this->session->csrfToken()],
            clientIp: '127.0.0.7', requestId: 'security-preview-blocked',
        ));
        self::assertSame(429, $blocked->status());
        self::assertSame('60', $blocked->headers()['Retry-After']);
    }
}

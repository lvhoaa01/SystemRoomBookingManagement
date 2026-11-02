<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminHttpFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;
    private IdentitySession $identity;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->identity = $container->get(IdentitySession::class);
        $this->users = new UserRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_admin_can_open_every_stage_fifteen_workspace(): void
    {
        $admin = $this->users->findById(1);
        self::assertNotNull($admin);
        $this->identity->signIn($admin->toCurrentUser(), null);

        $pages = [
            '/admin/accounts' => 'Quản lý tài khoản',
            '/admin/facilities' => 'Danh mục dùng chung',
            '/admin/audits' => 'Nhật ký hệ thống',
            '/admin/reports' => 'Tỷ lệ bán trên tồn mở bán',
            '/admin/payment-issues' => 'Lỗi payment và refund',
        ];

        foreach ($pages as $path => $expectedText) {
            $response = $this->app->handle(new Request('GET', $path, requestId: $this->requestId($path)));
            self::assertSame(200, $response->status(), $path);
            self::assertSame('no-store, private', $response->headers()['Cache-Control'], $path);
            self::assertStringContainsString($expectedText, $response->body(), $path);
        }
    }

    public function test_partner_report_is_tenant_scoped_and_admin_pages_are_forbidden(): void
    {
        $partner = $this->users->findById(2);
        self::assertNotNull($partner);
        $organization = (new PartnerMembershipRepository($this->pdo))->findOrganizationForUser($partner->id);
        self::assertNotNull($organization);
        $this->identity->signIn($partner->toCurrentUser(), $organization);

        $tampered = $this->app->handle(new Request(
            'GET', '/partner/reports',
            query: ['organizationId' => (string) ($organization->id + 1)],
            requestId: 'admin-http-partner-report',
        ));
        self::assertSame(303, $tampered->status());
        self::assertSame('http://localhost/partner/reports', $tampered->headers()['Location']);

        $report = $this->app->handle(new Request(
            'GET', '/partner/reports', requestId: 'admin-http-partner-valid',
        ));
        self::assertSame(200, $report->status());
        self::assertStringContainsString('Báo cáo tổ chức', $report->body());
        self::assertStringNotContainsString('name="organizationId"', $report->body());

        $forbidden = $this->app->handle(new Request(
            'GET', '/admin/reports', requestId: 'admin-http-partner-denied',
        ));
        self::assertSame(403, $forbidden->status());
    }

    public function test_admin_mutation_requires_csrf_token(): void
    {
        $admin = $this->users->findById(1);
        self::assertNotNull($admin);
        $this->identity->signIn($admin->toCurrentUser(), null);
        $before = (string) $this->pdo->query('SELECT TrangThai FROM NguoiDung WHERE NguoiDungID=1')->fetchColumn();

        $response = $this->app->handle(new Request(
            'POST', '/admin/accounts/1/status',
            body: ['status' => $before === 'HOAT_DONG' ? 'KHOA' : 'HOAT_DONG'],
            requestId: 'admin-http-no-csrf',
        ));

        self::assertSame(403, $response->status());
        self::assertSame($before, (string) $this->pdo->query(
            'SELECT TrangThai FROM NguoiDung WHERE NguoiDungID=1'
        )->fetchColumn());
    }

    private function requestId(string $path): string
    {
        return 'admin-http-' . substr(hash('sha256', $path), 0, 12);
    }
}

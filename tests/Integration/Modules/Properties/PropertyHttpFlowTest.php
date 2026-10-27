<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

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
final class PropertyHttpFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;
    private IdentitySession $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->identity = $container->get(IdentitySession::class);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_partner_dashboard_renders_only_own_properties_and_blocks_cross_tenant_detail(): void
    {
        $users = new UserRepository($this->pdo);
        $memberships = new PartnerMembershipRepository($this->pdo);
        $partner = $users->findById(2);
        self::assertNotNull($partner);
        $organization = $memberships->findOrganizationForUser($partner->id);
        self::assertNotNull($organization);
        $this->identity->signIn($partner->toCurrentUser(), $organization);

        $index = $this->app->handle(new Request('GET', '/partner/properties', requestId: 'property-http-index'));
        self::assertSame(200, $index->status());
        self::assertStringContainsString('Cơ sở lưu trú của bạn', $index->body());
        self::assertStringContainsString('Cơ sở lưu trú', $index->body());
        self::assertStringContainsString('resource-table', $index->body());

        $ownedPropertyId = (int) $this->pdo->query(
            'SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE ToChucDoiTacID = ' . $organization->id . ' LIMIT 1'
        )->fetchColumn();
        $detail = $this->app->handle(new Request(
            'GET', '/partner/properties/' . $ownedPropertyId, requestId: 'property-http-detail',
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Hoàn thiện thông tin', $detail->body());
        self::assertStringContainsString('Danh sách phòng', $detail->body());

        $foreignPropertyId = (int) $this->pdo->query(
            'SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE ToChucDoiTacID <> ' . $organization->id . ' LIMIT 1'
        )->fetchColumn();
        $foreign = $this->app->handle(new Request(
            'GET', '/partner/properties/' . $foreignPropertyId, requestId: 'property-http-foreign',
        ));
        self::assertSame(404, $foreign->status());
    }

    public function test_admin_can_open_review_queue_but_partner_cannot(): void
    {
        $users = new UserRepository($this->pdo);
        $partner = $users->findById(2);
        self::assertNotNull($partner);
        $organization = (new PartnerMembershipRepository($this->pdo))->findOrganizationForUser(2);
        self::assertNotNull($organization);
        $this->identity->signIn($partner->toCurrentUser(), $organization);
        self::assertSame(403, $this->app->handle(new Request(
            'GET', '/admin/properties/review', requestId: 'property-http-denied',
        ))->status());

        $this->identity->signOut();
        $this->session->start();
        $admin = $users->findById(1);
        self::assertNotNull($admin);
        $this->identity->signIn($admin->toCurrentUser(), null);
        $queue = $this->app->handle(new Request(
            'GET', '/admin/properties/review', requestId: 'property-http-admin',
        ));
        self::assertSame(200, $queue->status());
        self::assertStringContainsString('Hàng đợi duyệt', $queue->body());
        self::assertStringContainsString('Kiểm duyệt cơ sở', $queue->body());
        self::assertStringContainsString('review-table', $queue->body());

        $pendingPropertyId = (int) $this->pdo->query(
            "SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE TrangThai = 'PENDING_REVIEW' LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $pendingPropertyId);
        $detail = $this->app->handle(new Request(
            'GET', '/admin/properties/' . $pendingPropertyId, requestId: 'property-http-admin-detail',
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Khả năng bán trong 30 ngày', $detail->body());
        self::assertStringContainsString('Quyết định kiểm duyệt', $detail->body());
    }
}

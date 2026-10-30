<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

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
final class InventoryHttpFlowTest extends TestCase
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

    public function test_partner_opens_only_inventory_of_own_property(): void
    {
        $partner = (new UserRepository($this->pdo))->findById(2);
        self::assertNotNull($partner);
        $organization = (new PartnerMembershipRepository($this->pdo))->findOrganizationForUser($partner->id);
        self::assertNotNull($organization);
        $this->identity->signIn($partner->toCurrentUser(), $organization);

        $owned = (int) $this->pdo->query(
            'SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE ToChucDoiTacID = ' . $organization->id
            . ' ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetchColumn();
        $response = $this->app->handle(new Request(
            'GET', '/partner/properties/' . $owned . '/inventory', requestId: 'inventory-owned',
        ));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Lịch tồn phòng', $response->body());
        self::assertStringContainsString('Tổng phòng mở bán', $response->body());

        $foreign = (int) $this->pdo->query(
            'SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE ToChucDoiTacID <> ' . $organization->id
            . ' ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetchColumn();
        self::assertSame(404, $this->app->handle(new Request(
            'GET', '/partner/properties/' . $foreign . '/inventory', requestId: 'inventory-foreign',
        ))->status());
    }
}

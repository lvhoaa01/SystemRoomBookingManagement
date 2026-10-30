<?php

declare(strict_types=1);

namespace Tests\Integration\Bootstrap;

use App\Bootstrap\App;
use App\Http\Request;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\UserRepository;
use App\Modules\Inventory\Application\AvailabilityReader;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Clock\ClockInterface;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use PDO;
use Tests\Support\TestCase;

final class AppBootTest extends TestCase
{
    public function test_boots_with_one_shared_database_connection(): void
    {
        $app = App::boot(dirname(__DIR__, 3));
        $first = $app->container()->get(PDO::class);
        $second = $app->container()->get(PDO::class);

        self::assertSame($first, $second);
        self::assertSame('hotel_booking', $first->query('SELECT DATABASE()')->fetchColumn());
    }

    public function test_maps_unknown_api_route_to_404_with_request_id(): void
    {
        $app = App::boot(dirname(__DIR__, 3));

        $response = $app->handle(new Request(
            'GET',
            '/api/missing',
            requestId: 'request-app-12345',
        ));
        $payload = json_decode($response->body(), true);

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $payload['error']['code']);
        self::assertSame('request-app-12345', $response->headers()['X-Request-ID']);
    }

    public function test_health_route_checks_application_and_database(): void
    {
        $app = App::boot(dirname(__DIR__, 3));

        $response = $app->handle(new Request(
            'GET',
            '/api/health',
            requestId: 'request-health-123',
        ));
        $payload = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $payload['status']);
        self::assertSame('up', $payload['checks']['application']);
        self::assertSame('up', $payload['checks']['database']);
        self::assertSame('request-health-123', $payload['requestId']);
    }

    public function test_registers_shared_kernel_infrastructure_as_singletons(): void
    {
        $app = App::boot(dirname(__DIR__, 3));
        $container = $app->container();

        self::assertSame($container->get(ClockInterface::class), $container->get(ClockInterface::class));
        self::assertSame('UTC', $container->get(ClockInterface::class)->now()->getTimezone()->getName());
        self::assertSame($container->get(TokenGenerator::class), $container->get(TokenGenerator::class));
        self::assertSame($container->get(Hasher::class), $container->get(Hasher::class));
    }

    public function test_registers_identity_infrastructure_as_singletons(): void
    {
        $container = App::boot(dirname(__DIR__, 3))->container();

        self::assertSame($container->get(UserRepository::class), $container->get(UserRepository::class));
        self::assertSame($container->get(IdentitySession::class), $container->get(IdentitySession::class));
        self::assertSame($container->get(LoginService::class), $container->get(LoginService::class));
    }

    public function test_registers_inventory_reader_as_singleton(): void
    {
        $container = App::boot(dirname(__DIR__, 3))->container();

        self::assertSame($container->get(AvailabilityReader::class), $container->get(AvailabilityReader::class));
        self::assertSame(
            $container->get(InventoryReservationService::class),
            $container->get(InventoryReservationService::class),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Bootstrap;

use App\Bootstrap\App;
use App\Http\Request;
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
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Bootstrap;

use App\Bootstrap\App;
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
}

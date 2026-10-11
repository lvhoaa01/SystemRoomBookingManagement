<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\AppConfig;
use App\Config\DatabaseConfig;
use App\Config\Env;
use DateTimeZone;
use RuntimeException;
use Tests\Support\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_loads_env_without_overwriting_existing_value(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ota-env-');
        self::assertNotFalse($path);
        file_put_contents($path, "PHASE_ONE_VALUE=from-file\nPHASE_ONE_EMPTY=\n");
        $_ENV['PHASE_ONE_VALUE'] = 'from-process';

        try {
            Env::load($path);

            self::assertSame('from-process', Env::get('PHASE_ONE_VALUE'));
            self::assertSame('', Env::require('PHASE_ONE_EMPTY', true));
        } finally {
            unlink($path);
        }
    }

    public function test_rejects_debug_in_production(): void
    {
        $this->expectException(RuntimeException::class);

        new AppConfig(
            'production',
            true,
            'https://example.test',
            new DateTimeZone('Asia/Ho_Chi_Minh'),
        );
    }

    public function test_builds_typed_database_configuration(): void
    {
        $config = new DatabaseConfig('127.0.0.1', 3306, 'hotel_booking', 'root', '');

        self::assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=hotel_booking;charset=utf8mb4',
            $config->dsn(),
        );
    }
}

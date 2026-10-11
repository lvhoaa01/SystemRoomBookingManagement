<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\Container;
use RuntimeException;
use stdClass;
use Tests\Support\TestCase;

final class ContainerTest extends TestCase
{
    public function test_resolves_singleton_only_once(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton('service', static function () use (&$calls): stdClass {
            ++$calls;
            return new stdClass();
        });

        self::assertSame($container->get('service'), $container->get('service'));
        self::assertSame(1, $calls);
    }

    public function test_rejects_circular_dependency(): void
    {
        $container = new Container();
        $container->singleton('loop', static fn (Container $container): mixed => $container->get('loop'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dependency vòng');

        $container->get('loop');
    }
}

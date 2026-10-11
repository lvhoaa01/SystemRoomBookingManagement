<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use DateTimeZone;
use Tests\Support\TestCase;

final class SessionBootstrapTest extends TestCase
{
    public function test_generates_and_rotates_256_bit_csrf_token(): void
    {
        $session = new SessionBootstrap(new AppConfig(
            'test',
            true,
            'http://localhost',
            new DateTimeZone('Asia/Ho_Chi_Minh'),
        ));

        $first = $session->csrfToken();
        $second = $session->csrfToken();
        $rotated = $session->rotateCsrfToken();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertSame($first, $second);
        self::assertNotSame($first, $rotated);
    }

    public function test_enables_secure_cookie_only_for_https(): void
    {
        $http = new AppConfig('test', true, 'http://localhost', new DateTimeZone('UTC'));
        $https = new AppConfig('test', true, 'https://example.test', new DateTimeZone('UTC'));

        self::assertFalse($http->usesSecureCookies());
        self::assertTrue($https->usesSecureCookies());
    }
}

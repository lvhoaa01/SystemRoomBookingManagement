<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use DateTimeZone;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_starts_native_session_with_safe_cookie_settings(): void
    {
        $session = new SessionBootstrap(
            new AppConfig(
                'test',
                true,
                'http://localhost',
                new DateTimeZone('UTC'),
                'ota_phase_one_test',
            ),
            dirname(__DIR__, 3) . '/storage/tmp',
        );

        $session->start();
        $params = session_get_cookie_params();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertFalse($params['secure']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session->csrfToken());

        $session->destroy();
    }
}

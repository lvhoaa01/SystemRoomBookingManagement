<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Config\AppConfig;
use App\Http\Middleware\CallableRequestHandler;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Clock\FrozenClock;
use App\Shared\Http\RateLimitHttpException;
use App\Shared\Http\SecurityHeaders;
use App\Shared\Security\SessionRateLimiter;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestCase;

final class SecurityOperationsTest extends TestCase
{
    public function test_security_headers_apply_strict_csp_and_hsts_only_on_https(): void
    {
        $http = new SecurityHeaders(new AppConfig('test', true, 'http://localhost', new DateTimeZone('UTC')));
        $https = new SecurityHeaders(new AppConfig('test', true, 'https://example.test', new DateTimeZone('UTC')));

        $httpHeaders = $http->apply(Response::html('ok'))->headers();
        self::assertStringNotContainsString('unsafe-inline', $httpHeaders['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $httpHeaders['Content-Security-Policy']);
        self::assertSame('DENY', $httpHeaders['X-Frame-Options']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $httpHeaders);
        self::assertArrayHasKey('Strict-Transport-Security', $https->apply(Response::html('ok'))->headers());
    }

    public function test_rate_limit_blocks_next_request_and_resets_after_window(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T00:00:00+00:00'));
        $middleware = new RateLimitMiddleware(new SessionRateLimiter($clock), 'test.bucket', 2, 60);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));
        $request = new Request('POST', '/limited', clientIp: '127.0.0.1');

        self::assertSame(200, $middleware->process($request, $next)->status());
        self::assertSame(200, $middleware->process($request, $next)->status());
        try {
            $middleware->process($request, $next);
            self::fail('Yêu cầu vượt giới hạn phải bị chặn.');
        } catch (RateLimitHttpException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame('60', $exception->headers['Retry-After']);
        }

        $clock->advance(new DateInterval('PT60S'));
        self::assertSame(200, $middleware->process($request, $next)->status());
    }

    public function test_rate_limit_separates_authenticated_users(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-03T00:00:00+00:00'));
        $middleware = new RateLimitMiddleware(new SessionRateLimiter($clock), 'test.users', 1, 60);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));
        $request = new Request('POST', '/limited', clientIp: '127.0.0.1');

        $_SESSION['_current_user'] = ['id' => 10];
        self::assertSame(200, $middleware->process($request, $next)->status());
        $_SESSION['_current_user'] = ['id' => 11];
        self::assertSame(200, $middleware->process($request, $next)->status());
    }
}

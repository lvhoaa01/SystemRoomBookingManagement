<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CallableRequestHandler;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Http\AuthenticationRequiredException;
use App\Shared\Http\ForbiddenHttpException;
use DateTimeZone;
use Tests\Support\TestCase;

final class SecurityMiddlewareTest extends TestCase
{
    public function test_auth_requires_active_user_and_allowed_role(): void
    {
        $_SESSION = [
            'user_id' => 10,
            'account_status' => 'HOAT_DONG',
            'role' => 'CUSTOMER',
        ];
        $middleware = new AuthMiddleware(['CUSTOMER']);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));

        $response = $middleware->process(new Request('GET', '/account'), $next);

        self::assertSame(200, $response->status());
    }

    public function test_auth_rejects_missing_identity(): void
    {
        $middleware = new AuthMiddleware();
        $next = new CallableRequestHandler(static fn (): Response => Response::json([]));

        $this->expectException(AuthenticationRequiredException::class);
        $middleware->process(new Request('GET', '/account'), $next);
    }

    public function test_csrf_accepts_valid_token_and_rejects_invalid_token(): void
    {
        $session = new SessionBootstrap(new AppConfig(
            'test',
            true,
            'http://localhost',
            new DateTimeZone('UTC'),
        ));
        $token = $session->csrfToken();
        $middleware = new CsrfMiddleware($session);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));

        $valid = $middleware->process(
            new Request('POST', '/change', body: ['_token' => $token]),
            $next,
        );
        self::assertSame(200, $valid->status());

        $this->expectException(ForbiddenHttpException::class);
        $middleware->process(new Request('POST', '/change', body: ['_token' => 'wrong']), $next);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\Middleware;
use App\Http\Middleware\RequestHandler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Shared\Http\MethodNotAllowedHttpException;
use App\Shared\Http\NotFoundHttpException;
use Tests\Support\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_dynamic_route_runs_middleware_and_generates_url(): void
    {
        $router = new Router();
        $middleware = new class implements Middleware {
            public function process(Request $request, RequestHandler $next): Response
            {
                return $next->handle($request)->withHeader('X-Middleware', 'passed');
            }
        };
        $router->get(
            '/properties/{slug}',
            static fn (Request $request): Response => Response::json(['slug' => $request->route('slug')]),
            [$middleware],
            'properties.show',
        );

        $response = $router->dispatch(new Request('GET', '/properties/khách-sạn-a'));

        self::assertSame(200, $response->status());
        self::assertSame(['slug' => 'khách-sạn-a'], json_decode($response->body(), true));
        self::assertSame('passed', $response->headers()['X-Middleware']);
        self::assertSame('/properties/kh%C3%A1ch-s%E1%BA%A1n-a', $router->url('properties.show', [
            'slug' => 'khách-sạn-a',
        ]));
    }

    public function test_reports_method_not_allowed_with_allow_header(): void
    {
        $router = new Router();
        $router->post('/auth/login', static fn (): Response => Response::json([]));

        try {
            $router->dispatch(new Request('GET', '/auth/login'));
            self::fail('Expected MethodNotAllowedHttpException.');
        } catch (MethodNotAllowedHttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame('POST', $exception->headers['Allow']);
        }
    }

    public function test_reports_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new Router())->dispatch(new Request('GET', '/missing'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Config\AppConfig;
use App\Http\Request;
use App\Shared\Http\ExceptionHandler;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ConflictHttpException;
use App\Shared\Logging\NullLogger;
use DateTimeZone;
use RuntimeException;
use Tests\Support\TestCase;

final class ExceptionHandlerTest extends TestCase
{
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ExceptionHandler(
            new AppConfig('test', true, 'http://localhost', new DateTimeZone('UTC')),
            new NullLogger(),
        );
    }

    public function test_returns_standard_json_error_envelope(): void
    {
        $response = $this->handler->handle(
            new NotFoundHttpException(),
            new Request('GET', '/api/missing', requestId: 'request-12345678'),
        );
        $payload = json_decode($response->body(), true);

        self::assertSame(404, $response->status());
        self::assertSame('NOT_FOUND', $payload['error']['code']);
        self::assertSame('request-12345678', $payload['error']['requestId']);
        self::assertSame('request-12345678', $response->headers()['X-Request-ID']);
    }

    public function test_internal_error_never_exposes_exception_message_or_path(): void
    {
        $secret = 'SQL failed at C:\\private\\project with password=abc';
        $response = $this->handler->handle(
            new RuntimeException($secret),
            new Request('GET', '/api/fail', requestId: 'request-87654321'),
        );

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString($secret, $response->body());
        self::assertStringNotContainsString('C:\\private', $response->body());
    }

    public function test_returns_safe_html_error_for_browser_request(): void
    {
        $response = $this->handler->handle(
            new NotFoundHttpException(),
            new Request('GET', '/missing', requestId: 'request-html-123'),
        );

        self::assertSame(404, $response->status());
        self::assertStringContainsString('request-html-123', $response->body());
        self::assertStringNotContainsString('Stack trace', $response->body());
    }

    public function test_preserves_business_conflict_code_and_details(): void
    {
        $response = $this->handler->handle(
            new ConflictHttpException('PREVIEW_STALE', 'Giá đã thay đổi.', ['refresh' => true]),
            new Request('POST', '/api/bookings', requestId: 'request-conflict-1'),
        );
        $payload = json_decode($response->body(), true);

        self::assertSame(409, $response->status());
        self::assertSame('PREVIEW_STALE', $payload['error']['code']);
        self::assertTrue($payload['error']['details']['refresh']);
    }

    public function test_corrupted_identity_session_does_not_break_error_handling(): void
    {
        $_SESSION['_current_user'] = 'corrupted';

        $response = $this->handler->handle(
            new NotFoundHttpException(),
            new Request('GET', '/api/missing', requestId: 'request-corrupt-session'),
        );

        self::assertSame(404, $response->status());
        self::assertStringContainsString('request-corrupt-session', $response->body());
    }
}

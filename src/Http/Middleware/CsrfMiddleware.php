<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Http\ForbiddenHttpException;

final readonly class CsrfMiddleware implements Middleware
{
    public function __construct(private SessionBootstrap $session)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next->handle($request);
        }

        $token = $request->header('x-csrf-token');
        if ($token === null) {
            $formToken = $request->body['_token'] ?? null;
            $token = is_string($formToken) ? $formToken : null;
        }
        $this->assertValid($token);

        return $next->handle($request);
    }

    public function assertValid(?string $token): void
    {
        $expected = $this->session->csrfToken();
        if ($token === null || !hash_equals($expected, $token)) {
            throw new ForbiddenHttpException('CSRF_TOKEN_INVALID', 'Phiên biểu mẫu không hợp lệ hoặc đã hết hạn.');
        }
    }
}

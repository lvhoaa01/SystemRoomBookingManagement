<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Http\RateLimitHttpException;
use App\Shared\Security\SessionRateLimiter;
use InvalidArgumentException;

final readonly class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private SessionRateLimiter $limiter,
        private string $bucket,
        private int $limit,
        private int $windowSeconds,
    ) {
        if ($this->limit < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException('Cấu hình middleware rate limit không hợp lệ.');
        }
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $user = $_SESSION['_current_user'] ?? null;
        $actor = is_array($user) && is_int($user['id'] ?? null) ? 'user:' . $user['id'] : 'anonymous';
        $retryAfter = $this->limiter->consume(
            $this->bucket,
            $actor . '|ip:' . $request->clientIp,
            $this->limit,
            $this->windowSeconds,
        );
        if ($retryAfter > 0) {
            throw new RateLimitHttpException($retryAfter);
        }
        return $next->handle($request);
    }
}

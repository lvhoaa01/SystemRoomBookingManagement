<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class RateLimitHttpException extends HttpException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            429,
            'RATE_LIMITED',
            'Bạn thao tác quá nhanh. Vui lòng đợi một lúc rồi thử lại.',
            [],
            ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }
}

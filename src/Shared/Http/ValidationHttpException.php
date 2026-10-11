<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class ValidationHttpException extends HttpException
{
    /** @param array<string, mixed> $details */
    public function __construct(array $details = [], string $message = 'Dữ liệu gửi lên không hợp lệ.')
    {
        parent::__construct(400, 'VALIDATION_ERROR', $message, $details);
    }
}

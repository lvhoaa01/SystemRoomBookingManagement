<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class ForbiddenHttpException extends HttpException
{
    public function __construct(string $code = 'FORBIDDEN', string $message = 'Bạn không có quyền thực hiện thao tác này.')
    {
        parent::__construct(403, $code, $message);
    }
}

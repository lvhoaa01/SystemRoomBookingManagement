<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class AuthenticationRequiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(401, 'AUTH_REQUIRED', 'Vui lòng đăng nhập để tiếp tục.');
    }
}

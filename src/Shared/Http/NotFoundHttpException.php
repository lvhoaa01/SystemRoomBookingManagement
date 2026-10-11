<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class NotFoundHttpException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'NOT_FOUND', 'Không tìm thấy tài nguyên được yêu cầu.');
    }
}

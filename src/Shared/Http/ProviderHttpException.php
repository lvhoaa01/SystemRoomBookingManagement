<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class ProviderHttpException extends HttpException
{
    public function __construct(string $message = 'Dịch vụ thanh toán tạm thời gặp sự cố.')
    {
        parent::__construct(502, 'PAYMENT_PROVIDER_ERROR', $message);
    }
}

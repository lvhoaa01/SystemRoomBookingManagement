<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class MethodNotAllowedHttpException extends HttpException
{
    /** @param list<string> $allowedMethods */
    public function __construct(array $allowedMethods)
    {
        $allowedMethods = array_values(array_unique($allowedMethods));
        sort($allowedMethods);
        parent::__construct(
            405,
            'METHOD_NOT_ALLOWED',
            'Phương thức HTTP không được hỗ trợ cho đường dẫn này.',
            ['allowedMethods' => $allowedMethods],
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

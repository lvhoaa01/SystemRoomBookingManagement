<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;

final readonly class TokenGenerator
{
    public function __construct(private int $bytes = 32)
    {
        if ($this->bytes < 32) {
            throw new InvalidArgumentException('Token phải có ít nhất 256 bit ngẫu nhiên.');
        }
    }

    public function generate(): string
    {
        return bin2hex(random_bytes($this->bytes));
    }
}

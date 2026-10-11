<?php

declare(strict_types=1);

namespace App\Shared\Logging;

final class NullLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
    }
}

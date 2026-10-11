<?php

declare(strict_types=1);

namespace App\Shared\Logging;

interface Logger
{
    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void;
}

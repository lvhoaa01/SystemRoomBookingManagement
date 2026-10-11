<?php

declare(strict_types=1);

namespace App\Shared\Http;

final readonly class SafeHtml
{
    public function __construct(public string $value)
    {
    }
}

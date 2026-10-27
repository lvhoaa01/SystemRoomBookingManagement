<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;

final readonly class PropertyReadinessReport
{
    /** @param list<string> $missing */
    public function __construct(public array $missing)
    {
    }

    public function isReady(): bool
    {
        return $this->missing === [];
    }
}

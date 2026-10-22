<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\IdentitySession;

final readonly class LogoutService
{
    public function __construct(private IdentitySession $identitySession)
    {
    }

    public function logout(): void
    {
        $this->identitySession->signOut();
    }
}

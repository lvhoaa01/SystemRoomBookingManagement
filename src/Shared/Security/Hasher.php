<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class Hasher
{
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verifyToken(string $token, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hashToken($token));
    }
}

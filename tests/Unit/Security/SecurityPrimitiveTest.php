<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class SecurityPrimitiveTest extends TestCase
{
    public function test_generates_distinct_256_bit_tokens(): void
    {
        $generator = new TokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function test_rejects_token_entropy_below_256_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenGenerator(31);
    }

    public function test_hashes_and_verifies_token_without_storing_plain_value(): void
    {
        $hasher = new Hasher();
        $hash = $hasher->hashToken('opaque-token');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertNotSame('opaque-token', $hash);
        self::assertTrue($hasher->verifyToken('opaque-token', $hash));
        self::assertFalse($hasher->verifyToken('different-token', $hash));
    }
}

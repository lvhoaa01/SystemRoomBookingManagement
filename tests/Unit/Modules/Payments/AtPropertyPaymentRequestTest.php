<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payments;

use App\Modules\Payments\Application\AtPropertyPaymentRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtPropertyPaymentRequestTest extends TestCase
{
    public function test_normalizes_transfer_request(): void
    {
        $request = AtPropertyPaymentRequest::fromInput([
            'paymentKey' => '00000000-0000-4000-8000-000000000019',
            'method' => 'bank_transfer',
            'reference' => '  BANK-REF-19  ',
        ]);

        self::assertSame('BANK_TRANSFER', $request->method);
        self::assertSame('BANK-REF-19', $request->reference);
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidRequests')]
    public function test_rejects_invalid_request(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtPropertyPaymentRequest::fromInput($input);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        yield 'invalid uuid' => [[
            'paymentKey' => 'invalid', 'method' => 'CASH',
        ]];
        yield 'unknown method' => [[
            'paymentKey' => '00000000-0000-4000-8000-000000000019', 'method' => 'CRYPTO',
        ]];
        yield 'cash with reference' => [[
            'paymentKey' => '00000000-0000-4000-8000-000000000019',
            'method' => 'CASH', 'reference' => 'REF',
        ]];
        yield 'transfer without reference' => [[
            'paymentKey' => '00000000-0000-4000-8000-000000000019',
            'method' => 'BANK_TRANSFER',
        ]];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Shared\Money\Money;
use InvalidArgumentException;
use OverflowException;
use Tests\Support\TestCase;
use TypeError;

final class MoneyTest extends TestCase
{
    public function test_accepts_only_vnd_and_serializes_for_decimal_column(): void
    {
        $money = new Money('vnd', '125000');

        self::assertSame('VND', $money->currency);
        self::assertSame(125000, $money->amount());
        self::assertSame('125000.00', $money->decimal());
        self::assertSame(['currency' => 'VND', 'amount' => '125000.00'], $money->jsonSerialize());
    }

    public function test_rounds_decimal_strings_half_up_to_one_vnd(): void
    {
        self::assertSame(10, Money::vnd('10.49')->amount());
        self::assertSame(11, Money::vnd('10.50')->amount());
        self::assertSame(-10, Money::vnd('-10.49')->amount());
        self::assertSame(-11, Money::vnd('-10.50')->amount());
    }

    public function test_adds_subtracts_and_compares_without_mutation(): void
    {
        $first = Money::vnd(1000);
        $second = Money::vnd(250);

        self::assertSame(1250, $first->add($second)->amount());
        self::assertSame(750, $first->subtract($second)->amount());
        self::assertSame(1, $first->compareTo($second));
        self::assertSame(1000, $first->amount());
        self::assertTrue($first->equals(Money::vnd('1000.00')));
    }

    public function test_multiplies_and_calculates_percentage_with_half_up_rounding(): void
    {
        self::assertSame(13, Money::vnd(100)->multiply('0.125')->amount());
        self::assertSame(-13, Money::vnd(-100)->multiply('0.125')->amount());
        self::assertSame(80, Money::vnd(1000)->percentage('8')->amount());
        self::assertSame(85, Money::vnd(1000)->percentage('8.5')->amount());
        self::assertSame(0, Money::vnd(1)->percentage('49.9')->amount());
        self::assertSame(1, Money::vnd(1)->percentage('50')->amount());
    }

    public function test_rejects_float_non_vnd_and_out_of_range_amount(): void
    {
        try {
            Money::vnd(10.5);
            self::fail('Expected TypeError for float money.');
        } catch (TypeError) {
            self::assertTrue(true);
        }

        try {
            new Money('USD', '10');
            self::fail('Expected InvalidArgumentException for currency.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(OverflowException::class);
        Money::vnd('99999999999999.99');
    }
}

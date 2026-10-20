<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

final readonly class Money implements JsonSerializable
{
    private const CURRENCY = 'VND';
    private const MAX_ABSOLUTE_AMOUNT = 99_999_999_999_999;

    private int $amount;
    public string $currency;

    public function __construct(string $currency, string|int $decimalAmount)
    {
        $currency = strtoupper(trim($currency));
        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException('Money hiện chỉ hỗ trợ tiền tệ VND.');
        }

        $this->currency = $currency;
        $this->amount = self::parseAndRound($decimalAmount);
    }

    public static function vnd(string|int $decimalAmount): self
    {
        return new self(self::CURRENCY, $decimalAmount);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function decimal(): string
    {
        return $this->amount . '.00';
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return self::vnd(self::checkedAmount($this->amount + $other->amount));
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return self::vnd(self::checkedAmount($this->amount - $other->amount));
    }

    public function multiply(string|int $multiplier): self
    {
        [$numerator, $denominator] = self::decimalRatio($multiplier);
        return self::vnd(self::multiplyAndRound($this->amount, $numerator, $denominator));
    }

    public function percentage(string|int $percentage): self
    {
        [$numerator, $denominator] = self::decimalRatio($percentage);
        return self::vnd(self::multiplyAndRound($this->amount, $numerator, $denominator * 100));
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);
        return $this->amount <=> $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /** @return array{currency: string, amount: string} */
    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'amount' => $this->decimal(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Không thể thao tác trên hai loại tiền tệ khác nhau.');
        }
    }

    private static function parseAndRound(string|int $decimalAmount): int
    {
        if (is_int($decimalAmount)) {
            return self::checkedAmount($decimalAmount);
        }

        $value = trim($decimalAmount);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Số tiền phải là số nguyên hoặc chuỗi thập phân hợp lệ.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $integerDigits = ltrim($matches[2], '0');
        $integerDigits = $integerDigits === '' ? '0' : $integerDigits;
        if (strlen($integerDigits) > 14) {
            throw new InvalidArgumentException('Số tiền vượt giới hạn DECIMAL(14,2).');
        }

        $absolute = (int) $integerDigits;
        $fraction = $matches[3] ?? '';
        if ($fraction !== '' && $fraction[0] >= '5') {
            ++$absolute;
        }

        return self::checkedAmount($sign * $absolute);
    }

    private static function checkedAmount(int|float $amount): int
    {
        if (!is_int($amount) || abs($amount) > self::MAX_ABSOLUTE_AMOUNT) {
            throw new OverflowException('Số tiền vượt giới hạn DECIMAL(14,2).');
        }

        return $amount;
    }

    /** @return array{int, int} */
    private static function decimalRatio(string|int $value): array
    {
        if (is_int($value)) {
            return [$value, 1];
        }

        $value = trim($value);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d{1,6}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Hệ số phải là số nguyên hoặc chuỗi thập phân có tối đa 6 chữ số lẻ.');
        }

        $fraction = $matches[3] ?? '';
        $denominator = 10 ** strlen($fraction);
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $digits = $whole . str_pad($fraction, strlen($fraction), '0');
        $digits = ltrim($digits, '0');
        $numerator = $digits === '' ? 0 : (int) $digits;
        if ($matches[1] === '-') {
            $numerator *= -1;
        }

        return [$numerator, $denominator];
    }

    private static function multiplyAndRound(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator < 1) {
            throw new InvalidArgumentException('Mẫu số phải là số nguyên dương.');
        }
        if ($amount === 0 || $numerator === 0) {
            return 0;
        }
        if (abs($amount) > intdiv(PHP_INT_MAX, abs($numerator))) {
            throw new OverflowException('Phép nhân tiền vượt giới hạn số nguyên.');
        }

        $product = $amount * $numerator;
        $absoluteProduct = abs($product);
        $rounded = intdiv($absoluteProduct, $denominator);
        $remainder = $absoluteProduct % $denominator;
        if ($remainder * 2 >= $denominator) {
            ++$rounded;
        }

        return self::checkedAmount($product < 0 ? -$rounded : $rounded);
    }
}

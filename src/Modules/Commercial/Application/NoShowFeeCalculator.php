<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Shared\Money\Money;
use InvalidArgumentException;

final readonly class NoShowFeeCalculator
{
    /**
     * @param list<array{
     *     total: Money|string|int,
     *     cancellationSnapshot: array<string, mixed>
     * }> $items
     */
    public function calculate(Money $bookingTotal, array $items): Money
    {
        if ($bookingTotal->amount() < 0 || $items === [] || !array_is_list($items)) {
            throw new InvalidArgumentException('Snapshot booking dùng để tính phí no-show không hợp lệ.');
        }

        $fee = Money::vnd(0);
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Snapshot hạng mục no-show không hợp lệ.');
            }
            $itemTotal = $this->money($item['total'] ?? null);
            $snapshot = $item['cancellationSnapshot'] ?? null;
            if (!is_array($snapshot)) {
                throw new InvalidArgumentException('Snapshot chính sách no-show không hợp lệ.');
            }
            $rate = $this->percentage($snapshot['noShowPenaltyRate'] ?? null);
            $fee = $fee->add($itemTotal->percentage($rate));
        }

        return $fee->compareTo($bookingTotal) > 0 ? $bookingTotal : $fee;
    }

    private function money(mixed $value): Money
    {
        if ($value instanceof Money) {
            $money = $value;
        } elseif (is_string($value) || is_int($value)) {
            $money = Money::vnd($value);
        } else {
            throw new InvalidArgumentException('Tổng hạng mục trong snapshot no-show không hợp lệ.');
        }
        if ($money->amount() < 0) {
            throw new InvalidArgumentException('Tổng hạng mục trong snapshot no-show không được âm.');
        }
        return $money;
    }

    private function percentage(mixed $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException('Tỷ lệ phạt no-show trong snapshot không hợp lệ.');
        }
        $hundredths = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($hundredths > 10_000) {
            throw new InvalidArgumentException('Tỷ lệ phạt no-show không được vượt quá 100.');
        }
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }
}

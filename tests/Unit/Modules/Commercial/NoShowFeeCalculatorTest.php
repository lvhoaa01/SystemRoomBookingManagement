<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\NoShowFeeCalculator;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class NoShowFeeCalculatorTest extends TestCase
{
    public function test_rounds_each_item_before_summing(): void
    {
        $fee = (new NoShowFeeCalculator())->calculate(
            Money::vnd(202),
            [
                $this->item(101, '50'),
                $this->item(101, '50'),
            ],
        );

        self::assertSame(102, $fee->amount());
    }

    public function test_caps_summed_item_fees_at_booking_total(): void
    {
        $fee = (new NoShowFeeCalculator())->calculate(
            Money::vnd(150),
            [
                $this->item(100, '100'),
                $this->item(100, '100'),
            ],
        );

        self::assertSame(150, $fee->amount());
    }

    /** @return array<string, mixed> */
    private function item(int $total, string $rate): array
    {
        return [
            'total' => Money::vnd($total),
            'cancellationSnapshot' => ['noShowPenaltyRate' => $rate],
        ];
    }
}

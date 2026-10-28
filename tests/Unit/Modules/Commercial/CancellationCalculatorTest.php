<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\CancellationCalculator;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CancellationCalculatorTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function penalties(): iterable
    {
        yield 'none' => ['NONE', '0', 0];
        yield 'percent is rounded per item' => ['PERCENT', '33.33', 33_330];
        yield 'first night' => ['FIRST_NIGHT', '1', 40_000];
        yield 'full stay' => ['FULL_STAY', '100', 100_000];
    }

    #[DataProvider('penalties')]
    public function test_supports_all_four_penalty_types(string $type, string $value, int $expected): void
    {
        $fee = (new CancellationCalculator())->calculate(
            Money::vnd(100_000),
            [$this->item(100_000, 'NON_REFUNDABLE', null, $type, $value)],
            '2027-01-10',
            '14:00:00',
            'Asia/Ho_Chi_Minh',
            new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame($expected, $fee->amount());
    }

    public function test_flexible_policy_is_free_before_deadline_but_charged_at_exact_deadline(): void
    {
        $calculator = new CancellationCalculator();
        $items = [$this->item(100_000, 'FLEXIBLE', 24, 'FULL_STAY', '100')];

        $before = $calculator->calculate(
            Money::vnd(100_000),
            $items,
            '2027-01-10',
            '14:00:00',
            'Asia/Ho_Chi_Minh',
            new DateTimeImmutable('2027-01-09 06:59:59', new DateTimeZone('UTC')),
        );
        $atDeadline = $calculator->calculate(
            Money::vnd(100_000),
            $items,
            '2027-01-10',
            '14:00:00',
            'Asia/Ho_Chi_Minh',
            new DateTimeImmutable('2027-01-09 07:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(0, $before->amount());
        self::assertSame(100_000, $atDeadline->amount());
    }

    public function test_rounds_each_item_then_sums_and_caps_at_booking_total(): void
    {
        $items = [
            $this->item(101, 'NON_REFUNDABLE', null, 'PERCENT', '50'),
            $this->item(101, 'NON_REFUNDABLE', null, 'PERCENT', '50'),
        ];

        $fee = (new CancellationCalculator())->calculate(
            Money::vnd(101),
            $items,
            '2027-01-10',
            '14:00:00',
            'UTC',
            new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(101, $fee->amount());
    }

    /** @return array<string, mixed> */
    private function item(
        int $total,
        string $policyType,
        ?int $freeHours,
        string $penaltyType,
        string $penaltyValue,
    ): array {
        return [
            'total' => Money::vnd($total),
            'nights' => [
                ['stayDate' => '2027-01-11', 'total' => Money::vnd($total - 40_000)],
                ['stayDate' => '2027-01-10', 'total' => Money::vnd(40_000)],
            ],
            'cancellationSnapshot' => [
                'policyType' => $policyType,
                'freeCancellationHours' => $freeHours,
                'penaltyType' => $penaltyType,
                'penaltyValue' => $penaltyValue,
            ],
        ];
    }
}

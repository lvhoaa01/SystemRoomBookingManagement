<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StandardPriceCalculatorTest extends TestCase
{
    public function test_requires_a_rate_for_every_night(): void
    {
        $product = $this->product('PAY_AT_PROPERTY', [$this->rate('2027-01-10', 100)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Thiếu giá');
        $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-12'));
    }

    public function test_restrictions_come_only_from_check_in_rate(): void
    {
        $product = $this->product('PAY_AT_PROPERTY', [
            $this->rate('2027-01-10', 100, minimumStay: 2, maximumStay: 2, minimumLeadDays: 2),
            $this->rate('2027-01-11', 100, minimumStay: 10, maximumStay: 10, minimumLeadDays: 30),
        ]);

        $breakdown = $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-12'));

        self::assertCount(2, $breakdown->nights);
    }

    public function test_promotion_tie_breaks_by_discount_descending_then_id_ascending(): void
    {
        $product = $this->product(
            'PAY_AT_PROPERTY',
            [$this->rate('2027-01-10', 1_000)],
            [
                ['id' => 3, 'discountRate' => '10'],
                ['id' => 20, 'discountRate' => '15'],
                ['id' => 10, 'discountRate' => '15'],
            ],
        );

        $breakdown = $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-11'));

        self::assertSame(10, $breakdown->promotionId);
        self::assertSame(150, $breakdown->discountTotal->amount());
    }

    public function test_rounds_each_component_and_night_before_totalling(): void
    {
        $product = $this->product('PAY_AT_PROPERTY', [
            $this->rate('2027-01-10', 101),
            $this->rate('2027-01-11', 101),
        ], taxRate: '7.5', serviceFeeRate: '2.5');

        $breakdown = $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-12'));

        self::assertSame('8.00', $breakdown->nights[0]['taxAmount']);
        self::assertSame('3.00', $breakdown->nights[0]['serviceFeeAmount']);
        self::assertSame(224, $breakdown->grandTotal->amount());
    }

    public function test_pay_at_property_preserves_included_flags_and_uses_all_or_nothing_split(): void
    {
        $product = $this->product(
            'PAY_AT_PROPERTY',
            [$this->rate('2027-01-10', 100)],
            taxRate: '8',
            taxIncluded: true,
            serviceFeeRate: '5',
            serviceFeeIncluded: false,
        );

        $breakdown = $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-11'));

        self::assertSame('8.00', $breakdown->nights[0]['includedTaxFee']);
        self::assertSame('5.00', $breakdown->nights[0]['taxFeeAtProperty']);
        self::assertSame(0, $breakdown->payNowTotal->amount());
        self::assertSame(113, $breakdown->payAtPropertyTotal->amount());
    }

    public function test_pay_online_never_leaves_tax_or_fee_at_property(): void
    {
        $product = $this->product(
            'PAY_ONLINE',
            [$this->rate('2027-01-10', 100)],
            taxRate: '8',
            taxIncluded: false,
            serviceFeeRate: '5',
            serviceFeeIncluded: false,
        );

        $breakdown = $this->calculate($product, new StayPeriod('2027-01-10', '2027-01-11'));

        self::assertSame('13.00', $breakdown->nights[0]['includedTaxFee']);
        self::assertSame('0.00', $breakdown->nights[0]['taxFeeAtProperty']);
        self::assertSame(113, $breakdown->payNowTotal->amount());
        self::assertSame(0, $breakdown->payAtPropertyTotal->amount());
    }

    private function calculate(ProductPricingSnapshot $product, StayPeriod $stay): \App\Shared\Contract\PriceBreakdown
    {
        return (new StandardPriceCalculator())->calculate(
            $product,
            $stay,
            new RoomParty(1, 2, [7]),
            new DateTimeImmutable('2027-01-07 12:00:00', new DateTimeZone('Asia/Ho_Chi_Minh')),
        );
    }

    /** @param list<array<string, mixed>> $rates @param list<array{id: int, discountRate: string}> $promotions */
    private function product(
        string $paymentTiming,
        array $rates,
        array $promotions = [],
        string $taxRate = '0',
        bool $taxIncluded = true,
        string $serviceFeeRate = '0',
        bool $serviceFeeIncluded = true,
    ): ProductPricingSnapshot {
        return new ProductPricingSnapshot(
            $paymentTiming,
            'Asia/Ho_Chi_Minh',
            $taxRate,
            $taxIncluded,
            $serviceFeeRate,
            $serviceFeeIncluded,
            $rates,
            $promotions,
            ['policyType' => 'FLEXIBLE'],
        );
    }

    /** @return array<string, mixed> */
    private function rate(
        string $date,
        int $price,
        int $minimumStay = 1,
        ?int $maximumStay = null,
        int $minimumLeadDays = 0,
    ): array {
        return [
            'stayDate' => $date,
            'basePrice' => (string) $price,
            'closed' => false,
            'minimumStay' => $minimumStay,
            'maximumStay' => $maximumStay,
            'minimumLeadDays' => $minimumLeadDays,
            'version' => 1,
        ];
    }
}

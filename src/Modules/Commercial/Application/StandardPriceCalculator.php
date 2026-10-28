<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use App\Shared\Contract\PriceBreakdown;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\StayPeriod;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class StandardPriceCalculator implements PriceCalculator
{
    public function calculate(
        ProductPricingSnapshot $product,
        StayPeriod $stay,
        RoomParty $party,
        DateTimeImmutable $bookedAt,
    ): PriceBreakdown {
        if (!in_array($product->paymentTiming, ['PAY_ONLINE', 'PAY_AT_PROPERTY'], true)) {
            throw new InvalidArgumentException('Thời điểm thanh toán của sản phẩm không hợp lệ.');
        }
        $taxRate = $this->percentage($product->taxRate, 'Tỷ lệ thuế');
        $serviceFeeRate = $this->percentage($product->serviceFeeRate, 'Tỷ lệ phí dịch vụ');
        $rates = $this->ratesByDate($product->rates);
        $this->assertRestrictions($rates, $stay, $bookedAt, $product->timezone);
        $promotion = $this->selectPromotion($product->promotions);

        $subtotal = Money::vnd(0);
        $discountTotal = Money::vnd(0);
        $includedTaxFeeTotal = Money::vnd(0);
        $grandTotal = Money::vnd(0);
        $nights = [];
        $versions = [];

        foreach ($stay->nights() as $stayDate) {
            $rate = $rates[$stayDate];
            $basePrice = $this->money($rate['basePrice'] ?? null, 'Giá cơ bản');
            $discount = $promotion === null
                ? Money::vnd(0)
                : $basePrice->percentage($promotion['discountRate']);
            $discountedBase = $basePrice->subtract($discount);
            $taxAmount = $discountedBase->percentage($taxRate);
            $serviceFeeAmount = $discountedBase->percentage($serviceFeeRate);

            [$includedTaxFee, $atPropertyTaxFee] = $this->splitTaxAndFee(
                $product,
                $taxAmount,
                $serviceFeeAmount,
            );
            $nightTotal = $discountedBase->add($taxAmount)->add($serviceFeeAmount);
            $version = $this->positiveInteger($rate['version'] ?? null, 'Phiên bản giá');

            $nights[] = [
                'stayDate' => $stayDate,
                'basePrice' => $basePrice->decimal(),
                'discount' => $discount->decimal(),
                'discountedBase' => $discountedBase->decimal(),
                'taxRate' => $taxRate,
                'taxIncluded' => $product->taxIncluded,
                'taxAmount' => $taxAmount->decimal(),
                'serviceFeeRate' => $serviceFeeRate,
                'serviceFeeIncluded' => $product->serviceFeeIncluded,
                'serviceFeeAmount' => $serviceFeeAmount->decimal(),
                'includedTaxFee' => $includedTaxFee->decimal(),
                'taxFeeAtProperty' => $atPropertyTaxFee->decimal(),
                'total' => $nightTotal->decimal(),
                'promotionId' => $promotion['id'] ?? null,
                'rateVersion' => $version,
            ];
            $versions[] = $version;
            $subtotal = $subtotal->add($basePrice);
            $discountTotal = $discountTotal->add($discount);
            $includedTaxFeeTotal = $includedTaxFeeTotal->add($includedTaxFee);
            $grandTotal = $grandTotal->add($nightTotal);
        }

        $payNow = $product->paymentTiming === 'PAY_ONLINE' ? $grandTotal : Money::vnd(0);
        $payAtProperty = $product->paymentTiming === 'PAY_AT_PROPERTY' ? $grandTotal : Money::vnd(0);
        $paymentSnapshot = [
            'paymentTiming' => $product->paymentTiming,
            'taxRate' => $taxRate,
            'taxIncluded' => $product->taxIncluded,
            'serviceFeeRate' => $serviceFeeRate,
            'serviceFeeIncluded' => $product->serviceFeeIncluded,
            'party' => $party->jsonSerialize(),
        ];

        return new PriceBreakdown(
            $product->paymentTiming,
            $nights,
            $subtotal,
            $discountTotal,
            $includedTaxFeeTotal,
            $payNow,
            $payAtProperty,
            $grandTotal,
            $promotion['id'] ?? null,
            $product->cancellationSnapshot,
            $paymentSnapshot,
            $versions,
        );
    }

    /** @param list<array<string, mixed>> $rates @return array<string, array<string, mixed>> */
    private function ratesByDate(array $rates): array
    {
        if ($rates === [] || !array_is_list($rates)) {
            throw new InvalidArgumentException('Lịch giá phải là danh sách không rỗng.');
        }
        $indexed = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                throw new InvalidArgumentException('Mỗi dòng lịch giá phải là array.');
            }
            $date = trim((string) ($rate['stayDate'] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || isset($indexed[$date])) {
                throw new InvalidArgumentException('Ngày hoặc tính duy nhất của lịch giá không hợp lệ.');
            }
            $indexed[$date] = $rate;
        }
        return $indexed;
    }

    /** @param array<string, array<string, mixed>> $rates */
    private function assertRestrictions(
        array $rates,
        StayPeriod $stay,
        DateTimeImmutable $bookedAt,
        string $timezone,
    ): void {
        foreach ($stay->nights() as $stayDate) {
            if (!isset($rates[$stayDate])) {
                throw new InvalidArgumentException('Thiếu giá cho ít nhất một đêm lưu trú.');
            }
            if ($this->boolean($rates[$stayDate]['closed'] ?? null)) {
                throw new InvalidArgumentException('Ít nhất một đêm lưu trú đang đóng bán.');
            }
        }

        $checkInRate = $rates[$stay->checkIn];
        $minimumStay = $this->positiveInteger($checkInRate['minimumStay'] ?? null, 'Số đêm tối thiểu');
        $maximumStay = $this->nullablePositiveInteger($checkInRate['maximumStay'] ?? null, 'Số đêm tối đa');
        if ($stay->nightCount() < $minimumStay
            || ($maximumStay !== null && $stay->nightCount() > $maximumStay)) {
            throw new InvalidArgumentException('Kỳ lưu trú vi phạm giới hạn số đêm tại ngày nhận phòng.');
        }

        $minimumLeadDays = $this->nonNegativeInteger(
            $checkInRate['minimumLeadDays'] ?? null,
            'Số ngày đặt trước',
        );
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('Múi giờ cơ sở không hợp lệ.');
        }
        $localBookingDate = new DateTimeImmutable($bookedAt->setTimezone($zone)->format('Y-m-d'), $zone);
        $localCheckInDate = new DateTimeImmutable($stay->checkIn, $zone);
        if ($localBookingDate > $localCheckInDate
            || (int) $localBookingDate->diff($localCheckInDate)->days < $minimumLeadDays) {
            throw new InvalidArgumentException('Kỳ lưu trú vi phạm số ngày đặt trước tối thiểu.');
        }
    }

    /**
     * @param list<array{id: int, discountRate: string}> $promotions
     * @return array{id: int, discountRate: string}|null
     */
    private function selectPromotion(array $promotions): ?array
    {
        if (!array_is_list($promotions)) {
            throw new InvalidArgumentException('Danh sách khuyến mãi không hợp lệ.');
        }
        $normalized = [];
        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                throw new InvalidArgumentException('Khuyến mãi áp dụng không hợp lệ.');
            }
            $id = $this->positiveInteger($promotion['id'] ?? null, 'ID khuyến mãi');
            $rate = $this->percentage($promotion['discountRate'] ?? null, 'Tỷ lệ khuyến mãi');
            if ($this->percentageHundredths($rate) === 0) {
                throw new InvalidArgumentException('Tỷ lệ khuyến mãi phải lớn hơn 0.');
            }
            $normalized[] = ['id' => $id, 'discountRate' => $rate];
        }
        usort($normalized, function (array $left, array $right): int {
            $rateOrder = $this->percentageHundredths($right['discountRate'])
                <=> $this->percentageHundredths($left['discountRate']);
            return $rateOrder !== 0 ? $rateOrder : $left['id'] <=> $right['id'];
        });
        return $normalized[0] ?? null;
    }

    /** @return array{Money, Money} */
    private function splitTaxAndFee(
        ProductPricingSnapshot $product,
        Money $tax,
        Money $serviceFee,
    ): array {
        if ($product->paymentTiming === 'PAY_ONLINE') {
            return [$tax->add($serviceFee), Money::vnd(0)];
        }
        $included = Money::vnd(0);
        $atProperty = Money::vnd(0);
        foreach ([[$tax, $product->taxIncluded], [$serviceFee, $product->serviceFeeIncluded]] as [$amount, $includedFlag]) {
            if ($includedFlag) {
                $included = $included->add($amount);
            } else {
                $atProperty = $atProperty->add($amount);
            }
        }
        return [$included, $atProperty];
    }

    private function money(mixed $value, string $label): Money
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
        $money = Money::vnd($value);
        if ($money->amount() <= 0) {
            throw new InvalidArgumentException($label . ' phải lớn hơn 0.');
        }
        return $money;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, '0'], true)) {
            return false;
        }
        if (in_array($value, [1, '1'], true)) {
            return true;
        }
        throw new InvalidArgumentException('Cờ đóng bán không hợp lệ.');
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 1) {
            throw new InvalidArgumentException($label . ' phải là số nguyên dương.');
        }
        return (int) $number;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 0) {
            throw new InvalidArgumentException($label . ' phải là số nguyên không âm.');
        }
        return (int) $number;
    }

    private function nullablePositiveInteger(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInteger($value, $label);
    }

    private function percentage(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
        $hundredths = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($hundredths > 10_000) {
            throw new InvalidArgumentException($label . ' không được vượt quá 100.');
        }
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function percentageHundredths(string $value): int
    {
        [$whole, $fraction] = explode('.', $value);
        return ((int) $whole * 100) + (int) $fraction;
    }
}

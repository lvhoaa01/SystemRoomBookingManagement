<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\Money\Money;
use InvalidArgumentException;
use JsonSerializable;

final readonly class PriceBreakdown implements JsonSerializable
{
    private const PAYMENT_TIMINGS = ['PAY_ONLINE', 'PAY_AT_PROPERTY'];

    /**
     * @param list<array<string, mixed>> $nights
     * @param array<string, mixed> $cancellationSnapshot
     * @param array<string, mixed> $paymentSnapshot
     * @param list<int> $rateVersions
     */
    public function __construct(
        public string $paymentTiming,
        public array $nights,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $includedTaxFeeTotal,
        public Money $payNowTotal,
        public Money $payAtPropertyTotal,
        public Money $grandTotal,
        public ?int $promotionId,
        public array $cancellationSnapshot,
        public array $paymentSnapshot,
        public array $rateVersions,
    ) {
        if (!in_array($this->paymentTiming, self::PAYMENT_TIMINGS, true)) {
            throw new InvalidArgumentException('Thời điểm thanh toán không hợp lệ.');
        }
        if ($this->nights === [] || !array_is_list($this->nights)) {
            throw new InvalidArgumentException('PriceBreakdown phải có ít nhất một đêm giá.');
        }
        foreach ($this->nights as $night) {
            if (!is_array($night)) {
                throw new InvalidArgumentException('Mỗi snapshot giá đêm phải là array.');
            }
        }

        $moneyValues = [
            $this->subtotal,
            $this->discountTotal,
            $this->includedTaxFeeTotal,
            $this->payNowTotal,
            $this->payAtPropertyTotal,
            $this->grandTotal,
        ];
        foreach ($moneyValues as $money) {
            if ($money->currency !== 'VND' || $money->amount() < 0) {
                throw new InvalidArgumentException('Các thành phần giá phải là VND không âm.');
            }
        }
        if ($this->discountTotal->compareTo($this->subtotal) > 0) {
            throw new InvalidArgumentException('Tổng giảm giá không được lớn hơn giá trước giảm.');
        }
        if (!$this->payNowTotal->add($this->payAtPropertyTotal)->equals($this->grandTotal)) {
            throw new InvalidArgumentException('Tổng trả ngay và tại cơ sở phải bằng tổng booking.');
        }
        if ($this->paymentTiming === 'PAY_ONLINE'
            && (!$this->payNowTotal->equals($this->grandTotal) || !$this->payAtPropertyTotal->isZero())) {
            throw new InvalidArgumentException('PAY_ONLINE phải thanh toán toàn bộ ngay.');
        }
        if ($this->paymentTiming === 'PAY_AT_PROPERTY'
            && (!$this->payNowTotal->isZero() || !$this->payAtPropertyTotal->equals($this->grandTotal))) {
            throw new InvalidArgumentException('PAY_AT_PROPERTY phải thanh toán toàn bộ tại cơ sở.');
        }
        if ($this->promotionId !== null && $this->promotionId < 1) {
            throw new InvalidArgumentException('Promotion ID phải là số nguyên dương.');
        }
        if (!array_is_list($this->rateVersions)) {
            throw new InvalidArgumentException('Rate versions phải là một danh sách.');
        }
        foreach ($this->rateVersions as $version) {
            if (!is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Mỗi rate version phải là số nguyên dương.');
            }
        }
        if (count($this->rateVersions) !== count($this->nights)) {
            throw new InvalidArgumentException('Mỗi đêm giá phải có đúng một rate version.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'paymentTiming' => $this->paymentTiming,
            'nights' => $this->nights,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discountTotal,
            'includedTaxFeeTotal' => $this->includedTaxFeeTotal,
            'payNowTotal' => $this->payNowTotal,
            'payAtPropertyTotal' => $this->payAtPropertyTotal,
            'grandTotal' => $this->grandTotal,
            'promotionId' => $this->promotionId,
            'cancellationSnapshot' => $this->cancellationSnapshot,
            'paymentSnapshot' => $this->paymentSnapshot,
            'rateVersions' => $this->rateVersions,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use InvalidArgumentException;
use JsonSerializable;

final readonly class RoomAllocation implements JsonSerializable
{
    public function __construct(
        public int $roomOrder,
        public int $roomTypeId,
        public int $productId,
        public string $paymentTiming,
        public RoomParty $party,
        public PriceBreakdown $priceBreakdown,
    ) {
        if ($this->roomOrder < 1 || $this->roomOrder > 5) {
            throw new InvalidArgumentException('Thứ tự phòng phân bổ phải nằm trong khoảng 1–5.');
        }
        if ($this->roomTypeId < 1 || $this->productId < 1) {
            throw new InvalidArgumentException('Room type ID và product ID phải là số nguyên dương.');
        }
        if ($this->party->roomOrder !== $this->roomOrder) {
            throw new InvalidArgumentException('RoomParty phải có cùng thứ tự với RoomAllocation.');
        }
        if ($this->priceBreakdown->paymentTiming !== $this->paymentTiming) {
            throw new InvalidArgumentException('PriceBreakdown và RoomAllocation phải có cùng payment timing.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'roomOrder' => $this->roomOrder,
            'roomTypeId' => $this->roomTypeId,
            'productId' => $this->productId,
            'paymentTiming' => $this->paymentTiming,
            'party' => $this->party,
            'priceBreakdown' => $this->priceBreakdown,
        ];
    }
}

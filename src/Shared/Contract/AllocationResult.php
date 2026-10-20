<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Shared\Money\Money;
use InvalidArgumentException;
use JsonSerializable;

final readonly class AllocationResult implements JsonSerializable
{
    /** @param list<RoomAllocation> $rooms */
    public function __construct(
        public string $paymentTiming,
        public array $rooms,
        public Money $grandTotal,
        public string $selectionFingerprint,
    ) {
        if ($this->rooms === [] || !array_is_list($this->rooms)) {
            throw new InvalidArgumentException('AllocationResult phải có ít nhất một phòng.');
        }

        $orders = [];
        $previousOrder = 0;
        $calculatedTotal = Money::vnd(0);
        foreach ($this->rooms as $room) {
            if (!$room instanceof RoomAllocation) {
                throw new InvalidArgumentException('Mỗi phần tử phân bổ phải là RoomAllocation.');
            }
            if ($room->paymentTiming !== $this->paymentTiming) {
                throw new InvalidArgumentException('Mọi phòng phải có cùng payment timing với kết quả phân bổ.');
            }
            if (isset($orders[$room->roomOrder])) {
                throw new InvalidArgumentException('Thứ tự phòng phân bổ không được trùng.');
            }
            if ($room->roomOrder <= $previousOrder) {
                throw new InvalidArgumentException('Danh sách phòng phải được sắp xếp tăng dần theo roomOrder.');
            }
            $orders[$room->roomOrder] = true;
            $previousOrder = $room->roomOrder;
            $calculatedTotal = $calculatedTotal->add($room->priceBreakdown->grandTotal);
        }

        if (!$calculatedTotal->equals($this->grandTotal)) {
            throw new InvalidArgumentException('Grand total phải bằng tổng giá của các phòng phân bổ.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->selectionFingerprint) !== 1) {
            throw new InvalidArgumentException('Selection fingerprint phải là SHA-256 dạng hex.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'paymentTiming' => $this->paymentTiming,
            'rooms' => $this->rooms,
            'grandTotal' => $this->grandTotal,
            'selectionFingerprint' => $this->selectionFingerprint,
        ];
    }
}

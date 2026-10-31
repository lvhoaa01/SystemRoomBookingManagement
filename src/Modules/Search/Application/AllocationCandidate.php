<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\PriceBreakdown;
use App\Shared\Contract\RoomParty;
use InvalidArgumentException;

final readonly class AllocationCandidate
{
    /**
     * @param array<int, PriceBreakdown> $pricesByRoomOrder
     * @param array<string, int> $inventoryVersions
     */
    public function __construct(
        public int $roomTypeId,
        public int $productId,
        public string $roomTypeName,
        public string $productName,
        public string $mealType,
        public string $paymentTiming,
        public int $maximumGuests,
        public int $maximumAdults,
        public int $maximumChildren,
        public array $pricesByRoomOrder,
        public array $inventoryVersions,
        public string $criteriaFingerprint,
    ) {
        if ($this->roomTypeId < 1 || $this->productId < 1) {
            throw new InvalidArgumentException('ID loại phòng và sản phẩm phải là số nguyên dương.');
        }
        if ($this->maximumGuests < 1 || $this->maximumAdults < 1 || $this->maximumChildren < 0) {
            throw new InvalidArgumentException('Sức chứa phòng không hợp lệ.');
        }
        if (!in_array($this->paymentTiming, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)) {
            throw new InvalidArgumentException('Thời điểm thanh toán của ứng viên không hợp lệ.');
        }
        if ($this->pricesByRoomOrder === []) {
            throw new InvalidArgumentException('Ứng viên phải có giá cho ít nhất một nhóm phòng.');
        }
        foreach ($this->pricesByRoomOrder as $roomOrder => $price) {
            if (!is_int($roomOrder) || $roomOrder < 1 || $roomOrder > 5 || !$price instanceof PriceBreakdown) {
                throw new InvalidArgumentException('Bảng giá theo nhóm phòng không hợp lệ.');
            }
            if ($price->paymentTiming !== $this->paymentTiming) {
                throw new InvalidArgumentException('Giá và ứng viên phải có cùng thời điểm thanh toán.');
            }
        }
        foreach ($this->inventoryVersions as $date => $version) {
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Phiên bản tồn theo ngày không hợp lệ.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->criteriaFingerprint) !== 1) {
            throw new InvalidArgumentException('Fingerprint tiêu chí phải là SHA-256 dạng hex.');
        }
    }

    public function canHost(RoomParty $party): bool
    {
        return $party->guests() <= $this->maximumGuests
            && $party->adults <= $this->maximumAdults
            && $party->children() <= $this->maximumChildren
            && isset($this->pricesByRoomOrder[$party->roomOrder]);
    }

    public function priceFor(RoomParty $party): PriceBreakdown
    {
        if (!$this->canHost($party)) {
            throw new InvalidArgumentException('Ứng viên không đáp ứng nhóm khách được yêu cầu.');
        }
        return $this->pricesByRoomOrder[$party->roomOrder];
    }
}

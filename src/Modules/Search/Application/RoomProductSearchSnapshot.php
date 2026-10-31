<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use InvalidArgumentException;

final readonly class RoomProductSearchSnapshot
{
    /**
     * @param list<array<string, mixed>> $roomImages
     * @param list<array<string, mixed>> $roomFacilities
     * @param array<string, int> $inventoryVersions
     */
    public function __construct(
        public int $roomTypeId,
        public string $roomTypeName,
        public ?string $roomDescription,
        public int $maximumGuests,
        public int $maximumAdults,
        public int $maximumChildren,
        public array $bedConfiguration,
        public ?string $areaSquareMeters,
        public bool $smokingAllowed,
        public array $roomImages,
        public array $roomFacilities,
        public int $productId,
        public string $productName,
        public string $mealType,
        public string $paymentTiming,
        public ProductPricingSnapshot $pricing,
        public int $minimumAvailable,
        public array $inventoryVersions,
    ) {
        if ($this->roomTypeId < 1 || $this->productId < 1 || $this->minimumAvailable < 1) {
            throw new InvalidArgumentException('Snapshot phòng/sản phẩm phải có ID và tồn dương.');
        }
        if ($this->maximumGuests < 1 || $this->maximumAdults < 1 || $this->maximumChildren < 0) {
            throw new InvalidArgumentException('Sức chứa trong snapshot không hợp lệ.');
        }
        if ($this->pricing->paymentTiming !== $this->paymentTiming) {
            throw new InvalidArgumentException('Snapshot giá và sản phẩm phải cùng thời điểm thanh toán.');
        }
    }
}

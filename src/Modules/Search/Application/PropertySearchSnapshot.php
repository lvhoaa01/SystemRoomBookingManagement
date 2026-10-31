<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use InvalidArgumentException;

final readonly class PropertySearchSnapshot
{
    /**
     * @param list<array<string, mixed>> $images
     * @param list<array<string, mixed>> $facilities
     * @param list<RoomProductSearchSnapshot> $roomProducts
     */
    public function __construct(
        public int $propertyId,
        public string $slug,
        public string $name,
        public string $propertyType,
        public string $address,
        public ?string $description,
        public array $images,
        public ?string $starRating,
        public string $taxRate,
        public bool $taxIncluded,
        public string $serviceFeeRate,
        public bool $serviceFeeIncluded,
        public string $checkInTime,
        public string $checkOutTime,
        public string $timezone,
        public ?string $mapUrl,
        public string $reviewScore,
        public int $reviewCount,
        public array $facilities,
        public array $roomProducts,
    ) {
        if ($this->propertyId < 1 || trim($this->slug) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Snapshot cơ sở lưu trú không hợp lệ.');
        }
        if ($this->roomProducts === []) {
            throw new InvalidArgumentException('Snapshot tìm kiếm phải có ít nhất một sản phẩm còn bán.');
        }
    }

    /** @return array<int, int> */
    public function availability(): array
    {
        $availability = [];
        foreach ($this->roomProducts as $product) {
            $availability[$product->roomTypeId] = $product->minimumAvailable;
        }
        ksort($availability, SORT_NUMERIC);
        return $availability;
    }
}

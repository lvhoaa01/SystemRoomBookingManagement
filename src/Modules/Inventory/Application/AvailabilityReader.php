<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Shared\Contract\StayPeriod;

interface AvailabilityReader
{
    /**
     * @param list<int> $roomTypeIds
     * @return array<int, int> Số phòng nhỏ nhất còn bán, được đánh chỉ mục bởi room type ID.
     */
    public function getMinimumAvailable(array $roomTypeIds, StayPeriod $stay): array;
}

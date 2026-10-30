<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BookingInventoryItem
{
    /** @var list<string> */
    public array $stayDates;

    /** @param list<string> $stayDates */
    public function __construct(
        public int $bookingItemId,
        public int $roomTypeId,
        array $stayDates,
    ) {
        if ($this->bookingItemId < 1 || $this->roomTypeId < 1) {
            throw new InvalidArgumentException('ID hạng mục và loại phòng phải là số dương.');
        }
        if (!array_is_list($stayDates) || $stayDates === []) {
            throw new InvalidArgumentException('Hạng mục phải có ít nhất một đêm lưu trú.');
        }

        $dates = [];
        foreach ($stayDates as $date) {
            if (!is_string($date)) {
                throw new InvalidArgumentException('Ngày giữ tồn phải là chuỗi Y-m-d.');
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Ngày giữ tồn phải có định dạng Y-m-d hợp lệ.');
            }
            if (isset($dates[$date])) {
                throw new InvalidArgumentException('Một hạng mục không được giữ trùng ngày.');
            }
            $dates[$date] = $date;
        }
        ksort($dates, SORT_STRING);
        $this->stayDates = array_values($dates);
    }
}

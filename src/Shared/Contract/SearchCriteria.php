<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchCriteria implements JsonSerializable
{
    public string $destination;
    public string $sort;

    /**
     * @param list<RoomParty> $parties
     * @param array<string, mixed> $filters
     */
    public function __construct(
        string $destination,
        public StayPeriod $stayPeriod,
        public array $parties,
        public array $filters = [],
        string $sort = 'RECOMMENDED',
        public int $page = 1,
    ) {
        $this->destination = trim($destination);
        $this->sort = trim($sort);
        if ($this->destination === '') {
            throw new InvalidArgumentException('Điểm đến không được để trống.');
        }
        if ($this->parties === [] || !array_is_list($this->parties) || count($this->parties) > 5) {
            throw new InvalidArgumentException('Tìm kiếm phải có từ 1 đến 5 nhóm phòng.');
        }
        $orders = [];
        foreach ($this->parties as $party) {
            if (!$party instanceof RoomParty) {
                throw new InvalidArgumentException('Mỗi nhóm khách phải là RoomParty.');
            }
            if (isset($orders[$party->roomOrder])) {
                throw new InvalidArgumentException('Thứ tự nhóm phòng không được trùng.');
            }
            $orders[$party->roomOrder] = true;
        }
        if ($this->sort === '') {
            throw new InvalidArgumentException('Tiêu chí sắp xếp không được để trống.');
        }
        if ($this->page < 1) {
            throw new InvalidArgumentException('Số trang phải từ 1 trở lên.');
        }
        foreach (array_keys($this->filters) as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Tên bộ lọc không hợp lệ.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'destination' => $this->destination,
            'stayPeriod' => $this->stayPeriod,
            'parties' => $this->parties,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'page' => $this->page,
        ];
    }
}

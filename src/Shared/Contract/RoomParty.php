<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use InvalidArgumentException;
use JsonSerializable;

final readonly class RoomParty implements JsonSerializable
{
    /** @param list<int> $childAges */
    public function __construct(
        public int $roomOrder,
        public int $adults,
        public array $childAges = [],
    ) {
        if ($this->roomOrder < 1 || $this->roomOrder > 5) {
            throw new InvalidArgumentException('Thứ tự phòng phải nằm trong khoảng 1–5.');
        }
        if ($this->adults < 1) {
            throw new InvalidArgumentException('Mỗi phòng phải có ít nhất một người lớn.');
        }
        if (!array_is_list($this->childAges)) {
            throw new InvalidArgumentException('Tuổi trẻ em phải là một danh sách.');
        }
        foreach ($this->childAges as $age) {
            if (!is_int($age) || $age < 0 || $age > 17) {
                throw new InvalidArgumentException('Tuổi trẻ em phải là số nguyên từ 0 đến 17.');
            }
        }
    }

    public function children(): int
    {
        return count($this->childAges);
    }

    public function guests(): int
    {
        return $this->adults + $this->children();
    }

    /** @return array{roomOrder: int, adults: int, childAges: list<int>} */
    public function jsonSerialize(): array
    {
        return [
            'roomOrder' => $this->roomOrder,
            'adults' => $this->adults,
            'childAges' => $this->childAges,
        ];
    }
}

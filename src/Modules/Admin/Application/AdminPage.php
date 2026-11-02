<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use InvalidArgumentException;

final readonly class AdminPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0 || !array_is_list($items)) {
            throw new InvalidArgumentException('Trang quản trị không hợp lệ.');
        }
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}

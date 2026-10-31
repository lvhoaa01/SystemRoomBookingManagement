<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\SearchCriteria;
use InvalidArgumentException;

final readonly class SearchPage
{
    /** @param list<PropertySearchResult> $results */
    public function __construct(
        public SearchCriteria $criteria,
        public array $results,
        public int $total,
        public int $page,
        public int $pageSize,
        public int $totalPages,
    ) {
        if ($this->page < 1 || $this->pageSize < 1 || $this->total < 0 || $this->totalPages < 0) {
            throw new InvalidArgumentException('Thông tin phân trang tìm kiếm không hợp lệ.');
        }
    }
}

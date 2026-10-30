<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InventoryDayData
{
    public function __construct(
        public string $stayDate,
        public int $totalQuantity,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->stayDate);
        if ($date === false || $date->format('Y-m-d') !== $this->stayDate) {
            throw new InvalidArgumentException('Ngày tồn phòng phải có định dạng Y-m-d hợp lệ.');
        }
        if ($this->totalQuantity < 0 || $this->totalQuantity > 65_535) {
            throw new InvalidArgumentException('Tổng số phòng phải nằm trong khoảng 0 đến 65535.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $total = filter_var($input['totalQuantity'] ?? null, FILTER_VALIDATE_INT);
        if ($total === false) {
            throw new InvalidArgumentException('Tổng số phòng phải là số nguyên.');
        }

        return new self(trim((string) ($input['stayDate'] ?? '')), $total);
    }

    /** @return array{stayDate: string, totalQuantity: int} */
    public function persistenceData(): array
    {
        return ['stayDate' => $this->stayDate, 'totalQuantity' => $this->totalQuantity];
    }
}

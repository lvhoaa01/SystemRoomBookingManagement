<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use JsonSerializable;

final readonly class InventoryReconciliationIssue implements JsonSerializable
{
    public function __construct(
        public int $roomTypeId,
        public string $stayDate,
        public ?int $totalQuantity,
        public ?int $counterHeldQuantity,
        public int $ledgerHeldQuantity,
    ) {
    }

    public function difference(): ?int
    {
        return $this->counterHeldQuantity === null
            ? null
            : $this->counterHeldQuantity - $this->ledgerHeldQuantity;
    }

    /** @return array<string, int|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'roomTypeId' => $this->roomTypeId,
            'stayDate' => $this->stayDate,
            'totalQuantity' => $this->totalQuantity,
            'counterHeldQuantity' => $this->counterHeldQuantity,
            'ledgerHeldQuantity' => $this->ledgerHeldQuantity,
            'difference' => $this->difference(),
        ];
    }
}

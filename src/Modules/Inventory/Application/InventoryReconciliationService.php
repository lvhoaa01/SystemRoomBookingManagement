<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InventoryReconciliationService
{
    public function __construct(private InventoryRepository $inventory)
    {
    }

    /** @return list<InventoryReconciliationIssue> */
    public function reconcile(string $fromDate, string $toDate, ?int $roomTypeId = null): array
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
        if ($from === false || $to === false
            || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate
            || $from > $to) {
            throw new InvalidArgumentException('Khoảng ngày đối soát không hợp lệ.');
        }
        if ($roomTypeId !== null && $roomTypeId < 1) {
            throw new InvalidArgumentException('ID loại phòng đối soát phải là số dương.');
        }

        return array_map(
            static fn (array $row): InventoryReconciliationIssue => new InventoryReconciliationIssue(
                (int) $row['roomTypeId'],
                (string) $row['stayDate'],
                $row['totalQuantity'] === null ? null : (int) $row['totalQuantity'],
                $row['counterHeldQuantity'] === null ? null : (int) $row['counterHeldQuantity'],
                (int) $row['ledgerHeldQuantity'],
            ),
            $this->inventory->findReconciliationIssues($fromDate, $toDate, $roomTypeId),
        );
    }
}

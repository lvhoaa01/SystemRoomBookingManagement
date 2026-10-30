<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\AvailabilityReader;
use App\Shared\Contract\StayPeriod;
use InvalidArgumentException;
use PDO;

final readonly class DatabaseAvailabilityReader implements AvailabilityReader
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getMinimumAvailable(array $roomTypeIds, StayPeriod $stay): array
    {
        if (!array_is_list($roomTypeIds)) {
            throw new InvalidArgumentException('Danh sách loại phòng phải là list.');
        }

        $ids = [];
        foreach ($roomTypeIds as $roomTypeId) {
            if (!is_int($roomTypeId) || $roomTypeId < 1) {
                throw new InvalidArgumentException('ID loại phòng phải là số nguyên dương.');
            }
            $ids[$roomTypeId] = $roomTypeId;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT LoaiPhongID AS roomTypeId, COUNT(*) AS coveredNights,
                    MIN(TongSoLuong - SoLuongDaGiu) AS minimumAvailable
             FROM TonPhongNgay
             WHERE LoaiPhongID IN (' . $placeholders . ')
                 AND NgayLuuTru >= ? AND NgayLuuTru < ?
             GROUP BY LoaiPhongID'
        );
        $statement->execute([...$ids, $stay->checkIn, $stay->checkOut]);

        $minimums = array_fill_keys($ids, 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roomTypeId = (int) $row['roomTypeId'];
            if ((int) $row['coveredNights'] === $stay->nightCount()) {
                $minimums[$roomTypeId] = (int) $row['minimumAvailable'];
            }
        }

        return $minimums;
    }
}

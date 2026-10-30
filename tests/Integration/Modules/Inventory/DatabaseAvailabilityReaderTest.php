<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Inventory\Infrastructure\DatabaseAvailabilityReader;
use App\Shared\Contract\StayPeriod;
use InvalidArgumentException;
use PDO;
use Tests\Support\TestCase;

final class DatabaseAvailabilityReaderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_returns_minimum_for_complete_stay_and_zero_when_one_night_is_missing(): void
    {
        $roomTypeIds = $this->roomTypeIds(2);
        $insert = $this->pdo->prepare(
            'INSERT INTO TonPhongNgay
                (LoaiPhongID, NgayLuuTru, TongSoLuong, SoLuongDaGiu, PhienBan)
             VALUES (?, ?, ?, ?, 1)'
        );
        foreach ([['2034-01-01', 5, 1], ['2034-01-02', 4, 2], ['2034-01-03', 6, 1]] as $row) {
            $insert->execute([$roomTypeIds[0], ...$row]);
        }
        foreach ([['2034-01-01', 3, 0], ['2034-01-03', 3, 0]] as $row) {
            $insert->execute([$roomTypeIds[1], ...$row]);
        }

        $result = (new DatabaseAvailabilityReader($this->pdo))->getMinimumAvailable(
            [$roomTypeIds[1], $roomTypeIds[0], $roomTypeIds[0]],
            new StayPeriod('2034-01-01', '2034-01-04'),
        );

        self::assertSame([$roomTypeIds[0] => 2, $roomTypeIds[1] => 0], $result);
    }

    public function test_rejects_non_list_ids(): void
    {
        $reader = new DatabaseAvailabilityReader($this->pdo);

        $this->expectException(InvalidArgumentException::class);
        $reader->getMinimumAvailable(['room' => 1], new StayPeriod('2034-01-01', '2034-01-02'));
    }

    /** @return list<int> */
    private function roomTypeIds(int $limit): array
    {
        $statement = $this->pdo->query('SELECT LoaiPhongID FROM LoaiPhong ORDER BY LoaiPhongID LIMIT ' . $limit);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount($limit, $ids);
        return $ids;
    }
}

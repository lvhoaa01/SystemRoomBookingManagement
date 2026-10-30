<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Inventory;

use App\Modules\Inventory\Application\InventoryDayData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestCase;

final class InventoryDayDataTest extends TestCase
{
    public function test_normalizes_valid_input(): void
    {
        $data = InventoryDayData::fromArray(['stayDate' => '2032-05-10', 'totalQuantity' => '12']);

        self::assertSame(['stayDate' => '2032-05-10', 'totalQuantity' => 12], $data->persistenceData());
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_input(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        InventoryDayData::fromArray($input);
    }

    public static function invalidInputs(): array
    {
        return [
            'invalid date' => [['stayDate' => '2032-02-30', 'totalQuantity' => 1]],
            'negative total' => [['stayDate' => '2032-01-01', 'totalQuantity' => -1]],
            'decimal total' => [['stayDate' => '2032-01-01', 'totalQuantity' => '1.5']],
        ];
    }
}

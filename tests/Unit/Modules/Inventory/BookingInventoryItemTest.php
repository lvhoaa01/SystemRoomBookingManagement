<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Inventory;

use App\Modules\Inventory\Application\BookingInventoryItem;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class BookingInventoryItemTest extends TestCase
{
    public function test_sorts_dates_for_deterministic_ledger_creation(): void
    {
        $item = new BookingInventoryItem(10, 20, ['2032-01-03', '2032-01-01', '2032-01-02']);

        self::assertSame(['2032-01-01', '2032-01-02', '2032-01-03'], $item->stayDates);
    }

    public function test_rejects_duplicate_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BookingInventoryItem(10, 20, ['2032-01-01', '2032-01-01']);
    }
}

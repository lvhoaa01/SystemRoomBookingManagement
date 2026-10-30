<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Inventory;

use App\Modules\Inventory\Application\InventoryReconciliationIssue;
use Tests\Support\TestCase;

final class InventoryReconciliationIssueTest extends TestCase
{
    public function test_exposes_counter_minus_ledger_difference(): void
    {
        $issue = new InventoryReconciliationIssue(4, '2032-01-01', 5, 3, 2);

        self::assertSame(1, $issue->difference());
        self::assertSame(1, $issue->jsonSerialize()['difference']);
    }

    public function test_missing_counter_has_no_numeric_difference(): void
    {
        self::assertNull((new InventoryReconciliationIssue(4, '2032-01-01', null, null, 1))->difference());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Domain\PropertyReviewAction;
use App\Modules\Properties\Domain\PropertyStatus;
use Tests\Support\TestCase;

final class PropertyReviewActionTest extends TestCase
{
    public function test_review_actions_map_to_documented_state_machine(): void
    {
        self::assertSame(PropertyStatus::ACTIVE, PropertyReviewAction::APPROVE->targetStatus());
        self::assertSame(PropertyStatus::DRAFT, PropertyReviewAction::REQUEST_CHANGES->targetStatus());
        self::assertSame(PropertyStatus::REJECTED, PropertyReviewAction::REJECT->targetStatus());
        self::assertSame(PropertyStatus::SUSPENDED, PropertyReviewAction::SUSPEND->targetStatus());
        self::assertSame(PropertyStatus::ACTIVE, PropertyReviewAction::RESTORE->targetStatus());
        self::assertTrue(PropertyReviewAction::REQUEST_CHANGES->requiresNote());
        self::assertFalse(PropertyReviewAction::APPROVE->requiresNote());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;

enum PropertyReviewAction: string
{
    case APPROVE = 'APPROVE';
    case REQUEST_CHANGES = 'REQUEST_CHANGES';
    case REJECT = 'REJECT';
    case SUSPEND = 'SUSPEND';
    case RESTORE = 'RESTORE';

    public function expectedStatus(): PropertyStatus
    {
        return match ($this) {
            self::APPROVE, self::REQUEST_CHANGES, self::REJECT => PropertyStatus::PENDING_REVIEW,
            self::SUSPEND => PropertyStatus::ACTIVE,
            self::RESTORE => PropertyStatus::SUSPENDED,
        };
    }

    public function targetStatus(): PropertyStatus
    {
        return match ($this) {
            self::APPROVE, self::RESTORE => PropertyStatus::ACTIVE,
            self::REQUEST_CHANGES => PropertyStatus::DRAFT,
            self::REJECT => PropertyStatus::REJECTED,
            self::SUSPEND => PropertyStatus::SUSPENDED,
        };
    }

    public function requiresNote(): bool
    {
        return in_array($this, [self::REQUEST_CHANGES, self::REJECT, self::SUSPEND], true);
    }
}

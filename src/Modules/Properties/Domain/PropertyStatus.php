<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;
// Trạng thái
enum PropertyStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case ACTIVE = 'ACTIVE';
    case REJECTED = 'REJECTED';
    case SUSPENDED = 'SUSPENDED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => $target === self::PENDING_REVIEW,
            self::PENDING_REVIEW => in_array($target, [self::DRAFT, self::ACTIVE, self::REJECTED], true),
            self::ACTIVE => in_array($target, [self::PENDING_REVIEW, self::SUSPENDED], true),
            self::SUSPENDED => in_array($target, [self::PENDING_REVIEW, self::ACTIVE], true),
            self::REJECTED => false,
        };
    }

    public function canPartnerEdit(): bool
    {
        return in_array($this, [self::DRAFT, self::ACTIVE, self::SUSPENDED], true);
    }

    public function requiresReviewMetadata(): bool
    {
        return in_array($this, [self::ACTIVE, self::REJECTED, self::SUSPENDED], true);
    }
}

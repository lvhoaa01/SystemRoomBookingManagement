<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Properties\Domain\PropertyReviewAction;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class ReviewPropertyService
{
    public function __construct(
        private PropertyRepository $properties,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function review(
        int $propertyId,
        CurrentUser $reviewer,
        PropertyReviewAction $action,
        ?string $note,
        string $requestId,
        string $clientIp,
    ): void {
        if (!$reviewer->isActive() || !$reviewer->hasRole(UserAccount::ROLE_ADMIN)) {
            throw new DomainException('Chỉ quản trị viên đang hoạt động mới được duyệt cơ sở lưu trú.');
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);
        if ($action->requiresNote() && $note === null) {
            throw new DomainException('Hành động này bắt buộc phải có ghi chú duyệt.');
        }
        if ($note !== null && mb_strlen($note) > 1000) {
            throw new DomainException('Ghi chú duyệt không được vượt quá 1000 ký tự.');
        }

        $this->transactions->run(function () use (
            $propertyId, $reviewer, $action, $note, $requestId, $clientIp
        ): void {
            $before = $this->properties->findById($propertyId, true);
            if ($before === null) {
                throw new DomainException('Không tìm thấy cơ sở lưu trú cần duyệt.');
            }
            if ((string) $before['status'] !== $action->expectedStatus()->value) {
                throw new DomainException('Trạng thái hiện tại không phù hợp với hành động duyệt.');
            }

            $reviewerId = $action->targetStatus()->requiresReviewMetadata() ? $reviewer->id : null;
            if (!$this->properties->transition(
                $propertyId,
                $action->expectedStatus(),
                $action->targetStatus(),
                $reviewerId,
                $note,
            )) {
                throw new DomainException('Trạng thái cơ sở lưu trú vừa thay đổi; vui lòng tải lại trang.');
            }
            $after = $this->properties->findById($propertyId);
            $this->audit->record(
                $reviewer->id,
                'PROPERTY_' . $action->value,
                'PROPERTY',
                (string) $propertyId,
                $requestId,
                $before,
                $after,
                $clientIp,
            );
        });
    }
}

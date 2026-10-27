<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyReadinessChecker;
use App\Modules\Properties\Domain\PropertyReadinessReport;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class SubmitPropertyForReviewService
{
    public function __construct(
        private PropertyRepository $properties,
        private PropertyReadinessRepository $readinessData,
        private PropertyReadinessChecker $checker,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function submit(
        int $propertyId,
        OrganizationContext $organization,
        int $actorId,
        string $requestId,
        string $clientIp,
    ): PropertyReadinessReport {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể gửi duyệt.');
        }

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $requestId, $clientIp
        ): PropertyReadinessReport {
            $before = $this->properties->findForOrganization($propertyId, $organization->id, true);
            if ($before === null) {
                throw new DomainException('Không tìm thấy cơ sở lưu trú trong tổ chức hiện tại.');
            }
            if (PropertyStatus::from((string) $before['status']) !== PropertyStatus::DRAFT) {
                throw new DomainException('Chỉ cơ sở lưu trú ở trạng thái nháp mới được gửi duyệt.');
            }

            $report = $this->checker->check(
                $this->readinessData->isOrganizationActive($propertyId),
                $before,
                $this->readinessData->activeRooms($propertyId),
                $this->readinessData->commercialFacts($propertyId),
            );
            if (!$report->isReady()) {
                return $report;
            }

            if (!$this->properties->transition(
                $propertyId, PropertyStatus::DRAFT, PropertyStatus::PENDING_REVIEW, null, null,
            )) {
                throw new DomainException('Trạng thái cơ sở lưu trú vừa thay đổi; vui lòng tải lại trang.');
            }
            $after = $this->properties->findForOrganization($propertyId, $organization->id);
            $this->audit->record(
                $actorId, 'PROPERTY_SUBMITTED', 'PROPERTY', (string) $propertyId,
                $requestId, $before, $after, $clientIp,
            );
            return $report;
        });
    }
}

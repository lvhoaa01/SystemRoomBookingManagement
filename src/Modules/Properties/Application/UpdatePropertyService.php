<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class UpdatePropertyService
{
    public function __construct(
        private PropertyRepository $properties,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function update(
        int $propertyId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): PropertyStatus {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể sửa danh mục.');
        }
        $propertyData = PropertyData::fromArray($input);

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $propertyData, $requestId, $clientIp
        ): PropertyStatus {
            $before = $this->properties->findForOrganization($propertyId, $organization->id, true);
            if ($before === null) {
                throw new DomainException('Không tìm thấy cơ sở lưu trú trong tổ chức hiện tại.');
            }
            $currentStatus = PropertyStatus::from((string) $before['status']);
            if (!$currentStatus->canPartnerEdit()) {
                throw new DomainException('Cơ sở lưu trú ở trạng thái không cho phép đối tác chỉnh sửa.');
            }

            $targetStatus = $currentStatus;
            if ($propertyData->changesCriticalFields($before)
                && in_array($currentStatus, [PropertyStatus::ACTIVE, PropertyStatus::SUSPENDED], true)) {
                $targetStatus = PropertyStatus::PENDING_REVIEW;
            }

            $data = $propertyData->persistenceData((string) $before['code'], (string) $before['slug'], $before);
            unset($data['code']);
            $data += [
                'status' => $targetStatus->value,
                'reviewerId' => $targetStatus->requiresReviewMetadata() ? $before['reviewerId'] : null,
                'reviewedAt' => $targetStatus->requiresReviewMetadata() ? $before['reviewedAt'] : null,
                'reviewNote' => $targetStatus->requiresReviewMetadata() ? $before['reviewNote'] : null,
            ];
            if (!$this->properties->updateForOrganization($propertyId, $organization->id, $data)) {
                throw new DomainException('Không thể cập nhật cơ sở lưu trú trong tổ chức hiện tại.');
            }

            $after = $this->properties->findForOrganization($propertyId, $organization->id);
            $this->audit->record(
                $actorId, 'PROPERTY_UPDATED', 'PROPERTY', (string) $propertyId,
                $requestId, $before, $after, $clientIp,
            );
            return $targetStatus;
        });
    }
}

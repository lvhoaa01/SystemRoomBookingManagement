<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Infrastructure\PromotionRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class ManagePromotionService
{
    public function __construct(
        private PropertyRepository $properties,
        private PromotionRepository $promotions,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(
        int $propertyId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): int {
        $this->assertOrganizationActive($organization);
        $data = PromotionData::fromArray($input)->persistenceData();

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $data, $requestId, $clientIp
        ): int {
            $this->assertPropertyEditable($propertyId, $organization->id);
            $promotionId = $this->promotions->create($propertyId, $organization->id, $data);
            if ($promotionId === null) {
                throw new DomainException('Không thể tạo khuyến mãi trong tổ chức hiện tại.');
            }
            $after = $this->promotions->findForOrganization($promotionId, $organization->id);
            $this->audit->record(
                $actorId,
                'PROMOTION_CREATED',
                'PROMOTION',
                (string) $promotionId,
                $requestId,
                null,
                $after,
                $clientIp,
            );
            return $promotionId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(
        int $promotionId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertOrganizationActive($organization);
        $data = PromotionData::fromArray($input)->persistenceData();

        $this->transactions->run(function () use (
            $promotionId, $organization, $actorId, $data, $requestId, $clientIp
        ): void {
            $before = $this->promotions->findForOrganization($promotionId, $organization->id);
            if ($before === null) {
                throw new DomainException('Không tìm thấy khuyến mãi trong tổ chức hiện tại.');
            }
            $this->assertPropertyEditable((int) $before['propertyId'], $organization->id);
            if (!$this->promotions->update($promotionId, $organization->id, $data)) {
                throw new DomainException('Không thể cập nhật khuyến mãi trong tổ chức hiện tại.');
            }
            $after = $this->promotions->findForOrganization($promotionId, $organization->id);
            $this->audit->record(
                $actorId,
                'PROMOTION_UPDATED',
                'PROMOTION',
                (string) $promotionId,
                $requestId,
                $before,
                $after,
                $clientIp,
            );
        });
    }

    private function assertOrganizationActive(OrganizationContext $organization): void
    {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý khuyến mãi.');
        }
    }

    private function assertPropertyEditable(int $propertyId, int $organizationId): void
    {
        $property = $this->properties->findForOrganization($propertyId, $organizationId, true);
        if ($property === null || !PropertyStatus::from((string) $property['status'])->canPartnerEdit()) {
            throw new DomainException('Cơ sở lưu trú không tồn tại hoặc không cho phép chỉnh sửa thương mại.');
        }
    }
}

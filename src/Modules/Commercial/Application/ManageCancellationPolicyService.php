<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class ManageCancellationPolicyService
{
    public function __construct(
        private PropertyRepository $properties,
        private CancellationPolicyRepository $policies,
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
        $data = CancellationPolicyData::fromArray($input)->persistenceData();

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $data, $requestId, $clientIp
        ): int {
            $this->assertPropertyEditable($propertyId, $organization->id);
            $policyId = $this->policies->create($propertyId, $organization->id, $data);
            if ($policyId === null) {
                throw new DomainException('Không thể tạo chính sách hủy trong tổ chức hiện tại.');
            }
            $after = $this->policies->findForOrganization($policyId, $organization->id);
            $this->audit->record(
                $actorId,
                'CANCELLATION_POLICY_CREATED',
                'CANCELLATION_POLICY',
                (string) $policyId,
                $requestId,
                null,
                $after,
                $clientIp,
            );
            return $policyId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(
        int $policyId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertOrganizationActive($organization);
        $data = CancellationPolicyData::fromArray($input)->persistenceData();

        $this->transactions->run(function () use (
            $policyId, $organization, $actorId, $data, $requestId, $clientIp
        ): void {
            $before = $this->policies->findForOrganization($policyId, $organization->id);
            if ($before === null) {
                throw new DomainException('Không tìm thấy chính sách hủy trong tổ chức hiện tại.');
            }
            $this->assertPropertyEditable((int) $before['propertyId'], $organization->id);
            if (!$this->policies->update($policyId, $organization->id, $data)) {
                throw new DomainException('Không thể cập nhật chính sách hủy trong tổ chức hiện tại.');
            }
            $after = $this->policies->findForOrganization($policyId, $organization->id);
            $this->audit->record(
                $actorId,
                'CANCELLATION_POLICY_UPDATED',
                'CANCELLATION_POLICY',
                (string) $policyId,
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
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý chính sách hủy.');
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

<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class ManageRoomTypeService
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
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
        $data = RoomTypeData::fromArray($input)->persistenceData();

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $data, $requestId, $clientIp
        ): int {
            $this->assertPropertyEditable($propertyId, $organization->id);
            $roomId = $this->rooms->create($propertyId, $organization->id, $data);
            if ($roomId === null) {
                throw new DomainException('Không thể tạo loại phòng trong tổ chức hiện tại.');
            }
            $after = $this->rooms->findForOrganization($roomId, $organization->id);
            $this->audit->record(
                $actorId, 'ROOM_TYPE_CREATED', 'ROOM_TYPE', (string) $roomId,
                $requestId, null, $after, $clientIp,
            );
            return $roomId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(
        int $roomTypeId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertOrganizationActive($organization);
        $data = RoomTypeData::fromArray($input)->persistenceData();

        $this->transactions->run(function () use (
            $roomTypeId, $organization, $actorId, $data, $requestId, $clientIp
        ): void {
            $before = $this->rooms->findForOrganization($roomTypeId, $organization->id);
            if ($before === null) {
                throw new DomainException('Không tìm thấy loại phòng trong tổ chức hiện tại.');
            }
            $this->assertPropertyEditable((int) $before['propertyId'], $organization->id);
            if (!$this->rooms->update($roomTypeId, $organization->id, $data)) {
                throw new DomainException('Không thể cập nhật loại phòng trong tổ chức hiện tại.');
            }
            $after = $this->rooms->findForOrganization($roomTypeId, $organization->id);
            $this->audit->record(
                $actorId, 'ROOM_TYPE_UPDATED', 'ROOM_TYPE', (string) $roomTypeId,
                $requestId, $before, $after, $clientIp,
            );
        });
    }

    private function assertOrganizationActive(OrganizationContext $organization): void
    {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý loại phòng.');
        }
    }

    private function assertPropertyEditable(int $propertyId, int $organizationId): void
    {
        $property = $this->properties->findForOrganization($propertyId, $organizationId, true);
        if ($property === null || !PropertyStatus::from((string) $property['status'])->canPartnerEdit()) {
            throw new DomainException('Cơ sở lưu trú không tồn tại hoặc không cho phép chỉnh sửa.');
        }
    }
}

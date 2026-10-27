<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;
use InvalidArgumentException;

final readonly class UpdateFacilitiesService
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
        private FacilityRepository $facilities,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @param list<array{id: int, free: bool, note: ?string}> $facilities */
    public function updateProperty(
        int $propertyId,
        OrganizationContext $organization,
        int $actorId,
        array $facilities,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertActive($organization);
        $this->validateUniqueIds($facilities);
        $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $facilities, $requestId, $clientIp
        ): void {
            $property = $this->properties->findForOrganization($propertyId, $organization->id, true);
            if ($property === null || !PropertyStatus::from((string) $property['status'])->canPartnerEdit()) {
                throw new DomainException('Cơ sở lưu trú không tồn tại hoặc không cho phép chỉnh sửa.');
            }
            $before = $this->facilities->listForProperty($propertyId, $organization->id);
            if (!$this->facilities->replaceForProperty($propertyId, $organization->id, $facilities)) {
                throw new DomainException('Không thể cập nhật tiện nghi cơ sở lưu trú.');
            }
            $after = $this->facilities->listForProperty($propertyId, $organization->id);
            $this->audit->record(
                $actorId, 'PROPERTY_FACILITIES_UPDATED', 'PROPERTY', (string) $propertyId,
                $requestId, ['facilities' => $before], ['facilities' => $after], $clientIp,
            );
        });
    }

    /** @param list<array{id: int, quantity: ?int, note: ?string}> $facilities */
    public function updateRoomType(
        int $roomTypeId,
        OrganizationContext $organization,
        int $actorId,
        array $facilities,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertActive($organization);
        $this->validateUniqueIds($facilities);
        foreach ($facilities as $facility) {
            if ($facility['quantity'] !== null && $facility['quantity'] < 1) {
                throw new InvalidArgumentException('Số lượng tiện nghi phòng phải lớn hơn 0.');
            }
        }

        $this->transactions->run(function () use (
            $roomTypeId, $organization, $actorId, $facilities, $requestId, $clientIp
        ): void {
            $room = $this->rooms->findForOrganization($roomTypeId, $organization->id);
            if ($room === null) {
                throw new DomainException('Không tìm thấy loại phòng trong tổ chức hiện tại.');
            }
            $property = $this->properties->findForOrganization((int) $room['propertyId'], $organization->id, true);
            if ($property === null || !PropertyStatus::from((string) $property['status'])->canPartnerEdit()) {
                throw new DomainException('Cơ sở lưu trú không cho phép chỉnh sửa tiện nghi.');
            }
            $before = $this->facilities->listForRoomType($roomTypeId, $organization->id);
            if (!$this->facilities->replaceForRoomType($roomTypeId, $organization->id, $facilities)) {
                throw new DomainException('Không thể cập nhật tiện nghi loại phòng.');
            }
            $after = $this->facilities->listForRoomType($roomTypeId, $organization->id);
            $this->audit->record(
                $actorId, 'ROOM_FACILITIES_UPDATED', 'ROOM_TYPE', (string) $roomTypeId,
                $requestId, ['facilities' => $before], ['facilities' => $after], $clientIp,
            );
        });
    }

    private function assertActive(OrganizationContext $organization): void
    {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý tiện nghi.');
        }
    }

    /** @param list<array{id: int}> $facilities */
    private function validateUniqueIds(array $facilities): void
    {
        $seen = [];
        foreach ($facilities as $facility) {
            if ($facility['id'] <= 0 || isset($seen[$facility['id']])) {
                throw new InvalidArgumentException('Danh sách tiện nghi chứa ID không hợp lệ hoặc bị trùng.');
            }
            $seen[$facility['id']] = true;
        }
    }
}

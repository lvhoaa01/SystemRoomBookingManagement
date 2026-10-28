<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class ManageRoomProductService
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomProductRepository $products,
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
        $data = RoomProductData::fromArray($input)->persistenceData();

        return $this->transactions->run(function () use (
            $propertyId, $organization, $actorId, $data, $requestId, $clientIp
        ): int {
            $this->assertPropertyEditable($propertyId, $organization->id);
            $productId = $this->products->create($propertyId, $organization->id, $data);
            if ($productId === null) {
                throw new DomainException('Loại phòng và chính sách hủy phải thuộc cùng cơ sở hiện tại.');
            }
            $after = $this->products->findForOrganization($productId, $organization->id);
            $this->audit->record(
                $actorId,
                'ROOM_PRODUCT_CREATED',
                'ROOM_PRODUCT',
                (string) $productId,
                $requestId,
                null,
                $after,
                $clientIp,
            );
            return $productId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(
        int $productId,
        OrganizationContext $organization,
        int $actorId,
        array $input,
        string $requestId,
        string $clientIp,
    ): void {
        $this->assertOrganizationActive($organization);
        $data = RoomProductData::fromArray($input)->persistenceData();

        $this->transactions->run(function () use (
            $productId, $organization, $actorId, $data, $requestId, $clientIp
        ): void {
            $before = $this->products->findForOrganization($productId, $organization->id);
            if ($before === null) {
                throw new DomainException('Không tìm thấy sản phẩm phòng trong tổ chức hiện tại.');
            }
            $this->assertPropertyEditable((int) $before['propertyId'], $organization->id);
            if (!$this->products->update($productId, $organization->id, $data)) {
                throw new DomainException('Loại phòng và chính sách hủy phải thuộc cùng cơ sở hiện tại.');
            }
            $after = $this->products->findForOrganization($productId, $organization->id);
            $this->audit->record(
                $actorId,
                'ROOM_PRODUCT_UPDATED',
                'ROOM_PRODUCT',
                (string) $productId,
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
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý sản phẩm phòng.');
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

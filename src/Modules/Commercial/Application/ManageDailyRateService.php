<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;
use InvalidArgumentException;

final readonly class ManageDailyRateService
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomProductRepository $products,
        private DailyRateRepository $rates,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $inputRows
     * @return list<array<string, mixed>>
     */
    public function updateCalendar(
        int $productId,
        OrganizationContext $organization,
        int $actorId,
        array $inputRows,
        string $requestId,
        string $clientIp,
    ): array {
        $this->assertOrganizationActive($organization);
        $rows = $this->normalizeRows($inputRows);

        return $this->transactions->run(function () use (
            $productId, $organization, $actorId, $rows, $requestId, $clientIp
        ): array {
            $product = $this->products->findForOrganization($productId, $organization->id);
            if ($product === null) {
                throw new DomainException('Không tìm thấy sản phẩm phòng trong tổ chức hiện tại.');
            }
            $this->assertPropertyEditable((int) $product['propertyId'], $organization->id);

            $before = [];
            $after = [];
            foreach ($rows as $row) {
                $stayDate = (string) $row['stayDate'];
                $before[$stayDate] = $this->rates->findForOrganization(
                    $productId,
                    $stayDate,
                    $organization->id,
                );
                $updated = $this->rates->upsert($productId, $organization->id, $row);
                if ($updated === null) {
                    throw new DomainException('Không thể cập nhật lịch giá trong tổ chức hiện tại.');
                }
                $after[$stayDate] = $updated;
            }

            $this->audit->record(
                $actorId,
                'DAILY_RATES_UPDATED',
                'ROOM_PRODUCT_RATES',
                (string) $productId,
                $requestId,
                $before,
                $after,
                $clientIp,
            );
            return array_values($after);
        });
    }

    /**
     * @param list<array<string, mixed>> $inputRows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $inputRows): array
    {
        if (!array_is_list($inputRows) || $inputRows === [] || count($inputRows) > 366) {
            throw new InvalidArgumentException('Mỗi lần cập nhật phải có từ 1 đến 366 ngày giá.');
        }
        $normalized = [];
        $dates = [];
        foreach ($inputRows as $input) {
            if (!is_array($input)) {
                throw new InvalidArgumentException('Mỗi dòng lịch giá phải là một object dữ liệu.');
            }
            $row = DailyRateData::fromArray($input)->persistenceData();
            $stayDate = (string) $row['stayDate'];
            if (isset($dates[$stayDate])) {
                throw new InvalidArgumentException('Không được gửi trùng ngày trong một lần cập nhật lịch giá.');
            }
            $dates[$stayDate] = true;
            $normalized[] = $row;
        }
        return $normalized;
    }

    private function assertOrganizationActive(OrganizationContext $organization): void
    {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý lịch giá.');
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

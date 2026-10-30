<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use DomainException;
use InvalidArgumentException;

final readonly class InventoryCalendarService
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $roomTypes,
        private InventoryRepository $inventory,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $inputRows
     * @return list<array<string, mixed>>
     */
    public function updateCalendar(
        int $roomTypeId,
        OrganizationContext $organization,
        int $actorId,
        array $inputRows,
        string $requestId,
        string $clientIp,
    ): array {
        if (!$organization->isActive()) {
            throw new DomainException('Tổ chức đang tạm khóa nên không thể quản lý tồn phòng.');
        }
        $rows = $this->normalizeRows($inputRows);

        return $this->transactions->run(function () use (
            $roomTypeId, $organization, $actorId, $rows, $requestId, $clientIp
        ): array {
            $roomType = $this->roomTypes->findForOrganization($roomTypeId, $organization->id);
            if ($roomType === null) {
                throw new DomainException('Không tìm thấy loại phòng trong tổ chức hiện tại.');
            }
            $property = $this->properties->findForOrganization(
                (int) $roomType['propertyId'],
                $organization->id,
                true,
            );
            if ($property === null
                || !PropertyStatus::from((string) $property['status'])->canPartnerEdit()) {
                throw new DomainException('Cơ sở lưu trú không cho phép chỉnh sửa tồn phòng.');
            }

            $before = [];
            $after = [];
            foreach ($rows as $row) {
                $date = $row['stayDate'];
                $before[$date] = $this->inventory->findForOrganization(
                    $roomTypeId,
                    $date,
                    $organization->id,
                );
                $updated = $this->inventory->upsertTotal(
                    $roomTypeId,
                    $organization->id,
                    $date,
                    $row['totalQuantity'],
                );
                if ($updated === null) {
                    throw new DomainException(
                        'Không thể giảm tổng tồn thấp hơn số phòng đang được giữ ở ngày ' . $date . '.',
                    );
                }
                $after[$date] = $updated;
            }

            $this->audit->record(
                $actorId,
                'INVENTORY_CALENDAR_UPDATED',
                'ROOM_TYPE_INVENTORY',
                (string) $roomTypeId,
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
     * @return list<array{stayDate: string, totalQuantity: int}>
     */
    private function normalizeRows(array $inputRows): array
    {
        if (!array_is_list($inputRows) || $inputRows === [] || count($inputRows) > 366) {
            throw new InvalidArgumentException('Mỗi lần cập nhật phải có từ 1 đến 366 ngày tồn.');
        }

        $rows = [];
        foreach ($inputRows as $input) {
            if (!is_array($input)) {
                throw new InvalidArgumentException('Mỗi dòng tồn phải là một object dữ liệu.');
            }
            $row = InventoryDayData::fromArray($input)->persistenceData();
            if (isset($rows[$row['stayDate']])) {
                throw new InvalidArgumentException('Không được gửi trùng ngày trong lịch tồn.');
            }
            $rows[$row['stayDate']] = $row;
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }
}

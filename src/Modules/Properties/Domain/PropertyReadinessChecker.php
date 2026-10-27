<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;

use DateTimeZone;
use InvalidArgumentException;

final class PropertyReadinessChecker
{
    /**
     * @param array<string, mixed> $property
     * @param list<array<string, mixed>> $activeRooms
     * @param array{hasCancellationPolicy: bool, hasProduct: bool, priceDays: int, inventoryDays: int, hasCrossPropertyRelation: bool} $commercial
     */
    public function check(
        bool $organizationActive,
        array $property,
        array $activeRooms,
        array $commercial,
    ): PropertyReadinessReport {
        $missing = [];

        if (!$organizationActive) {
            $missing[] = 'Tổ chức đối tác phải đang hoạt động.';
        }
        if (!$this->hasRequiredProfile($property)) {
            $missing[] = 'Hồ sơ, nguồn dữ liệu, thuế phí và thời gian nhận/trả phòng chưa đầy đủ hoặc không hợp lệ.';
        }
        if (!$this->hasValidImages(
            (string) ($property['imagesJson'] ?? '[]'),
            (string) ($property['code'] ?? ''),
        )) {
            $missing[] = 'Cơ sở lưu trú phải có ít nhất một ảnh hợp lệ và đúng một ảnh bìa.';
        }
        if (!$this->hasReadyRoom($activeRooms)) {
            $missing[] = 'Phải có ít nhất một loại phòng hoạt động với ảnh và cấu hình giường hợp lệ.';
        }
        if (!$commercial['hasCancellationPolicy']) {
            $missing[] = 'Phải có ít nhất một chính sách hủy đang hoạt động.';
        }
        if (!$commercial['hasProduct']) {
            $missing[] = 'Phải có ít nhất một sản phẩm phòng hoạt động cùng cơ sở lưu trú.';
        }
        if ($commercial['priceDays'] < 30) {
            $missing[] = 'Giá mở bán chưa phủ đủ 30 ngày tương lai.';
        }
        if ($commercial['inventoryDays'] < 30) {
            $missing[] = 'Tồn phòng hợp lệ chưa phủ đủ 30 ngày tương lai.';
        }
        if ($commercial['hasCrossPropertyRelation']) {
            $missing[] = 'Phát hiện quan hệ thương mại tham chiếu chéo cơ sở lưu trú.';
        }

        return new PropertyReadinessReport($missing);
    }

    /** @param array<string, mixed> $property */
    private function hasRequiredProfile(array $property): bool
    {
        foreach ([
            'type', 'name', 'sourceName', 'nameSource', 'sourceRecordCode', 'address',
            'addressSource', 'addressVerifiedAt', 'description', 'reconciliationStatus',
            'checkInTime', 'checkOutTime', 'timezone',
        ] as $field) {
            if (trim((string) ($property[$field] ?? '')) === '') {
                return false;
            }
        }

        $type = (string) $property['type'];
        if (!in_array($type, ['HOTEL', 'RESORT', 'APARTMENT', 'VILLA', 'HOSTEL', 'GUEST_HOUSE'], true)) {
            return false;
        }

        $taxRate = filter_var($property['taxRate'] ?? null, FILTER_VALIDATE_FLOAT);
        $serviceFeeRate = filter_var($property['serviceFeeRate'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($taxRate === false || $serviceFeeRate === false || $taxRate < 0 || $taxRate > 100 || $serviceFeeRate < 0 || $serviceFeeRate > 100) {
            return false;
        }

        if (!$this->validTime((string) $property['checkInTime']) || !$this->validTime((string) $property['checkOutTime'])) {
            return false;
        }

        try {
            new DateTimeZone((string) $property['timezone']);
        } catch (\Exception) {
            return false;
        }

        $coordinateValues = [
            $property['latitude'] ?? null,
            $property['longitude'] ?? null,
            $property['coordinatesSource'] ?? null,
            $property['coordinatesVerifiedAt'] ?? null,
        ];
        $presentCoordinates = count(array_filter($coordinateValues, static fn (mixed $value): bool => $value !== null && $value !== ''));
        return $presentCoordinates === 0 || $presentCoordinates === 4;
    }

    /** @param list<array<string, mixed>> $activeRooms */
    private function hasReadyRoom(array $activeRooms): bool
    {
        foreach ($activeRooms as $room) {
            try {
                $beds = BedConfiguration::fromJson((string) ($room['bedsJson'] ?? ''));
                $images = ImageCollection::fromJson((string) ($room['imagesJson'] ?? ''));
                if ($beds->totalBeds() > 0 && !$images->isEmpty()) {
                    return true;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return false;
    }

    private function hasValidImages(string $json, string $propertyCode): bool
    {
        try {
            $images = ImageCollection::fromJson($json);
            foreach ($images->images() as $image) {
                if (str_contains($image['path'], '/accommodation/majestic/') && $propertyCode !== 'CS016') {
                    return false;
                }
            }
            return !$images->isEmpty();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function validTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) === 1;
    }
}

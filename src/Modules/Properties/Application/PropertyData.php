<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Properties\Domain\ImageCollection;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PropertyData
{
    private const TYPES = ['HOTEL', 'RESORT', 'APARTMENT', 'VILLA', 'HOSTEL', 'GUEST_HOUSE'];

    public function __construct(// 19
        public string $type,
        public string $name,
        public string $address,
        public string $description,
        public ImageCollection $images,
        public ?string $latitude, // Vĩ độ
        public ?string $longitude, // Kinh độ
        public ?string $coordinatesSource, // Nguồn
        public ?string $coordinatesVerifiedAt, // Thời điểm xác minh
        public string $reconciliationStatus,
        public ?string $mapUrl,
        public ?string $starRating,// Số sao đánh giá (maybe gg map btw)
        public string $taxRate,// Tỷ lệ thuế
        public bool $taxIncluded, // Thuế đã bao gồm trong giá chưa
        public string $serviceFeeRate,// Tỷ lệ phí dịch vụ
        public bool $serviceFeeIncluded,// Tỷ lệ phí dịch vụ đã bao gồm trong giá chưa
        public string $checkInTime,
        public string $checkOutTime,
        public string $timezone,
    ) {
        if (!in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException('Loại cơ sở lưu trú không hợp lệ.');
        }
        self::assertLength($this->name, 2, 190, 'Tên cơ sở lưu trú');
        self::assertLength($this->address, 5, 500, 'Địa chỉ');
        self::assertLength($this->description, 10, 10000, 'Mô tả');
        self::assertDecimal($this->taxRate, 'Tỷ lệ thuế');
        self::assertDecimal($this->serviceFeeRate, 'Tỷ lệ phí dịch vụ');
        self::assertTime($this->checkInTime, 'Giờ nhận phòng');
        self::assertTime($this->checkOutTime, 'Giờ trả phòng');
        try {
            new DateTimeZone($this->timezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Múi giờ không hợp lệ.', 0, $exception);
        }
        if ($this->starRating !== null && (!is_numeric($this->starRating) || (float) $this->starRating < 0 || (float) $this->starRating > 5)) {
            throw new InvalidArgumentException('Số sao phải từ 0 đến 5.');
        }
        $coordinateCount = count(array_filter(
            [$this->latitude, $this->longitude, $this->coordinatesSource, $this->coordinatesVerifiedAt],
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));
        if (!in_array($coordinateCount, [0, 4], true)) {
            throw new InvalidArgumentException('Tọa độ phải có đủ vĩ độ, kinh độ, nguồn và thời điểm xác minh.');
        }
        if ($coordinateCount === 4 && (
            !is_numeric($this->latitude) || (float) $this->latitude < -90 || (float) $this->latitude > 90
            || !is_numeric($this->longitude) || (float) $this->longitude < -180 || (float) $this->longitude > 180
        )) {
            throw new InvalidArgumentException('Tọa độ nằm ngoài phạm vi hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $nullable = static fn (mixed $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);
        $boolean = static function (mixed $value): bool {
            if (is_bool($value)) {
                return $value;
            }
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
        };

        return new self(
            strtoupper(trim((string) ($input['type'] ?? ''))),
            trim((string) ($input['name'] ?? '')),
            trim((string) ($input['address'] ?? '')),
            trim((string) ($input['description'] ?? '')),
            ImageCollection::fromJson((string) ($input['imagesJson'] ?? '[]')),
            $nullable($input['latitude'] ?? null),
            $nullable($input['longitude'] ?? null),
            $nullable($input['coordinatesSource'] ?? null),
            $nullable($input['coordinatesVerifiedAt'] ?? null),
            trim((string) ($input['reconciliationStatus'] ?? 'PARTNER_DECLARED')),
            $nullable($input['mapUrl'] ?? null),
            $nullable($input['starRating'] ?? null),
            trim((string) ($input['taxRate'] ?? '0')),
            $boolean($input['taxIncluded'] ?? false),
            trim((string) ($input['serviceFeeRate'] ?? '0')),
            $boolean($input['serviceFeeIncluded'] ?? false),
            trim((string) ($input['checkInTime'] ?? '14:00:00')),
            trim((string) ($input['checkOutTime'] ?? '12:00:00')),
            trim((string) ($input['timezone'] ?? 'Asia/Ho_Chi_Minh')),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(string $code, string $slug, ?array $existing = null): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        return [
            'code' => $code,
            'type' => $this->type,
            'name' => $this->name,
            'sourceName' => $this->name,
            'nameSource' => 'PARTNER_PORTAL',
            'sourceRecordCode' => $existing['sourceRecordCode'] ?? $code,
            'slug' => $slug,
            'address' => $this->address,
            'addressSource' => 'PARTNER_PORTAL',
            'addressVerifiedAt' => $now,
            'description' => $this->description,
            'imagesJson' => $this->images->toJson(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'coordinatesSource' => $this->coordinatesSource,
            'coordinatesVerifiedAt' => $this->coordinatesVerifiedAt,
            'reconciliationStatus' => $this->reconciliationStatus,
            'mapUrl' => $this->mapUrl,
            'starRating' => $this->starRating,
            'taxRate' => $this->taxRate,
            'taxIncluded' => $this->taxIncluded ? 1 : 0,
            'serviceFeeRate' => $this->serviceFeeRate,
            'serviceFeeIncluded' => $this->serviceFeeIncluded ? 1 : 0,
            'checkInTime' => $this->checkInTime,
            'checkOutTime' => $this->checkOutTime,
            'timezone' => $this->timezone,
        ];
    }

    /** @param array<string, mixed> $existing */
    public function changesCriticalFields(array $existing): bool
    {
        $comparisons = [
            'type' => $this->type,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'checkInTime' => $this->checkInTime,
            'checkOutTime' => $this->checkOutTime,
        ];
        foreach ($comparisons as $field => $value) {
            if ((string) ($existing[$field] ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }
        return abs((float) ($existing['taxRate'] ?? -1) - (float) $this->taxRate) > 0.00001
            || abs((float) ($existing['serviceFeeRate'] ?? -1) - (float) $this->serviceFeeRate) > 0.00001
            || (bool) ($existing['taxIncluded'] ?? false) !== $this->taxIncluded
            || (bool) ($existing['serviceFeeIncluded'] ?? false) !== $this->serviceFeeIncluded;
    }

    private static function assertLength(string $value, int $min, int $max, string $label): void
    {
        $length = mb_strlen(trim($value));
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(sprintf('%s phải có từ %d đến %d ký tự.', $label, $min, $max));
        }
    }

    private static function assertDecimal(string $value, string $label): void
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new InvalidArgumentException($label . ' phải từ 0 đến 100.');
        }
    }

    private static function assertTime(string $value, string $label): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
    }
}

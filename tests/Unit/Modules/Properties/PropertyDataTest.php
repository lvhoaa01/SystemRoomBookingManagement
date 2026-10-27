<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Application\PropertyData;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class PropertyDataTest extends TestCase
{
    public function test_detects_critical_changes_but_ignores_description_and_images(): void
    {
        $data = PropertyData::fromArray($this->input());
        $existing = [
            'type' => 'HOTEL', 'name' => 'Khách sạn kiểm thử', 'address' => '1 Nguyễn Huệ, Quận 1',
            'latitude' => null, 'longitude' => null, 'taxRate' => '8.00', 'taxIncluded' => 1,
            'serviceFeeRate' => '5.000', 'serviceFeeIncluded' => 0,
            'checkInTime' => '14:00:00', 'checkOutTime' => '12:00:00',
        ];
        self::assertFalse($data->changesCriticalFields($existing));

        $input = $this->input();
        $input['name'] = 'Tên quan trọng đã đổi';
        self::assertTrue(PropertyData::fromArray($input)->changesCriticalFields($existing));
    }

    public function test_rejects_partial_coordinates(): void
    {
        $input = $this->input();
        $input['latitude'] = '10.7';
        $this->expectException(InvalidArgumentException::class);
        PropertyData::fromArray($input);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'type' => 'HOTEL', 'name' => 'Khách sạn kiểm thử', 'address' => '1 Nguyễn Huệ, Quận 1',
            'description' => 'Mô tả hợp lệ cho khách sạn kiểm thử.',
            'imagesJson' => '[{"path":"/uploads/properties/a.webp","alt":"Ảnh","sort":1,"cover":true}]',
            'taxRate' => '8', 'taxIncluded' => '1', 'serviceFeeRate' => '5',
            'checkInTime' => '14:00:00', 'checkOutTime' => '12:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }
}

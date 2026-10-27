<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Application\RoomTypeData;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class RoomTypeDataTest extends TestCase
{
    public function test_normalizes_room_type_for_persistence(): void
    {
        $data = RoomTypeData::fromArray([
            'code' => 'dlx', 'name' => 'Phòng cao cấp', 'description' => '',
            'maxGuests' => '3', 'maxAdults' => '2', 'maxChildren' => '1',
            'bedsJson' => '[{"type":"KING","quantity":1}]',
            'areaM2' => '32.5', 'smokingAllowed' => 'on',
            'imagesJson' => '[{"path":"/uploads/properties/room.webp","alt":"Phòng","sort":1,"cover":true}]',
            'status' => 'active',
        ])->persistenceData();

        self::assertSame('DLX', $data['code']);
        self::assertSame(3, $data['maxGuests']);
        self::assertSame(1, $data['smokingAllowed']);
        self::assertSame('ACTIVE', $data['status']);
    }

    public function test_rejects_adult_capacity_above_total_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoomTypeData::fromArray([
            'code' => 'STD', 'name' => 'Phòng chuẩn', 'maxGuests' => 1, 'maxAdults' => 2,
            'maxChildren' => 0, 'bedsJson' => '[{"type":"DOUBLE","quantity":1}]',
            'imagesJson' => '[]',
        ]);
    }
}

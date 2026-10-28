<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\RoomProductData;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class RoomProductDataTest extends TestCase
{
    public function test_normalizes_room_product_for_persistence(): void
    {
        $data = RoomProductData::fromArray([
            'roomTypeId' => '12', 'cancellationPolicyId' => '8',
            'code' => ' flex_bf ', 'name' => ' Giá gồm bữa sáng ',
            'mealType' => 'breakfast_included', 'paymentTiming' => 'pay_at_property',
            'status' => 'active',
        ])->persistenceData();

        self::assertSame(12, $data['roomTypeId']);
        self::assertSame(8, $data['cancellationPolicyId']);
        self::assertSame('FLEX_BF', $data['code']);
        self::assertSame('Giá gồm bữa sáng', $data['name']);
        self::assertSame('BREAKFAST_INCLUDED', $data['mealType']);
        self::assertSame('PAY_AT_PROPERTY', $data['paymentTiming']);
        self::assertSame('ACTIVE', $data['status']);
    }

    public function test_rejects_unsupported_payment_timing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoomProductData::fromArray([
            'roomTypeId' => 1, 'cancellationPolicyId' => 1,
            'code' => 'FLEX', 'name' => 'Giá linh hoạt',
            'mealType' => 'ROOM_ONLY', 'paymentTiming' => 'PAY_LATER',
        ]);
    }
}

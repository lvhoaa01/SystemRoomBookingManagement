<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\PromotionData;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class PromotionDataTest extends TestCase
{
    public function test_normalizes_promotion_windows_for_persistence(): void
    {
        $data = PromotionData::fromArray([
            'code' => ' early_10 ', 'name' => ' Ưu đãi đặt sớm ',
            'discountRate' => '10.5',
            'bookingFrom' => '2026-10-01T00:00',
            'bookingUntil' => '2027-03-15T23:59',
            'stayFrom' => '2026-10-05', 'stayUntil' => '2027-03-31',
            'status' => 'active',
        ])->persistenceData();

        self::assertSame('EARLY_10', $data['code']);
        self::assertSame('Ưu đãi đặt sớm', $data['name']);
        self::assertSame('10.50', $data['discountRate']);
        self::assertSame('2026-10-01 00:00:00', $data['bookingFrom']);
        self::assertSame('2027-03-15 23:59:00', $data['bookingUntil']);
        self::assertSame('ACTIVE', $data['status']);
    }

    public function test_rejects_reversed_stay_window(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PromotionData::fromArray([
            'code' => 'TEST', 'name' => 'Ưu đãi', 'discountRate' => 10,
            'bookingFrom' => '2026-10-01 00:00:00',
            'bookingUntil' => '2026-10-02 00:00:00',
            'stayFrom' => '2027-04-01', 'stayUntil' => '2027-03-01',
        ]);
    }
}

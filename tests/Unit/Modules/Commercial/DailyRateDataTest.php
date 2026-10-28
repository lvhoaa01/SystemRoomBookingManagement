<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\DailyRateData;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class DailyRateDataTest extends TestCase
{
    public function test_normalizes_daily_rate_without_accepting_version_from_client(): void
    {
        $data = DailyRateData::fromArray([
            'stayDate' => '2027-01-02', 'basePrice' => '607000.49',
            'closed' => 'on', 'minimumStay' => '2', 'maximumStay' => '14',
            'minimumLeadDays' => '3', 'version' => 999,
        ])->persistenceData();

        self::assertSame('2027-01-02', $data['stayDate']);
        self::assertSame('607000.00', $data['basePrice']);
        self::assertSame(1, $data['closed']);
        self::assertSame(2, $data['minimumStay']);
        self::assertSame(14, $data['maximumStay']);
        self::assertSame(3, $data['minimumLeadDays']);
        self::assertArrayNotHasKey('version', $data);
    }

    public function test_rejects_impossible_calendar_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DailyRateData::fromArray([
            'stayDate' => '2027-02-30', 'basePrice' => '500000',
            'minimumStay' => 1, 'minimumLeadDays' => 0,
        ]);
    }

    public function test_rejects_maximum_stay_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DailyRateData::fromArray([
            'stayDate' => '2027-02-28', 'basePrice' => '500000',
            'minimumStay' => 4, 'maximumStay' => 3, 'minimumLeadDays' => 0,
        ]);
    }
}

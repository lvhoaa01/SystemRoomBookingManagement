<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Search;

use App\Modules\Search\Application\SearchCriteriaFactory;
use App\Shared\Clock\FrozenClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SearchCriteriaFactoryTest extends TestCase
{
    private SearchCriteriaFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SearchCriteriaFactory(
            new FrozenClock(new DateTimeImmutable('2026-10-31 02:00:00+00:00')),
            new DateTimeZone('Asia/Ho_Chi_Minh'),
        );
    }

    public function test_normalizes_rooms_filters_sort_and_page(): void
    {
        $criteria = $this->factory->fromArray([
            'destination' => '  Thành phố Hồ Chí Minh ',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-13',
            'roomAdults' => ['2', '1'],
            'roomChildAges' => ['7, 10', ''],
            'propertyTypes' => ['hotel', 'villa', 'hotel'],
            'mealTypes' => 'breakfast_included,room_only',
            'facilityIds' => '3,1,3',
            'starRatings' => ['5', '4'],
            'paymentTiming' => 'pay_at_property',
            'freeCancellation' => 'true',
            'minReviewScore' => '8',
            'minPrice' => '100000',
            'maxPrice' => '5000000',
            'sort' => 'price_asc',
            'page' => '2',
        ]);

        self::assertSame('Thành phố Hồ Chí Minh', $criteria->destination);
        self::assertCount(2, $criteria->parties);
        self::assertSame([7, 10], $criteria->parties[0]->childAges);
        self::assertSame([], $criteria->parties[1]->childAges);
        self::assertSame(['HOTEL', 'VILLA'], $criteria->filters['propertyTypes']);
        self::assertSame(['BREAKFAST_INCLUDED', 'ROOM_ONLY'], $criteria->filters['mealTypes']);
        self::assertSame([1, 3], $criteria->filters['facilityIds']);
        self::assertSame('PAY_AT_PROPERTY', $criteria->filters['paymentTiming']);
        self::assertSame('8.0', $criteria->filters['minReviewScore']);
        self::assertSame('PRICE_ASC', $criteria->sort);
        self::assertSame(2, $criteria->page);
    }

    public function test_accepts_json_room_payload_without_changing_child_ages(): void
    {
        $criteria = $this->factory->fromArray([
            'destination' => 'HCM',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-12',
            'rooms' => '[{"roomOrder":1,"adults":2,"childAges":[0,17]}]',
        ]);

        self::assertSame([0, 17], $criteria->parties[0]->childAges);
    }

    public function test_rejects_past_check_in_and_unknown_sort(): void
    {
        try {
            $this->factory->fromArray([
                'destination' => 'HCM',
                'checkIn' => '2026-10-30',
                'checkOut' => '2026-11-01',
                'adults' => 2,
            ]);
            self::fail('Expected past check-in to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('quá khứ', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sắp xếp');
        $this->factory->fromArray([
            'destination' => 'HCM',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-12',
            'adults' => 2,
            'sort' => 'DISTANCE',
        ]);
    }

    public function test_rejects_more_than_ten_adults_or_children_across_rooms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('10 người lớn');

        $this->factory->fromArray([
            'destination' => 'HCM',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-12',
            'roomAdults' => [6, 5],
            'roomChildAges' => ['', ''],
        ]);
    }
}

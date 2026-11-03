<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Bookings;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Http\BookingViewPresenter;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BookingViewPresenterTest extends TestCase
{
    public function test_confirmation_normalizes_summary_and_guest_list_parties(): void
    {
        $booking = new Booking(
            1,
            'BK-TEST',
            '00000000-0000-4000-8000-000000000001',
            1,
            1,
            1,
            'StayBooking Test',
            'TP.HCM',
            '2027-01-10',
            '2027-01-12',
            'Khách Test',
            'customer@example.test',
            '0900000000',
            null,
            'PAY_AT_PROPERTY',
            Money::vnd('1000000'),
            'CONFIRMED',
            new DateTimeImmutable('2026-11-03T12:00:00+00:00'),
            new DateTimeImmutable('2026-11-03T11:59:00+00:00'),
            [
                ['party' => ['adults' => 2, 'childAges' => [7]]],
                ['party' => [
                    ['name' => 'Người lớn', 'type' => 'ADULT', 'age' => null],
                    ['name' => 'Trẻ em', 'type' => 'CHILD', 'age' => 8],
                ]],
            ],
        );

        $payload = (new BookingViewPresenter())->confirmation($booking);

        self::assertSame(['adults' => 2, 'childAges' => [7]], $payload['items'][0]['party']);
        self::assertSame(['adults' => 1, 'childAges' => [8]], $payload['items'][1]['party']);
    }
}

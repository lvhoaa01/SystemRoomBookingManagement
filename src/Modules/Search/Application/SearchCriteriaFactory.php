<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class SearchCriteriaFactory
{
    private const SORTS = ['RECOMMENDED', 'PRICE_ASC', 'PRICE_DESC', 'REVIEW_SCORE', 'STAR_RATING'];
    private const PROPERTY_TYPES = ['HOTEL', 'RESORT', 'APARTMENT', 'VILLA', 'HOSTEL', 'GUEST_HOUSE'];
    private const MEAL_TYPES = ['ROOM_ONLY', 'BREAKFAST_INCLUDED', 'HALF_BOARD'];
    private const PAYMENT_TIMINGS = ['PAY_AT_PROPERTY', 'PAY_ONLINE'];

    public function __construct(
        private ClockInterface $clock,
        private DateTimeZone $businessTimezone,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function fromArray(array $input): SearchCriteria
    {
        $destination = trim((string) ($input['destination'] ?? ''));
        if (mb_strlen($destination) > 120) {
            throw new InvalidArgumentException('Điểm đến không được dài quá 120 ký tự.');
        }

        $stay = new StayPeriod(
            trim((string) ($input['checkIn'] ?? '')),
            trim((string) ($input['checkOut'] ?? '')),
        );
        $today = new DateTimeImmutable($this->clock->now()->setTimezone($this->businessTimezone)->format('Y-m-d'), $this->businessTimezone);
        $checkIn = new DateTimeImmutable($stay->checkIn, $this->businessTimezone);
        if ($checkIn < $today) {
            throw new InvalidArgumentException('Ngày nhận phòng không được nằm trong quá khứ.');
        }

        $sort = strtoupper(trim((string) ($input['sort'] ?? 'RECOMMENDED')));
        if (!in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException('Tiêu chí sắp xếp không được hỗ trợ.');
        }

        $page = $this->integer($input['page'] ?? 1, 'Số trang', 1, 10_000);

        return new SearchCriteria(
            $destination,
            $stay,
            $this->parties($input),
            $this->filters($input),
            $sort,
            $page,
        );
    }

    /** @param array<string, mixed> $input @return list<RoomParty> */
    private function parties(array $input): array
    {
        if (array_key_exists('rooms', $input)) {
            $rooms = $input['rooms'];
            if (is_string($rooms)) {
                try {
                    $rooms = json_decode($rooms, true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new InvalidArgumentException('Danh sách phòng phải là JSON hợp lệ.');
                }
            }
            if (!is_array($rooms) || !array_is_list($rooms)) {
                throw new InvalidArgumentException('Danh sách phòng không hợp lệ.');
            }

            $parties = [];
            foreach ($rooms as $index => $room) {
                if (!is_array($room)) {
                    throw new InvalidArgumentException('Mỗi phòng phải là một object.');
                }
                $parties[] = new RoomParty(
                    $this->integer($room['roomOrder'] ?? ($index + 1), 'Thứ tự phòng', 1, 5),
                    $this->integer($room['adults'] ?? null, 'Số người lớn', 1, 10),
                    $this->childAges($room['childAges'] ?? []),
                );
            }
            return $parties;
        }

        $adultValues = $this->listValue($input['roomAdults'] ?? ($input['adults'] ?? null));
        if ($adultValues === []) {
            throw new InvalidArgumentException('Phải khai báo số người lớn cho từng phòng.');
        }
        $childValues = $this->listValue($input['roomChildAges'] ?? ($input['childAges'] ?? []), false);
        $parties = [];
        foreach ($adultValues as $index => $adults) {
            $parties[] = new RoomParty(
                $index + 1,
                $this->integer($adults, 'Số người lớn', 1, 10),
                $this->childAges($childValues[$index] ?? []),
            );
        }
        return $parties;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function filters(array $input): array
    {
        $filters = [];
        $minPrice = $this->price($input['minPrice'] ?? null, 'Giá tối thiểu');
        $maxPrice = $this->price($input['maxPrice'] ?? null, 'Giá tối đa');
        if ($minPrice !== null) {
            $filters['minPrice'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $filters['maxPrice'] = $maxPrice;
        }
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new InvalidArgumentException('Giá tối thiểu không được lớn hơn giá tối đa.');
        }

        $this->enumListFilter($filters, 'propertyTypes', $input['propertyTypes'] ?? [], self::PROPERTY_TYPES);
        $this->enumListFilter($filters, 'mealTypes', $input['mealTypes'] ?? [], self::MEAL_TYPES);
        $this->integerListFilter($filters, 'facilityIds', $input['facilityIds'] ?? [], 1, 10_000);
        $this->integerListFilter($filters, 'starRatings', $input['starRatings'] ?? [], 1, 5);

        $paymentTiming = strtoupper(trim((string) ($input['paymentTiming'] ?? '')));
        if ($paymentTiming !== '') {
            if (!in_array($paymentTiming, self::PAYMENT_TIMINGS, true)) {
                throw new InvalidArgumentException('Thời điểm thanh toán không hợp lệ.');
            }
            $filters['paymentTiming'] = $paymentTiming;
        }

        if ($this->boolean($input['freeCancellation'] ?? false)) {
            $filters['freeCancellation'] = true;
        }

        $reviewScore = trim((string) ($input['minReviewScore'] ?? ''));
        if ($reviewScore !== '') {
            if (!is_numeric($reviewScore) || (float) $reviewScore < 1 || (float) $reviewScore > 10) {
                throw new InvalidArgumentException('Điểm đánh giá phải nằm trong khoảng 1–10.');
            }
            $filters['minReviewScore'] = number_format((float) $reviewScore, 1, '.', '');
        }

        return $filters;
    }

    /** @param array<string, mixed> $filters @param list<string> $allowed */
    private function enumListFilter(array &$filters, string $key, mixed $value, array $allowed): void
    {
        $values = array_map(static fn (mixed $item): string => strtoupper(trim((string) $item)), $this->listValue($value, false));
        $values = array_values(array_unique(array_filter($values, static fn (string $item): bool => $item !== '')));
        foreach ($values as $item) {
            if (!in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("Bộ lọc {$key} chứa giá trị không được hỗ trợ.");
            }
        }
        sort($values, SORT_STRING);
        if ($values !== []) {
            $filters[$key] = $values;
        }
    }

    /** @param array<string, mixed> $filters */
    private function integerListFilter(array &$filters, string $key, mixed $value, int $minimum, int $maximum): void
    {
        $values = [];
        foreach ($this->listValue($value, false) as $item) {
            if (is_string($item) && str_contains($item, ',')) {
                foreach (explode(',', $item) as $part) {
                    $values[] = $this->integer(trim($part), $key, $minimum, $maximum);
                }
                continue;
            }
            if ($item !== '') {
                $values[] = $this->integer($item, $key, $minimum, $maximum);
            }
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);
        if ($values !== []) {
            $filters[$key] = $values;
        }
    }

    /** @return list<int> */
    private function childAges(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : explode(',', $value);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Tuổi trẻ em phải là danh sách hoặc chuỗi phân tách bằng dấu phẩy.');
        }
        return array_map(fn (mixed $age): int => $this->integer(trim((string) $age), 'Tuổi trẻ em', 0, 17), $value);
    }

    /** @return list<mixed> */
    private function listValue(mixed $value, bool $required = true): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_is_list($value) ? $value : throw new InvalidArgumentException('Giá trị danh sách không hợp lệ.');
        }
        if (!$required && is_string($value) && str_contains($value, ',')) {
            return explode(',', $value);
        }
        return [$value];
    }

    private function price(mixed $value, string $label): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $money = Money::vnd(trim((string) $value));
        if ($money->amount() < 0) {
            throw new InvalidArgumentException("{$label} không được âm.");
        }
        return $money->amount();
    }

    private function integer(mixed $value, string $label, int $minimum, int $maximum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException("{$label} phải nằm trong khoảng {$minimum}–{$maximum}.");
        }
        return (int) $integer;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolean === null) {
            throw new InvalidArgumentException('Giá trị bộ lọc boolean không hợp lệ.');
        }
        return $boolean;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

final readonly class StayPeriod implements JsonSerializable
{
    private DateTimeImmutable $checkInDate;
    private DateTimeImmutable $checkOutDate;

    public function __construct(
        public string $checkIn,
        public string $checkOut,
    ) {
        $this->checkInDate = self::parseDate($this->checkIn, 'check-in');
        $this->checkOutDate = self::parseDate($this->checkOut, 'check-out');

        if ($this->checkOutDate <= $this->checkInDate) {
            throw new InvalidArgumentException('Ngày check-out phải sau ngày check-in.');
        }
        if ($this->nightCount() > 30) {
            throw new InvalidArgumentException('Một kỳ lưu trú không được vượt quá 30 đêm.');
        }
    }

    public function nightCount(): int
    {
        return (int) $this->checkInDate->diff($this->checkOutDate)->days;
    }

    /** @return list<string> */
    public function nights(): array
    {
        $nights = [];
        for ($date = $this->checkInDate; $date < $this->checkOutDate; $date = $date->modify('+1 day')) {
            $nights[] = $date->format('Y-m-d');
        }

        return $nights;
    }

    public function containsNight(string $date): bool
    {
        $night = self::parseDate($date, 'ngày lưu trú');
        return $night >= $this->checkInDate && $night < $this->checkOutDate;
    }

    /** @return array{checkIn: string, checkOut: string} */
    public function jsonSerialize(): array
    {
        return ['checkIn' => $this->checkIn, 'checkOut' => $this->checkOut];
    }

    private static function parseDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Ngày {$field} phải có định dạng Y-m-d hợp lệ.");
        }

        return $date;
    }
}

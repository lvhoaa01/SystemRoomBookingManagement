<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

final readonly class InventoryDemand implements JsonSerializable
{
    public function __construct(
        public int $roomTypeId,
        public string $date,
        public int $quantity,
    ) {
        if ($this->roomTypeId < 1) {
            throw new InvalidArgumentException('Room type ID phải là số nguyên dương.');
        }
        if ($this->quantity < 1) {
            throw new InvalidArgumentException('Nhu cầu tồn phòng phải từ 1 trở lên.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $this->date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $this->date) {
            throw new InvalidArgumentException('Ngày nhu cầu tồn phòng phải có định dạng Y-m-d hợp lệ.');
        }
    }

    /** @return array{roomTypeId: int, date: string, quantity: int} */
    public function jsonSerialize(): array
    {
        return [
            'roomTypeId' => $this->roomTypeId,
            'date' => $this->date,
            'quantity' => $this->quantity,
        ];
    }
}

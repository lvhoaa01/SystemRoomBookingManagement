<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DailyRateData
{
    public function __construct(
        public string $stayDate,
        public Money $basePrice,
        public bool $closed,
        public int $minimumStay,
        public ?int $maximumStay,
        public int $minimumLeadDays,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->stayDate);
        if ($date === false || $date->format('Y-m-d') !== $this->stayDate) {
            throw new InvalidArgumentException('Ngày lưu trú phải có định dạng YYYY-MM-DD và tồn tại.');
        }
        if ($this->basePrice->amount() <= 0) {
            throw new InvalidArgumentException('Giá cơ bản phải lớn hơn 0 VND.');
        }
        if ($this->minimumStay < 1 || $this->minimumStay > 30) {
            throw new InvalidArgumentException('Số đêm tối thiểu phải nằm trong khoảng 1-30.');
        }
        if ($this->maximumStay !== null
            && ($this->maximumStay < $this->minimumStay || $this->maximumStay > 30)) {
            throw new InvalidArgumentException('Số đêm tối đa phải từ số đêm tối thiểu đến 30.');
        }
        if ($this->minimumLeadDays < 0 || $this->minimumLeadDays > 730) {
            throw new InvalidArgumentException('Số ngày đặt trước phải nằm trong khoảng 0-730.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            trim((string) ($input['stayDate'] ?? '')),
            Money::vnd(trim((string) ($input['basePrice'] ?? '0'))),
            in_array(strtolower((string) ($input['closed'] ?? '')), ['1', 'true', 'on', 'yes'], true),
            self::integer($input['minimumStay'] ?? 1, -1),
            self::nullableInteger($input['maximumStay'] ?? null),
            self::integer($input['minimumLeadDays'] ?? 0, -1),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        return [
            'stayDate' => $this->stayDate,
            'basePrice' => $this->basePrice->decimal(),
            'closed' => $this->closed ? 1 : 0,
            'minimumStay' => $this->minimumStay,
            'maximumStay' => $this->maximumStay,
            'minimumLeadDays' => $this->minimumLeadDays,
        ];
    }

    private static function integer(mixed $value, int $invalid): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? $invalid : (int) $integer;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return self::integer($value, -1);
    }
}

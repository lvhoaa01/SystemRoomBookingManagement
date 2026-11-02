<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportCriteria
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $organizationId = null,
        public ?int $propertyId = null,
    ) {
        $start = self::date($from, 'Ngày bắt đầu');
        $end = self::date($to, 'Ngày kết thúc');
        if ($start > $end) {
            throw new InvalidArgumentException('Ngày bắt đầu báo cáo phải trước hoặc bằng ngày kết thúc.');
        }
        if ((int) $start->diff($end)->days > 366) {
            throw new InvalidArgumentException('Khoảng báo cáo tối đa 366 ngày.');
        }
        if (($organizationId !== null && $organizationId < 1) || ($propertyId !== null && $propertyId < 1)) {
            throw new InvalidArgumentException('Phạm vi báo cáo không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, DateTimeImmutable $today): self
    {
        $to = self::string($input['to'] ?? null) ?? $today->format('Y-m-d');
        $from = self::string($input['from'] ?? null) ?? $today->modify('-29 days')->format('Y-m-d');
        return new self(
            $from,
            $to,
            self::positiveInt($input['organizationId'] ?? null),
            self::positiveInt($input['propertyId'] ?? null),
        );
    }

    private static function date(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' phải có định dạng YYYY-MM-DD.');
        }
        return $date;
    }

    private static function string(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($result) || $result < 1) {
            throw new InvalidArgumentException('ID bộ lọc báo cáo không hợp lệ.');
        }
        return $result;
    }
}

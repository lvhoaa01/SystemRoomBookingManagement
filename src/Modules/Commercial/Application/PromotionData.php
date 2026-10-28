<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PromotionData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $discountRate,
        public string $bookingFrom,
        public string $bookingUntil,
        public string $stayFrom,
        public string $stayUntil,
        public string $status,
    ) {
        if (preg_match('/^[A-Z0-9_-]{2,24}$/', $this->code) !== 1) {
            throw new InvalidArgumentException('Mã khuyến mãi phải có 2-24 ký tự A-Z, 0-9, gạch dưới hoặc gạch ngang.');
        }
        if (mb_strlen($this->name) < 2 || mb_strlen($this->name) > 150) {
            throw new InvalidArgumentException('Tên khuyến mãi phải có từ 2 đến 150 ký tự.');
        }
        if ($this->bookingFrom > $this->bookingUntil) {
            throw new InvalidArgumentException('Cửa sổ đặt phải bắt đầu trước hoặc đúng thời điểm kết thúc.');
        }
        if ($this->stayFrom > $this->stayUntil) {
            throw new InvalidArgumentException('Cửa sổ lưu trú phải bắt đầu trước hoặc đúng ngày kết thúc.');
        }
        if (!in_array($this->status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new InvalidArgumentException('Trạng thái khuyến mãi không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            strtoupper(trim((string) ($input['code'] ?? ''))),
            trim((string) ($input['name'] ?? '')),
            self::discountRate($input['discountRate'] ?? null),
            self::dateTime($input['bookingFrom'] ?? null, 'Thời điểm bắt đầu đặt'),
            self::dateTime($input['bookingUntil'] ?? null, 'Thời điểm kết thúc đặt'),
            self::date($input['stayFrom'] ?? null, 'Ngày bắt đầu lưu trú'),
            self::date($input['stayUntil'] ?? null, 'Ngày kết thúc lưu trú'),
            strtoupper(trim((string) ($input['status'] ?? 'ACTIVE'))),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'discountRate' => $this->discountRate,
            'bookingFrom' => $this->bookingFrom,
            'bookingUntil' => $this->bookingUntil,
            'stayFrom' => $this->stayFrom,
            'stayUntil' => $this->stayUntil,
            'status' => $this->status,
        ];
    }

    private static function discountRate(mixed $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException('Phần trăm giảm phải lớn hơn 0 và không vượt quá 100.');
        }
        $hundredths = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($hundredths < 1 || $hundredths > 10_000) {
            throw new InvalidArgumentException('Phần trăm giảm phải lớn hơn 0 và không vượt quá 100.');
        }
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private static function dateTime(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);
            if ($date !== false && $date->format(str_contains($format, '\T') ? 'Y-m-d\TH:i' : 'Y-m-d H:i:s') === $text) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException($label . ' không hợp lệ.');
    }

    private static function date(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
        return $text;
    }
}

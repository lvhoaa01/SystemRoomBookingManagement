<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use InvalidArgumentException;

final readonly class CancellationPolicyData
{
    private const POLICY_TYPES = ['FLEXIBLE', 'NON_REFUNDABLE'];
    private const PENALTY_TYPES = ['NONE', 'PERCENT', 'FIRST_NIGHT', 'FULL_STAY'];
    private const STATUSES = ['ACTIVE', 'INACTIVE'];

    public function __construct(
        public string $code,
        public string $name,
        public string $policyType,
        public ?int $freeCancellationHours,
        public string $penaltyType,
        public string $penaltyValue,
        public string $noShowPenaltyRate,
        public string $status,
    ) {
        if (preg_match('/^[A-Z0-9_-]{2,24}$/', $this->code) !== 1) {
            throw new InvalidArgumentException('Mã chính sách phải có 2-24 ký tự A-Z, 0-9, gạch dưới hoặc gạch ngang.');
        }
        if (mb_strlen($this->name) < 2 || mb_strlen($this->name) > 150) {
            throw new InvalidArgumentException('Tên chính sách phải có từ 2 đến 150 ký tự.');
        }
        if (!in_array($this->policyType, self::POLICY_TYPES, true)) {
            throw new InvalidArgumentException('Loại chính sách hủy không hợp lệ.');
        }
        if ($this->freeCancellationHours !== null
            && ($this->freeCancellationHours < 0 || $this->freeCancellationHours > 65_535)) {
            throw new InvalidArgumentException('Số giờ hủy miễn phí phải nằm trong khoảng 0-65535.');
        }
        if ($this->policyType === 'NON_REFUNDABLE' && $this->freeCancellationHours !== null) {
            throw new InvalidArgumentException('Chính sách không hoàn tiền không có thời hạn hủy miễn phí.');
        }
        if (!in_array($this->penaltyType, self::PENALTY_TYPES, true)) {
            throw new InvalidArgumentException('Loại phạt hủy không hợp lệ.');
        }
        if (!in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Trạng thái chính sách hủy không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $policyType = strtoupper(trim((string) ($input['policyType'] ?? '')));
        $penaltyType = strtoupper(trim((string) ($input['penaltyType'] ?? '')));
        $freeHours = self::nullableInteger($input['freeCancellationHours'] ?? null);
        if ($policyType === 'NON_REFUNDABLE') {
            $freeHours = null;
        }

        return new self(
            strtoupper(trim((string) ($input['code'] ?? ''))),
            trim((string) ($input['name'] ?? '')),
            $policyType,
            $freeHours,
            $penaltyType,
            self::normalizedPenaltyValue($penaltyType, $input['penaltyValue'] ?? null),
            self::percentage($input['noShowPenaltyRate'] ?? '100', 'Tỷ lệ phạt no-show'),
            strtoupper(trim((string) ($input['status'] ?? 'ACTIVE'))),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'policyType' => $this->policyType,
            'freeCancellationHours' => $this->freeCancellationHours,
            'penaltyType' => $this->penaltyType,
            'penaltyValue' => $this->penaltyValue,
            'noShowPenaltyRate' => $this->noShowPenaltyRate,
            'status' => $this->status,
        ];
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidArgumentException('Số giờ hủy miễn phí phải là số nguyên.');
        }
        return $integer;
    }

    private static function normalizedPenaltyValue(string $penaltyType, mixed $value): string
    {
        return match ($penaltyType) {
            'NONE' => '0.00',
            'FIRST_NIGHT' => '1.00',
            'FULL_STAY' => '100.00',
            'PERCENT' => self::percentage($value, 'Giá trị phạt'),
            default => throw new InvalidArgumentException('Loại phạt hủy không hợp lệ.'),
        };
    }

    private static function percentage(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException($label . ' phải là số từ 0 đến 100, tối đa 2 chữ số thập phân.');
        }
        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $hundredths = ($whole * 100) + (int) $fraction;
        if ($hundredths > 10_000) {
            throw new InvalidArgumentException($label . ' phải nằm trong khoảng 0-100.');
        }
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use InvalidArgumentException;

final readonly class RoomProductData
{
    private const MEAL_TYPES = ['ROOM_ONLY', 'BREAKFAST_INCLUDED', 'HALF_BOARD'];
    private const PAYMENT_TIMINGS = ['PAY_AT_PROPERTY', 'PAY_ONLINE'];
    private const STATUSES = ['ACTIVE', 'INACTIVE'];

    public function __construct(
        public int $roomTypeId,
        public int $cancellationPolicyId,
        public string $code,
        public string $name,
        public string $mealType,
        public string $paymentTiming,
        public string $status,
    ) {
        if ($this->roomTypeId <= 0 || $this->cancellationPolicyId <= 0) {
            throw new InvalidArgumentException('Loại phòng và chính sách hủy phải có ID hợp lệ.');
        }
        if (preg_match('/^[A-Z0-9_-]{2,24}$/', $this->code) !== 1) {
            throw new InvalidArgumentException('Mã sản phẩm phải có 2-24 ký tự A-Z, 0-9, gạch dưới hoặc gạch ngang.');
        }
        if (mb_strlen($this->name) < 2 || mb_strlen($this->name) > 150) {
            throw new InvalidArgumentException('Tên sản phẩm phải có từ 2 đến 150 ký tự.');
        }
        if (!in_array($this->mealType, self::MEAL_TYPES, true)) {
            throw new InvalidArgumentException('Loại bữa ăn không hợp lệ.');
        }
        if (!in_array($this->paymentTiming, self::PAYMENT_TIMINGS, true)) {
            throw new InvalidArgumentException('Thời điểm thanh toán không hợp lệ.');
        }
        if (!in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Trạng thái sản phẩm phòng không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            self::positiveInteger($input['roomTypeId'] ?? null),
            self::positiveInteger($input['cancellationPolicyId'] ?? null),
            strtoupper(trim((string) ($input['code'] ?? ''))),
            trim((string) ($input['name'] ?? '')),
            strtoupper(trim((string) ($input['mealType'] ?? ''))),
            strtoupper(trim((string) ($input['paymentTiming'] ?? ''))),
            strtoupper(trim((string) ($input['status'] ?? 'ACTIVE'))),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        return [
            'roomTypeId' => $this->roomTypeId,
            'cancellationPolicyId' => $this->cancellationPolicyId,
            'code' => $this->code,
            'name' => $this->name,
            'mealType' => $this->mealType,
            'paymentTiming' => $this->paymentTiming,
            'status' => $this->status,
        ];
    }

    private static function positiveInteger(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? 0 : (int) $integer;
    }
}

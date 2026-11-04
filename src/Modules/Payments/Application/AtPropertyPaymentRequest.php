<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use InvalidArgumentException;

final readonly class AtPropertyPaymentRequest
{
    private const METHODS = ['CASH', 'BANK_TRANSFER', 'CARD_AT_PROPERTY'];

    public function __construct(
        public string $idempotencyKey,
        public string $method,
        public ?string $reference,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Mã ghi nhận thanh toán không hợp lệ.');
        }
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Phương thức thanh toán tại cơ sở không hợp lệ.');
        }
        if ($reference !== null && strlen($reference) > 100) {
            throw new InvalidArgumentException('Mã tham chiếu không được vượt quá 100 ký tự.');
        }
        if ($method === 'CASH' && $reference !== null) {
            throw new InvalidArgumentException('Thanh toán tiền mặt không dùng mã tham chiếu.');
        }
        if ($method !== 'CASH' && $reference === null) {
            throw new InvalidArgumentException('Chuyển khoản hoặc thẻ cần mã tham chiếu.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $reference = trim((string) ($input['reference'] ?? ''));
        return new self(
            strtolower(trim((string) ($input['paymentKey'] ?? ''))),
            strtoupper(trim((string) ($input['method'] ?? ''))),
            $reference === '' ? null : $reference,
        );
    }
}

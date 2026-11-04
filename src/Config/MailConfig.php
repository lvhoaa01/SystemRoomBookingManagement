<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class MailConfig
{
    public function __construct(
        public string $transport,
        public string $fromAddress,
        public string $fromName,
    ) {
        if (!in_array($transport, ['file', 'native'], true)) {
            throw new RuntimeException('MAIL_TRANSPORT chỉ hỗ trợ file hoặc native.');
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('MAIL_FROM_ADDRESS không hợp lệ.');
        }
        if (trim($fromName) === '') {
            throw new RuntimeException('MAIL_FROM_NAME không được để trống.');
        }
    }

    public static function fromEnv(): self
    {
        return new self(
            strtolower(Env::get('MAIL_TRANSPORT', 'file') ?? 'file'),
            Env::get('MAIL_FROM_ADDRESS', 'no-reply@staybooking.local') ?? 'no-reply@staybooking.local',
            Env::get('MAIL_FROM_NAME', 'StayBooking') ?? 'StayBooking',
        );
    }
}

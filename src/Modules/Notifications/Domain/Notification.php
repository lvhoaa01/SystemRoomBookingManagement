<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

use DateTimeImmutable;

final readonly class Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public int $invoiceId,
        public string $recipient,
        public string $subject,
        public array $payload,
        public string $status,
        public int $attempts,
        public string $idempotencyKey,
        public DateTimeImmutable $sendAfter,
    ) {
    }
}

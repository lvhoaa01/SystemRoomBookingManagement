<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

final readonly class MailMessage
{
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public string $idempotencyKey,
    ) {
    }
}

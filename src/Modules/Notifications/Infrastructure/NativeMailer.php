<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Config\MailConfig;
use App\Modules\Notifications\Application\Mailer;
use App\Modules\Notifications\Domain\MailMessage;
use RuntimeException;

final readonly class NativeMailer implements Mailer
{
    public function __construct(private MailConfig $config)
    {
    }

    public function send(MailMessage $message): void
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->config->fromName . ' <' . $this->config->fromAddress . '>',
        ];
        if (!mail($message->recipient, $message->subject, $message->htmlBody, implode("\r\n", $headers))) {
            throw new RuntimeException('PHP mail() không chấp nhận email.');
        }
    }
}

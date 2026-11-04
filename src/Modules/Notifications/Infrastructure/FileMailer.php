<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Config\MailConfig;
use App\Modules\Notifications\Application\Mailer;
use App\Modules\Notifications\Domain\MailMessage;
use RuntimeException;

final readonly class FileMailer implements Mailer
{
    public function __construct(private MailConfig $config, private string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Không thể tạo thư mục mail cục bộ.');
        }
        $filename = hash('sha256', $message->idempotencyKey) . '.eml';
        $contents = "From: {$this->config->fromName} <{$this->config->fromAddress}>\r\n"
            . "To: {$message->recipient}\r\n"
            . "Subject: {$message->subject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $message->htmlBody;
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Không thể ghi email cục bộ.');
        }
    }
}

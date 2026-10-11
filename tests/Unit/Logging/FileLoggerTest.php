<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Shared\Logging\FileLogger;
use Tests\Support\TestCase;

final class FileLoggerTest extends TestCase
{
    public function test_masks_credentials_email_and_phone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ota-log-');
        self::assertNotFalse($path);
        $logger = new FileLogger($path);

        try {
            $logger->log('info', 'TEST', [
                'password' => 'secret-password',
                'email' => 'customer@example.com',
                'note' => 'Liên hệ 0901234567',
                'token' => 'raw-token',
            ]);
            $content = (string) file_get_contents($path);

            self::assertStringNotContainsString('secret-password', $content);
            self::assertStringNotContainsString('customer@example.com', $content);
            self::assertStringNotContainsString('0901234567', $content);
            self::assertStringNotContainsString('raw-token', $content);
            self::assertStringContainsString('[REDACTED]', $content);
        } finally {
            unlink($path);
        }
    }
}

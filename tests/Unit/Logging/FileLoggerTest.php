<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Shared\Clock\FrozenClock;
use App\Shared\Logging\FileLogger;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestCase;

final class FileLoggerTest extends TestCase
{
    public function test_masks_credentials_email_and_phone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ota-log-');
        self::assertNotFalse($path);
        $logger = new FileLogger(
            $path,
            new FrozenClock(new DateTimeImmutable('2026-10-20 07:30:00.123456', new DateTimeZone('UTC'))),
        );

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
            self::assertStringContainsString('2026-10-20T07:30:00.123456Z', $content);
        } finally {
            unlink($path);
        }
    }
}

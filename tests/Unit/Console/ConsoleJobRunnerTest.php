<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Shared\Clock\FrozenClock;
use App\Shared\Console\ConsoleJobRunner;
use App\Shared\Logging\Logger;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestCase;

final class ConsoleJobRunnerTest extends TestCase
{
    public function test_logs_start_and_finish_with_the_same_run_id(): void
    {
        $logger = new RecordingLogger();
        $runner = new ConsoleJobRunner($logger, new FrozenClock(new DateTimeImmutable('2026-11-03T01:02:03Z')));

        $run = $runner->run('inventory:reconcile', static fn (string $runId): string => $runId);

        self::assertSame($run['runId'], $run['result']);
        self::assertStringStartsWith('inventory-reconcile-20261103010203-', $run['runId']);
        self::assertSame(['CONSOLE_JOB_STARTED', 'CONSOLE_JOB_FINISHED'], array_column($logger->entries, 'message'));
        self::assertSame($run['runId'], $logger->entries[0]['context']['runId']);
        self::assertSame($run['runId'], $logger->entries[1]['context']['runId']);
    }

    public function test_logs_failure_and_rethrows_the_original_exception(): void
    {
        $logger = new RecordingLogger();
        $runner = new ConsoleJobRunner($logger, new FrozenClock(new DateTimeImmutable('2026-11-03T01:02:03Z')));

        try {
            $runner->run('payment:expire', static function (string $_): never {
                throw new RuntimeException('job failed');
            });
            self::fail('Runner phải ném lại lỗi của job.');
        } catch (RuntimeException $exception) {
            self::assertSame('job failed', $exception->getMessage());
        }

        self::assertSame(['CONSOLE_JOB_STARTED', 'CONSOLE_JOB_FAILED'], array_column($logger->entries, 'message'));
        self::assertSame(RuntimeException::class, $logger->entries[1]['context']['exception']);
    }
}

final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = compact('level', 'message', 'context');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Clock\ClockInterface;
use App\Shared\Logging\Logger;
use Throwable;

final readonly class ConsoleJobRunner
{
    public function __construct(
        private Logger $logger,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{runId: string, result: mixed} */
    public function run(string $jobName, callable $job): array
    {
        $runId = sprintf(
            '%s-%s-%s',
            str_replace(':', '-', $jobName),
            $this->clock->now()->format('YmdHis'),
            bin2hex(random_bytes(6)),
        );
        $startedAt = microtime(true);
        $this->logger->log('info', 'CONSOLE_JOB_STARTED', [
            'job' => $jobName,
            'runId' => $runId,
        ]);

        try {
            $result = $job($runId);
            $this->logger->log('info', 'CONSOLE_JOB_FINISHED', [
                'job' => $jobName,
                'runId' => $runId,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1_000),
                'result' => $result,
            ]);

            return ['runId' => $runId, 'result' => $result];
        } catch (Throwable $exception) {
            $this->logger->log('error', 'CONSOLE_JOB_FAILED', [
                'job' => $jobName,
                'runId' => $runId,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1_000),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}

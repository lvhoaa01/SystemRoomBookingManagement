<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Modules\Bookings\Application\CompletionService;
use App\Modules\Bookings\Application\PaymentExpiryService;
use App\Modules\Bookings\Application\PreviewExpiryService;
use App\Modules\Inventory\Application\InventoryReconciliationService;
use App\Shared\Console\ConsoleJobRunner;

return static function (Container $container): array {
    $batchSize = static function (array $arguments, int $index = 0): int {
        $limit = isset($arguments[$index]) ? filter_var($arguments[$index], FILTER_VALIDATE_INT) : 100;
        if (!is_int($limit) || $limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Batch size phải là số nguyên từ 1 đến 1000.');
        }
        return $limit;
    };
    $runner = $container->get(ConsoleJobRunner::class);

    return [
        'payment:expire' => static function (array $arguments) use ($container, $runner, $batchSize): int {
            $limit = $batchSize($arguments);
            $run = $runner->run('payment:expire', static fn (string $runId): int =>
                $container->get(PaymentExpiryService::class)->expireDue($limit, $runId));
            fwrite(STDOUT, "Run ID: {$run['runId']}" . PHP_EOL);
            fwrite(STDOUT, "Expired payments: {$run['result']}" . PHP_EOL);
            return 0;
        },
        'booking:complete' => static function (array $arguments) use ($container, $runner, $batchSize): int {
            $limit = $batchSize($arguments);
            $run = $runner->run('booking:complete', static fn (string $_): int =>
                $container->get(CompletionService::class)->completeDue($limit));
            fwrite(STDOUT, "Run ID: {$run['runId']}" . PHP_EOL);
            fwrite(STDOUT, "Completed bookings: {$run['result']}" . PHP_EOL);
            return 0;
        },
        'preview:invalidate-expired' => static function (array $arguments) use ($container, $runner, $batchSize): int {
            $limit = $batchSize($arguments);
            $run = $runner->run('preview:invalidate-expired', static fn (string $runId): int =>
                $container->get(PreviewExpiryService::class)->invalidateDue($limit, $runId));
            fwrite(STDOUT, "Run ID: {$run['runId']}" . PHP_EOL);
            fwrite(STDOUT, "Invalidated previews: {$run['result']}" . PHP_EOL);
            return 0;
        },
        'inventory:reconcile' => static function (array $arguments) use ($container, $runner, $batchSize): int {
            $fromDate = $arguments[0] ?? gmdate('Y-m-d');
            $toDate = $arguments[1] ?? $fromDate;
            $roomTypeId = isset($arguments[2]) ? filter_var($arguments[2], FILTER_VALIDATE_INT) : null;
            if (isset($arguments[2]) && (!is_int($roomTypeId) || $roomTypeId < 1)) {
                throw new InvalidArgumentException('ID loại phòng phải là số nguyên dương.');
            }
            $limit = $batchSize($arguments, 3);
            $run = $runner->run('inventory:reconcile', static function (string $_) use (
                $container, $fromDate, $toDate, $roomTypeId, $limit,
            ): array {
                $issues = $container->get(InventoryReconciliationService::class)->reconcile(
                    $fromDate, $toDate, $roomTypeId,
                );
                return [
                    'total' => count($issues),
                    'displayed' => array_slice($issues, 0, $limit),
                    'truncated' => count($issues) > $limit,
                ];
            });
            fwrite(STDOUT, "Run ID: {$run['runId']}" . PHP_EOL);
            fwrite(STDOUT, 'Reconciliation issues: ' . $run['result']['total'] . PHP_EOL);
            foreach ($run['result']['displayed'] as $issue) {
                fwrite(STDOUT, json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            }
            if ($run['result']['truncated']) {
                fwrite(STDOUT, "Output truncated at {$limit} issues." . PHP_EOL);
            }
            return 0;
        },
    ];
};

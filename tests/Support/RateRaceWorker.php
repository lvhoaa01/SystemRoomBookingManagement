<?php

declare(strict_types=1);

use App\Bootstrap\App;
use App\Modules\Commercial\Application\ManageDailyRateService;
use App\Modules\Identity\Domain\OrganizationContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_array($payload['rows'] ?? null) || !is_array($payload['organization'] ?? null)) {
        throw new InvalidArgumentException('Payload worker rate không hợp lệ.');
    }
    $startAt = (float) ($payload['startAt'] ?? 0);
    while (microtime(true) < $startAt) {
        usleep(1_000);
    }
    $organization = $payload['organization'];
    $updated = App::boot(dirname(__DIR__, 2))->container()->get(ManageDailyRateService::class)->updateCalendar(
        (int) $payload['productId'],
        new OrganizationContext(
            (int) $organization['id'],
            (string) $organization['code'],
            (string) $organization['name'],
            (string) $organization['status'],
            true,
        ),
        (int) $payload['actorId'],
        $payload['rows'],
        (string) $payload['requestId'],
        '127.0.0.1',
    );
    echo json_encode(['success' => true, 'updated' => count($updated)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'success' => false,
        'error' => property_exists($exception, 'errorCode') ? $exception->errorCode : $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

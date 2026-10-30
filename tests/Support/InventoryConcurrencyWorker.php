<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Modules\Inventory\Application\BookingInventoryItem;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Clock\SystemClock;
use App\Shared\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Env::load(dirname(__DIR__, 2) . '/.env');
$payload = json_decode(base64_decode((string) ($argv[2] ?? ''), true) ?: '', true);
if (!is_array($payload)) {
    fwrite(STDERR, 'Payload worker không hợp lệ.');
    exit(2);
}

$pdo = Connection::create(DatabaseConfig::fromEnv());
$repository = new InventoryRepository($pdo);
$action = (string) ($argv[1] ?? '');

try {
    $pdo->beginTransaction();
    if ($action === 'hold') {
        $service = new TransactionalInventoryReservationService($pdo, $repository, new SystemClock());
        $service->holdForBooking(
            (int) $payload['bookingId'],
            [new BookingInventoryItem(
                (int) $payload['bookingItemId'],
                (int) $payload['roomTypeId'],
                [(string) $payload['stayDate']],
            )],
            null,
        );
        $success = true;
    } elseif ($action === 'reduce') {
        $success = $repository->upsertTotal(
            (int) $payload['roomTypeId'],
            (int) $payload['organizationId'],
            (string) $payload['stayDate'],
            (int) $payload['totalQuantity'],
        ) !== null;
    } else {
        throw new InvalidArgumentException('Action worker không được hỗ trợ.');
    }
    $pdo->commit();
    echo json_encode(['success' => $success], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

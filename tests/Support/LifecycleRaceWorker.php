<?php

declare(strict_types=1);

use App\Bootstrap\App;
use App\Modules\Bookings\Application\CancellationCommand;
use App\Modules\Bookings\Application\CancellationService;
use App\Modules\Bookings\Application\CompletionService;
use App\Modules\Bookings\Application\NoShowService;
use App\Modules\Bookings\Application\OnlinePaymentService;
use App\Modules\Bookings\Application\PaymentGatewayResult;
use App\Modules\Bookings\Application\WaiverDecision;
use App\Modules\Bookings\Application\WaiverService;
use App\Shared\Clock\ClockInterface;
use App\Shared\Clock\FrozenClock;
use App\Shared\Money\Money;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Payload worker lifecycle không hợp lệ.');
    }
    $startAt = (float) ($payload['startAt'] ?? 0);
    while (microtime(true) < $startAt) {
        usleep(1_000);
    }

    $container = App::boot(dirname(__DIR__, 2))->container();
    $clock = new FrozenClock(new DateTimeImmutable((string) ($payload['clock'] ?? 'now')));
    $container->instance(ClockInterface::class, $clock);
    $action = (string) ($payload['action'] ?? '');

    if ($action === 'cancel') {
        $receipt = $container->get(CancellationService::class)->cancel(
            (string) $payload['code'],
            (int) $payload['customerId'],
            new CancellationCommand(
                (string) $payload['key'],
                'Nghiệm thu tranh chấp vòng đời booking.',
                Money::vnd((string) $payload['expectedFee']),
            ),
            '127.0.0.1',
        );
        $result = ['success' => true, 'status' => 'CANCELLED', 'refund' => $receipt->refunded->decimal()];
    } elseif ($action === 'payment_success') {
        $booking = $container->get(OnlinePaymentService::class)->handleResult(
            (int) $payload['bookingId'],
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'ACCEPTANCE-FINALIZE'),
            (string) $payload['requestId'],
            '127.0.0.1',
        );
        $result = ['success' => true, 'status' => $booking->status];
    } elseif ($action === 'no_show') {
        $container->get(NoShowService::class)->mark(
            (string) $payload['code'],
            (int) $payload['organizationId'],
            (int) $payload['actorId'],
            (string) $payload['requestId'],
            '127.0.0.1',
        );
        $result = ['success' => true, 'status' => 'NO_SHOW'];
    } elseif ($action === 'waiver_approve') {
        $container->get(WaiverService::class)->decide(
            (int) $payload['waiverId'],
            (int) $payload['organizationId'],
            (int) $payload['actorId'],
            new WaiverDecision('APPROVED', 'Đồng ý miễn phí hủy.'),
            (string) $payload['requestId'],
            '127.0.0.1',
        );
        $result = ['success' => true, 'status' => 'CANCELLED'];
    } elseif ($action === 'complete') {
        $changed = $container->get(CompletionService::class)->completeDue(1);
        $result = ['success' => $changed === 1, 'status' => $changed === 1 ? 'COMPLETED' : 'UNCHANGED'];
    } else {
        throw new InvalidArgumentException('Action worker lifecycle không được hỗ trợ.');
    }
} catch (Throwable $exception) {
    $result = [
        'success' => false,
        'error' => property_exists($exception, 'errorCode') ? $exception->errorCode : $exception::class,
        'message' => $exception->getMessage(),
    ];
}

echo json_encode($result, JSON_THROW_ON_ERROR);

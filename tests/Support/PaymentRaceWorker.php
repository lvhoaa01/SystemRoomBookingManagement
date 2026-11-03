<?php

declare(strict_types=1);

use App\Bootstrap\App;
use App\Modules\Bookings\Application\OnlinePaymentService;
use App\Modules\Bookings\Application\PaymentExpiryService;
use App\Modules\Bookings\Application\PaymentGatewayResult;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$action = (string) ($argv[1] ?? '');
$bookingId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if (!is_int($bookingId) || $bookingId < 1) {
    fwrite(STDERR, 'Booking ID worker payment không hợp lệ.');
    exit(2);
}

try {
    $container = App::boot(dirname(__DIR__, 2))->container();
    if ($action === 'callback') {
        $booking = $container->get(OnlinePaymentService::class)->handleResult(
            $bookingId,
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'ACCEPTANCE-LATE-CAPTURE'),
            'acceptance-payment-callback',
            '127.0.0.1',
        );
        $result = ['success' => true, 'status' => $booking->status];
    } elseif ($action === 'expiry') {
        $expired = $container->get(PaymentExpiryService::class)->expireDue(1_000, 'acceptance-payment-expiry');
        $result = ['success' => true, 'expired' => $expired];
    } else {
        throw new InvalidArgumentException('Action worker payment không được hỗ trợ.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'success' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

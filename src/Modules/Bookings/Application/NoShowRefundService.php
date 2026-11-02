<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use PDO;
use RuntimeException;

final readonly class NoShowRefundService
{
    public function __construct(
        private PDO $pdo,
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function process(int $bookingId, Money $expected, int $actorId, string $requestId, string $clientIp): void
    {
        $payment = $this->payments->findByBookingId($bookingId);
        if ($expected->isZero() || $payment === null || $payment->refundStatus === 'SUCCEEDED') {
            return;
        }
        if ($payment->refundStatus !== 'PENDING' || $payment->refundIdempotencyKey === null) {
            throw new BookingException('REFUND_STATE_INVALID', 'Refund no-show không ở trạng thái có thể xử lý.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Refund no-show không được gọi trong transaction giữ tồn.');
        }
        $result = $this->gateway->refund(new PaymentGatewayRequest(
            $bookingId, $expected, $payment->refundIdempotencyKey, $payment->providerReference,
        ));
        $this->transactions->run(function () use ($bookingId, $expected, $actorId, $requestId, $clientIp, $result): void {
            $context = $this->lifecycle->lockById($bookingId);
            $payment = $this->payments->lockByBookingId($bookingId);
            if ($context === null || $context['status'] !== 'NO_SHOW' || $payment === null) {
                throw new BookingException('REFUND_STATE_INVALID', 'Booking no-show không còn hợp lệ để refund.');
            }
            if ($payment->refundStatus !== 'PENDING') {
                return;
            }
            $outcome = $result->status;
            if ($result->status === PaymentGatewayResult::SUCCESS
                && $result->amount instanceof Money && $result->amount->equals($expected)) {
                $this->payments->markRefundSucceeded($payment->id, $expected, (string) $result->providerReference, $this->clock->now());
                $outcome = 'SUCCEEDED';
            } elseif ($result->status !== PaymentGatewayResult::TIMEOUT) {
                $this->payments->markRefundFailed($payment->id);
                $outcome = $result->status === PaymentGatewayResult::SUCCESS ? 'AMOUNT_MISMATCH' : 'FAILED';
            }
            $auditId = 'no-show-refund:' . $requestId . ':' . strtolower($outcome);
            if ($this->audit->findByRequestId($auditId) === null) {
                $this->audit->record($actorId, 'NO_SHOW_REFUND_' . $outcome, 'ThanhToan', (string) $bookingId,
                    $auditId, null, ['expectedAmount' => $expected->decimal(), 'outcome' => $outcome], $clientIp);
            }
        });
    }
}

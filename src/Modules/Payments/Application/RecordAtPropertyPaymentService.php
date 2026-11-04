<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Payments\Infrastructure\PaymentTransactionRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;

final readonly class RecordAtPropertyPaymentService
{
    public function __construct(
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private PaymentTransactionRepository $transactionsRepository,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function record(
        string $code,
        int $organizationId,
        int $actorId,
        AtPropertyPaymentRequest $request,
        string $clientIp,
    ): CollectedPayment {
        $code = strtoupper(trim($code));
        if ($organizationId < 1 || $actorId < 1 || preg_match('/^[A-Z0-9-]{1,24}$/', $code) !== 1) {
            throw new BookingException('PAYMENT_COLLECTION_REQUEST_INVALID', 'Yêu cầu ghi nhận thanh toán không hợp lệ.');
        }

        return $this->transactions->run(function () use (
            $code,
            $organizationId,
            $actorId,
            $request,
            $clientIp,
        ): CollectedPayment {
            $context = $this->lifecycle->lockForOrganization($code, $organizationId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tổ chức hiện tại.');
            }

            $existing = $this->transactionsRepository->findByIdempotencyKey($request->idempotencyKey);
            if ($existing instanceof CollectedPayment) {
                if ($existing->bookingId !== (int) $context['id']
                    || $existing->method !== $request->method
                    || $existing->reference !== $request->reference) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'Mã thanh toán đã được dùng cho nội dung khác.');
                }
                return $existing;
            }

            if ($context['status'] !== 'CHECKED_IN') {
                throw new BookingException('PAYMENT_COLLECTION_NOT_ALLOWED', 'Chỉ ghi nhận tiền khi khách đang lưu trú.');
            }
            if ($context['paymentTiming'] !== 'PAY_AT_PROPERTY') {
                throw new BookingException('PAYMENT_COLLECTION_NOT_ALLOWED', 'Booking này đã chọn thanh toán trực tuyến.');
            }

            $payment = $this->payments->lockByBookingId((int) $context['id']);
            if ($payment === null) {
                throw new BookingException('PAYMENT_STATE_INVALID', 'Booking không có payment hợp lệ.');
            }
            if ($payment->status === 'PAID') {
                throw new BookingException('PAYMENT_ALREADY_COLLECTED', 'Khoản tiền tại cơ sở đã được ghi nhận.');
            }
            if ($payment->status !== 'NOT_TRACKED' || !$payment->paid->isZero()) {
                throw new BookingException('PAYMENT_STATE_INVALID', 'Payment không ở trạng thái có thể ghi nhận.');
            }

            $collectedAt = $this->clock->now();
            $this->payments->markPaidAtProperty(
                $payment->id,
                $payment->total,
                $request->idempotencyKey,
                $request->reference,
                $collectedAt,
            );
            $collected = $this->transactionsRepository->createCollection(
                $payment->id,
                (int) $context['id'],
                $actorId,
                $payment->total,
                $request->method,
                $request->reference,
                $request->idempotencyKey,
                $collectedAt,
                [
                    'version' => 1,
                    'bookingCode' => $context['code'],
                    'propertyId' => $context['propertyId'],
                    'organizationId' => $context['organizationId'],
                ],
            );
            $this->audit->record(
                $actorId,
                'PAYMENT_COLLECTED_AT_PROPERTY',
                'ThanhToan',
                (string) $payment->id,
                $request->idempotencyKey,
                ['status' => 'NOT_TRACKED', 'paid' => $payment->paid->decimal()],
                [
                    'status' => 'PAID',
                    'paid' => $payment->total->decimal(),
                    'method' => $request->method,
                    'reference' => $request->reference,
                    'transactionId' => $collected->transactionId,
                ],
                $clientIp,
            );

            return $collected;
        });
    }
}

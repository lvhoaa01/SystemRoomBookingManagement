<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final readonly class CheckInService
{
    public function __construct(
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function checkIn(
        string $code,
        int $organizationId,
        int $actorId,
        string $requestId,
        string $clientIp,
    ): DateTimeImmutable {
        $code = strtoupper(trim($code));
        $this->assertInput($code, $organizationId, $actorId, $requestId);

        return $this->transactions->run(function () use (
            $code,
            $organizationId,
            $actorId,
            $requestId,
            $clientIp,
        ): DateTimeImmutable {
            $context = $this->lifecycle->lockForOrganization($code, $organizationId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tổ chức hiện tại.');
            }

            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'BOOKING_CHECKED_IN'
                    || ($existing['entityId'] ?? null) !== (string) $context['id']
                    || (int) ($existing['actorId'] ?? 0) !== $actorId) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }

                return new DateTimeImmutable((string) ($existing['after']['checkedInAt'] ?? 'now'));
            }

            if ($context['status'] !== 'CONFIRMED') {
                throw new BookingException('CHECK_IN_NOT_ALLOWED', 'Chỉ booking đã xác nhận mới được nhận khách.');
            }
            $this->assertWindow($context);

            $payment = $this->payments->lockByBookingId((int) $context['id']);
            if ($payment === null
                || ($context['paymentTiming'] === 'PAY_ONLINE' && $payment->status !== 'PAID')
                || ($context['paymentTiming'] === 'PAY_AT_PROPERTY' && $payment->status !== 'NOT_TRACKED')) {
                throw new BookingException('PAYMENT_STATE_INVALID', 'Trạng thái thanh toán không cho phép nhận khách.');
            }

            $checkedInAt = $this->clock->now();
            $this->lifecycle->markCheckedIn((int) $context['id'], $actorId, $checkedInAt);
            $this->audit->record(
                $actorId,
                'BOOKING_CHECKED_IN',
                'DatCho',
                (string) $context['id'],
                $requestId,
                ['status' => 'CONFIRMED'],
                ['status' => 'CHECKED_IN', 'checkedInAt' => $checkedInAt->format(DATE_ATOM)],
                $clientIp,
            );

            return $checkedInAt;
        });
    }

    /** @param array<string, mixed> $context */
    private function assertWindow(array $context): void
    {
        $zone = new DateTimeZone((string) $context['timezone']);
        $start = new DateTimeImmutable($context['checkIn'] . ' 00:00:00', $zone);
        $end = new DateTimeImmutable($context['checkOut'] . ' ' . $context['checkOutTime'], $zone);
        $now = $this->clock->now();
        if ($now < $start || $now >= $end) {
            throw new BookingException(
                'CHECK_IN_WINDOW_INVALID',
                'Chỉ nhận khách từ ngày nhận phòng đến trước giờ trả phòng.',
            );
        }
    }

    private function assertInput(string $code, int $organizationId, int $actorId, string $requestId): void
    {
        if ($organizationId < 1 || $actorId < 1
            || preg_match('/^[A-Z0-9-]{1,24}$/', $code) !== 1
            || preg_match('/^[A-Za-z0-9._-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('CHECK_IN_REQUEST_INVALID', 'Yêu cầu nhận khách không hợp lệ.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Commercial\Application\NoShowFeeCalculator;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;

final readonly class NoShowService
{
    public function __construct(
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private NoShowFeeCalculator $calculator,
        private InventoryReservationService $inventory,
        private NoShowRefundService $refunds,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{fee: Money, expectedRefund: Money} */
    public function mark(string $code, int $organizationId, int $actorId, string $requestId, string $clientIp): array
    {
        $code = strtoupper(trim($code));
        $this->assertInput($code, $organizationId, $actorId, $requestId);
        $result = $this->transactions->run(function () use ($code, $organizationId, $actorId, $requestId, $clientIp): array {
            $context = $this->lifecycle->lockForOrganization($code, $organizationId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tổ chức hiện tại.');
            }
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'BOOKING_MARKED_NO_SHOW'
                    || ($existing['entityId'] ?? null) !== (string) $context['id']
                    || (int) ($existing['actorId'] ?? 0) !== $actorId) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                $expectedRefund = Money::vnd((string) ($existing['after']['expectedRefund'] ?? 0));
                $payment = $this->payments->lockByBookingId((int) $context['id']);
                if (!$expectedRefund->isZero() && $payment !== null && $payment->refundStatus === 'FAILED') {
                    $this->payments->startRefund(
                        $payment->id,
                        $expectedRefund,
                        self::refundKey($requestId),
                        'BOOKING_NO_SHOW',
                    );
                }
                return [
                    'bookingId' => (int) $context['id'],
                    'fee' => Money::vnd((string) ($existing['after']['fee'] ?? 0)),
                    'expectedRefund' => $expectedRefund,
                ];
            }
            if ($context['status'] !== 'CONFIRMED') {
                throw new BookingException('NO_SHOW_NOT_ALLOWED', 'Chỉ booking đã xác nhận mới được đánh dấu không đến.');
            }
            $this->assertWindow($context);
            $payment = $this->payments->lockByBookingId((int) $context['id']);
            if ($payment === null) {
                throw new BookingException('PAYMENT_STATE_INVALID', 'Booking không có payment hợp lệ.');
            }
            if (($context['paymentTiming'] === 'PAY_ONLINE' && $payment->status !== 'PAID')
                || ($context['paymentTiming'] === 'PAY_AT_PROPERTY' && $payment->status !== 'NOT_TRACKED')) {
                throw new BookingException('PAYMENT_STATE_INVALID', 'Payment không phù hợp để xử lý no-show.');
            }
            $fee = $this->calculator->calculate(Money::vnd((string) $context['grandTotal']), $context['items']);
            $expectedRefund = $context['paymentTiming'] === 'PAY_ONLINE' && $payment->paid->compareTo($fee) > 0
                ? $payment->paid->subtract($fee) : Money::vnd(0);
            $fingerprint = CanonicalJson::hash(['bookingId' => $context['id'], 'fee' => $fee, 'requestId' => $requestId]);
            $released = $this->inventory->releaseActive((int) $context['id'], 'BOOKING_NO_SHOW');
            $snapshot = [
                'version' => 1, 'calculatedAt' => $this->clock->now()->format(DATE_ATOM),
                'timezone' => $context['timezone'], 'bookingTotal' => $context['grandTotal'],
                'paidAmount' => $payment->paid->decimal(), 'fee' => $fee->decimal(),
                'expectedRefund' => $expectedRefund->decimal(), 'items' => $context['items'],
                'commandFingerprint' => $fingerprint,
            ];
            $this->lifecycle->markNoShow((int) $context['id'], $actorId, $fee, $snapshot, $this->clock->now());
            if (!$expectedRefund->isZero()) {
                $this->payments->startRefund($payment->id, $expectedRefund, self::refundKey($requestId), 'BOOKING_NO_SHOW');
            }
            $this->audit->record($actorId, 'BOOKING_MARKED_NO_SHOW', 'DatCho', (string) $context['id'], $requestId,
                ['status' => 'CONFIRMED', 'activeLedgerCount' => $released],
                ['status' => 'NO_SHOW', 'fee' => $fee->decimal(), 'expectedRefund' => $expectedRefund->decimal(), 'snapshot' => $snapshot],
                $clientIp);
            return ['bookingId' => (int) $context['id'], 'fee' => $fee, 'expectedRefund' => $expectedRefund];
        });
        $this->refunds->process($result['bookingId'], $result['expectedRefund'], $actorId, $requestId, $clientIp);
        return ['fee' => $result['fee'], 'expectedRefund' => $result['expectedRefund']];
    }

    /** @param array<string, mixed> $context */
    private function assertWindow(array $context): void
    {
        $zone = new DateTimeZone((string) $context['timezone']);
        $start = new DateTimeImmutable($context['checkIn'] . ' ' . $context['checkInTime'], $zone);
        $end = (new DateTimeImmutable($context['checkIn'] . ' 23:59:59.999999', $zone))->modify('+1 day');
        $now = $this->clock->now();
        if ($now < $start || $now > $end) {
            throw new BookingException('NO_SHOW_WINDOW_INVALID', 'Chỉ đánh dấu no-show từ giờ nhận phòng đến hết ngày kế tiếp.');
        }
    }

    private function assertInput(string $code, int $organizationId, int $actorId, string $requestId): void
    {
        if ($organizationId < 1 || $actorId < 1 || preg_match('/^[A-Z0-9-]{1,24}$/', $code) !== 1
            || preg_match('/^[A-Za-z0-9._-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('NO_SHOW_REQUEST_INVALID', 'Yêu cầu đánh dấu no-show không hợp lệ.');
        }
    }

    private static function refundKey(string $requestId): string
    {
        $hex = hash('sha256', 'no-show:' . $requestId);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}

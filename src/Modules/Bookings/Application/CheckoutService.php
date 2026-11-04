<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Payments\Application\IssueInvoiceService;
use App\Modules\Payments\Domain\Invoice;
use App\Modules\Payments\Infrastructure\InvoiceRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;

final readonly class CheckoutService
{
    public function __construct(
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private InventoryReservationService $inventory,
        private IssueInvoiceService $invoiceIssuer,
        private InvoiceRepository $invoices,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function checkout(
        string $code,
        int $organizationId,
        int $actorId,
        string $idempotencyKey,
        string $clientIp,
    ): Invoice {
        $code = strtoupper(trim($code));
        $idempotencyKey = strtolower(trim($idempotencyKey));
        if ($organizationId < 1 || $actorId < 1
            || preg_match('/^[A-Z0-9-]{1,24}$/', $code) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idempotencyKey) !== 1) {
            throw new BookingException('CHECKOUT_REQUEST_INVALID', 'Yêu cầu trả phòng không hợp lệ.');
        }

        return $this->transactions->run(function () use (
            $code,
            $organizationId,
            $actorId,
            $idempotencyKey,
            $clientIp,
        ): Invoice {
            $context = $this->lifecycle->lockForOrganization($code, $organizationId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tổ chức hiện tại.');
            }
            $existingAudit = $this->audit->findByRequestId($idempotencyKey);
            if ($existingAudit !== null) {
                if (($existingAudit['action'] ?? null) !== 'BOOKING_CHECKED_OUT'
                    || ($existingAudit['entityId'] ?? null) !== (string) $context['id']
                    || (int) ($existingAudit['actorId'] ?? 0) !== $actorId) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'Mã trả phòng đã được dùng cho thao tác khác.');
                }
                $invoice = $this->invoices->findByBookingId((int) $context['id']);
                if (!$invoice instanceof Invoice) {
                    throw new BookingException('INVOICE_NOT_FOUND', 'Không tìm thấy hóa đơn của lần trả phòng trước.');
                }
                return $invoice;
            }
            if ($context['status'] !== 'CHECKED_IN') {
                throw new BookingException('CHECKOUT_NOT_ALLOWED', 'Chỉ trả phòng cho booking đang lưu trú.');
            }
            $payment = $this->payments->lockByBookingId((int) $context['id']);
            if ($payment === null || $payment->status !== 'PAID'
                || !$payment->refunded->isZero() || !$payment->paid->equals($payment->total)) {
                throw new BookingException('PAYMENT_NOT_SETTLED', 'Booking phải thanh toán đủ trước khi trả phòng.');
            }

            $completedAt = $this->clock->now();
            $released = $this->inventory->releaseActive((int) $context['id'], 'BOOKING_COMPLETED');
            $this->lifecycle->markCompleted((int) $context['id'], $completedAt);
            $invoice = $this->invoiceIssuer->issue((int) $context['id'], $actorId, $completedAt);
            $this->audit->record(
                $actorId,
                'BOOKING_CHECKED_OUT',
                'DatCho',
                (string) $context['id'],
                $idempotencyKey,
                ['status' => 'CHECKED_IN', 'activeLedgerCount' => $released],
                [
                    'status' => 'COMPLETED',
                    'completedAt' => $completedAt->format(DATE_ATOM),
                    'invoiceId' => $invoice->id,
                    'invoiceNumber' => $invoice->number,
                ],
                $clientIp,
            );
            return $invoice;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\BookingLifecycleRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Payments\Application\IssueInvoiceService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CompletionService
{
    public function __construct(
        private BookingLifecycleRepository $lifecycle,
        private PaymentRepository $payments,
        private InventoryReservationService $inventory,
        private IssueInvoiceService $invoiceIssuer,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function completeDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Batch completion phải từ 1 đến 1000.');
        }
        $completed = 0;
        foreach ($this->lifecycle->completionCandidates($limit) as $candidate) {
            $cutoff = new DateTimeImmutable(
                $candidate['checkOut'] . ' ' . $candidate['checkOutTime'],
                new DateTimeZone($candidate['timezone']),
            );
            if ($this->clock->now() < $cutoff) {
                continue;
            }
            $changed = $this->transactions->run(function () use ($candidate): bool {
                $context = $this->lifecycle->lockById($candidate['id']);
                if ($context === null || $context['status'] !== 'CHECKED_IN') {
                    return false;
                }
                $cutoff = new DateTimeImmutable(
                    $context['checkOut'] . ' ' . $context['checkOutTime'],
                    new DateTimeZone($context['timezone']),
                );
                if ($this->clock->now() < $cutoff) {
                    return false;
                }
                $payment = $this->payments->lockByBookingId((int) $context['id']);
                if ($payment === null || $payment->status !== 'PAID'
                    || !$payment->refunded->isZero() || !$payment->paid->equals($payment->total)) {
                    return false;
                }
                $released = $this->inventory->releaseActive((int) $context['id'], 'BOOKING_COMPLETED');
                $this->lifecycle->markCompleted((int) $context['id'], $this->clock->now());
                $invoice = $this->invoiceIssuer->issue((int) $context['id'], null, $this->clock->now());
                $requestId = 'completion:' . $context['id'];
                if ($this->audit->findByRequestId($requestId) === null) {
                    $this->audit->record(null, 'BOOKING_COMPLETED', 'DatCho', (string) $context['id'], $requestId,
                        ['status' => $context['status'], 'activeLedgerCount' => $released],
                        ['status' => 'COMPLETED', 'completedAt' => $this->clock->now()->format(DATE_ATOM),
                            'invoiceId' => $invoice->id, 'invoiceNumber' => $invoice->number], null);
                }
                return true;
            });
            $completed += $changed ? 1 : 0;
        }
        return $completed;
    }
}

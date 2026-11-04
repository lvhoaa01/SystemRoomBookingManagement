<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Bookings\Application\BookingException;
use App\Modules\Payments\Domain\Invoice;
use App\Modules\Payments\Infrastructure\InvoiceRepository;
use App\Modules\Notifications\Application\QueueInvoiceNotificationService;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class IssueInvoiceService
{
    public function __construct(
        private PDO $pdo,
        private InvoiceRepository $invoices,
        private QueueInvoiceNotificationService $notifications,
    ) {
    }

    public function issue(int $bookingId, ?int $actorId, DateTimeImmutable $at): Invoice
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Hóa đơn chỉ được phát hành trong transaction hoàn tất lưu trú.');
        }
        $existing = $this->invoices->findByBookingId($bookingId);
        if ($existing instanceof Invoice) {
            $this->notifications->queue($existing, $at);
            return $existing;
        }
        $source = $this->invoices->source($bookingId);
        $paidNet = Money::vnd((string) $source['paid'])->subtract(Money::vnd((string) $source['refunded']));
        $total = Money::vnd((string) $source['total']);
        if (($source['status'] ?? null) !== 'COMPLETED'
            || ($source['paymentStatus'] ?? null) !== 'PAID'
            || !$paidNet->equals($total)) {
            throw new BookingException(
                'INVOICE_NOT_ALLOWED',
                'Chỉ phát hành hóa đơn cho booking hoàn tất và đã thanh toán đủ.',
            );
        }
        $lineTotal = Money::vnd(0);
        foreach ($source['items'] as $item) {
            $lineTotal = $lineTotal->add(Money::vnd((string) $item['total']));
        }
        if (!$lineTotal->equals($total)) {
            throw new BookingException('INVOICE_TOTAL_MISMATCH', 'Tổng chi tiết hóa đơn không khớp booking.');
        }
        $invoice = $this->invoices->create($source, $actorId, $at);
        $this->notifications->queue($invoice, $at);
        return $invoice;
    }
}

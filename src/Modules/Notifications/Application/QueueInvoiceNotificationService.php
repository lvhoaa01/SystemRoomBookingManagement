<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Config\AppConfig;
use App\Modules\Notifications\Infrastructure\NotificationRepository;
use App\Modules\Payments\Domain\Invoice;
use DateTimeImmutable;
use RuntimeException;

final readonly class QueueInvoiceNotificationService
{
    public function __construct(
        private NotificationRepository $notifications,
        private AppConfig $appConfig,
    ) {
    }

    public function queue(Invoice $invoice, DateTimeImmutable $at): void
    {
        $booker = $invoice->snapshot['booker'] ?? null;
        $recipient = is_array($booker) ? trim((string) ($booker['email'] ?? '')) : '';
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email người đặt trong snapshot hóa đơn không hợp lệ.');
        }
        $baseUrl = rtrim($this->appConfig->url, '/');
        $this->notifications->enqueueInvoice(
            $invoice->id,
            $recipient,
            'Hóa đơn ' . $invoice->number . ' từ StayBooking',
            [
                'version' => 1,
                'invoiceNumber' => $invoice->number,
                'bookingCode' => $invoice->bookingCode,
                'propertyName' => $invoice->propertyName,
                'bookerName' => is_array($booker) ? (string) ($booker['name'] ?? '') : '',
                'total' => $invoice->total->amount(),
                'currency' => $invoice->total->currency,
                'invoiceUrl' => $baseUrl . '/account/bookings/' . rawurlencode($invoice->bookingCode) . '/invoice',
            ],
            'invoice:' . $invoice->id . ':issued',
            $at,
        );
    }
}

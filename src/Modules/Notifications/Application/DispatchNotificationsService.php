<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\MailMessage;
use App\Modules\Notifications\Domain\Notification;
use App\Modules\Notifications\Infrastructure\NotificationRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use DateInterval;
use Throwable;

final readonly class DispatchNotificationsService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private NotificationRepository $notifications,
        private Mailer $mailer,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{sent: int, failed: int} */
    public function dispatch(int $limit): array
    {
        $sent = 0;
        $failed = 0;
        for ($i = 0; $i < $limit; ++$i) {
            $now = $this->clock->now();
            $notification = $this->transactions->run(fn (): ?Notification =>
                $this->notifications->claimNext($now, $now->sub(new DateInterval('PT15M')), self::MAX_ATTEMPTS));
            if (!$notification instanceof Notification) {
                break;
            }
            try {
                $this->mailer->send($this->message($notification));
                $completedAt = $this->clock->now();
                $this->transactions->run(fn () => $this->notifications->markSent($notification->id, $completedAt));
                ++$sent;
            } catch (Throwable $exception) {
                $failedAt = $this->clock->now();
                $minutes = 2 ** max(0, $notification->attempts - 1);
                $retryAt = $failedAt->add(new DateInterval('PT' . $minutes . 'M'));
                $this->transactions->run(fn () => $this->notifications->markFailed(
                    $notification->id,
                    $exception->getMessage(),
                    $retryAt,
                    $failedAt,
                ));
                ++$failed;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    private function message(Notification $notification): MailMessage
    {
        $payload = $notification->payload;
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = number_format((int) ($payload['total'] ?? 0), 0, ',', '.');
        $url = $escape($payload['invoiceUrl'] ?? '');
        $name = $escape($payload['bookerName'] ?? 'Quý khách');
        $invoice = $escape($payload['invoiceNumber'] ?? '');
        $booking = $escape($payload['bookingCode'] ?? '');
        $property = $escape($payload['propertyName'] ?? '');
        $html = '<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#1f2937">'
            . '<h1 style="color:#003b95">StayBooking</h1>'
            . '<p>Xin chào ' . $name . ',</p>'
            . '<p>Hóa đơn <strong>' . $invoice . '</strong> cho mã đặt chỗ <strong>' . $booking . '</strong> tại '
            . $property . ' đã được phát hành.</p>'
            . '<p>Tổng đã thanh toán: <strong>' . $amount . ' VND</strong></p>'
            . '<p><a href="' . $url . '" style="background:#006ce4;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px">Xem hóa đơn</a></p>'
            . '<p>Bạn cần đăng nhập đúng tài khoản đã đặt phòng để mở hóa đơn.</p>'
            . '</body></html>';
        $text = "Hóa đơn {$invoice} cho booking {$booking}: {$amount} VND. Xem tại {$url}";
        return new MailMessage(
            $notification->recipient,
            $notification->subject,
            $html,
            $text,
            $notification->idempotencyKey,
        );
    }
}

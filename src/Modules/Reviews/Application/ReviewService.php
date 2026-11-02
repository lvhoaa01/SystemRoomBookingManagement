<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Modules\Bookings\Application\BookingException;
use App\Modules\Reviews\Infrastructure\ReviewRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Json\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReviewService
{
    public function __construct(
        private ReviewRepository $reviews,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function create(string $code, int $customerId, ReviewRequest $request, string $requestId, string $clientIp): int
    {
        $code = strtoupper(trim($code));
        $this->requestId($requestId);
        return $this->transactions->run(function () use ($code, $customerId, $request, $requestId, $clientIp): int {
            $booking = $this->reviews->lockBooking($code, $customerId);
            if ($booking === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $fingerprint = CanonicalJson::hash([
                'bookingId' => $booking['id'],
                'overall' => $request->overall,
                'categories' => $request->categories,
                'comment' => $request->comment,
            ]);
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'REVIEW_CREATED'
                    || (int) ($existing['actorId'] ?? 0) !== $customerId
                    || ($existing['after']['fingerprint'] ?? null) !== $fingerprint) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return (int) $existing['entityId'];
            }
            if ($booking['status'] !== 'COMPLETED') {
                throw new BookingException('REVIEW_NOT_ALLOWED', 'Chỉ booking đã hoàn tất mới được đánh giá.');
            }
            $zone = new DateTimeZone((string) $booking['timezone']);
            $start = new DateTimeImmutable((string) $booking['checkOut'] . ' 00:00:00', $zone);
            $end = $start->modify('+90 days')->setTime(23, 59, 59, 999999);
            if ($this->clock->now() < $start || $this->clock->now() > $end) {
                throw new BookingException('REVIEW_WINDOW_INVALID', 'Đánh giá chỉ được gửi trong 90 ngày từ ngày trả phòng.');
            }
            if ($this->reviews->existsForBooking((int) $booking['id'])) {
                throw new BookingException('REVIEW_ALREADY_EXISTS', 'Booking đã có đánh giá.');
            }
            $reviewId = $this->reviews->create((int) $booking['id'], (int) $booking['propertyId'], $customerId, $request);
            $this->audit->record($customerId, 'REVIEW_CREATED', 'DanhGia', (string) $reviewId, $requestId,
                null, ['status' => 'PUBLISHED', 'bookingId' => $booking['id'], 'fingerprint' => $fingerprint], $clientIp);
            return $reviewId;
        });
    }

    public function moderate(int $reviewId, string $status, int $adminId, string $requestId, string $clientIp): void
    {
        $status = strtoupper(trim($status));
        $this->requestId($requestId);
        if ($reviewId < 1 || !in_array($status, ['PUBLISHED', 'HIDDEN'], true)) {
            throw new InvalidArgumentException('Trạng thái kiểm duyệt review không hợp lệ.');
        }
        $this->transactions->run(function () use ($reviewId, $status, $adminId, $requestId, $clientIp): void {
            $review = $this->reviews->lockReview($reviewId);
            if ($review === null) {
                throw new BookingException('REVIEW_NOT_FOUND', 'Không tìm thấy review.');
            }
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if (($existing['action'] ?? null) !== 'REVIEW_MODERATED'
                    || ($existing['entityId'] ?? null) !== (string) $reviewId
                    || ($existing['after']['status'] ?? null) !== $status) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return;
            }
            $this->reviews->setStatus($reviewId, $status);
            $this->audit->record($adminId, 'REVIEW_MODERATED', 'DanhGia', (string) $reviewId, $requestId,
                ['status' => $review['status']], ['status' => $status], $clientIp);
        });
    }

    /** @return list<array<string, mixed>> */
    public function adminList(): array
    {
        return $this->reviews->listAll();
    }

    private function requestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $requestId) !== 1) {
            throw new InvalidArgumentException('RequestID review không hợp lệ.');
        }
    }
}

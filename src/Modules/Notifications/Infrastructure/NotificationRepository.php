<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Modules\Notifications\Domain\Notification;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class NotificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueueInvoice(
        int $invoiceId,
        string $recipient,
        string $subject,
        array $payload,
        string $idempotencyKey,
        DateTimeImmutable $sendAfter,
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO HangDoiThongBao
                (HoaDonID, LoaiThongBao, Kenh, EmailNguoiNhan, TieuDe, PayloadJSON,
                 TrangThai, SoLanThu, GuiSauLuc, IdempotencyKey)
             VALUES (:invoiceId, 'INVOICE_ISSUED', 'EMAIL', :recipient, :subject, :payload,
                     'PENDING', 0, :sendAfter, :idempotencyKey)
             ON DUPLICATE KEY UPDATE ThongBaoID=ThongBaoID"
        );
        $statement->execute([
            'invoiceId' => $invoiceId,
            'recipient' => $recipient,
            'subject' => $subject,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sendAfter' => self::utc($sendAfter),
            'idempotencyKey' => $idempotencyKey,
        ]);
    }

    public function claimNext(DateTimeImmutable $now, DateTimeImmutable $staleBefore, int $maxAttempts): ?Notification
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Claim thông báo phải chạy trong transaction.');
        }
        $statement = $this->pdo->prepare(
            "SELECT * FROM HangDoiThongBao
             WHERE SoLanThu < :maxAttempts
               AND ((TrangThai IN ('PENDING','FAILED') AND GuiSauLuc <= :now)
                    OR (TrangThai='SENDING' AND CapNhatLuc <= :staleBefore))
             ORDER BY GuiSauLuc, ThongBaoID
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            'maxAttempts' => $maxAttempts,
            'now' => self::utc($now),
            'staleBefore' => self::utc($staleBefore),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $update = $this->pdo->prepare(
            "UPDATE HangDoiThongBao
             SET TrangThai='SENDING', SoLanThu=SoLanThu+1, LoiCuoi=NULL, CapNhatLuc=:now
             WHERE ThongBaoID=:id"
        );
        $update->execute(['now' => self::utc($now), 'id' => $row['ThongBaoID']]);
        $row['TrangThai'] = 'SENDING';
        $row['SoLanThu'] = (int) $row['SoLanThu'] + 1;
        return $this->hydrate($row);
    }

    public function markSent(int $id, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE HangDoiThongBao
             SET TrangThai='SENT', GuiLuc=:sentAt, LoiCuoi=NULL, CapNhatLuc=:updatedAt
             WHERE ThongBaoID=:id AND TrangThai='SENDING'"
        );
        $timestamp = self::utc($at);
        $statement->execute(['sentAt' => $timestamp, 'updatedAt' => $timestamp, 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể đánh dấu thông báo đã gửi.');
        }
    }

    public function markFailed(int $id, string $error, DateTimeImmutable $retryAt, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE HangDoiThongBao
             SET TrangThai='FAILED', GuiSauLuc=:retryAt, GuiLuc=NULL,
                 LoiCuoi=:error, CapNhatLuc=:at
             WHERE ThongBaoID=:id AND TrangThai='SENDING'"
        );
        $statement->execute([
            'retryAt' => self::utc($retryAt),
            'error' => mb_substr($error, 0, 500),
            'at' => self::utc($at),
            'id' => $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể đánh dấu thông báo gửi lỗi.');
        }
    }

    public function findByInvoiceId(int $invoiceId): ?Notification
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM HangDoiThongBao
             WHERE HoaDonID=? AND LoaiThongBao='INVOICE_ISSUED'"
        );
        $statement->execute([$invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Notification
    {
        try {
            $payload = json_decode((string) $row['PayloadJSON'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Payload thông báo không hợp lệ.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Payload thông báo phải là object.');
        }
        return new Notification(
            (int) $row['ThongBaoID'],
            (int) $row['HoaDonID'],
            (string) $row['EmailNguoiNhan'],
            (string) $row['TieuDe'],
            $payload,
            (string) $row['TrangThai'],
            (int) $row['SoLanThu'],
            (string) $row['IdempotencyKey'],
            new DateTimeImmutable((string) $row['GuiSauLuc'], new DateTimeZone('UTC')),
        );
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

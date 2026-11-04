<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Application\CollectedPayment;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PaymentTransactionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByIdempotencyKey(string $key): ?CollectedPayment
    {
        $statement = $this->pdo->prepare(
            "SELECT gd.GiaoDichThanhToanID AS id, tt.DatChoID AS bookingId,
                    gd.SoTien AS amount, gd.PhuongThuc AS method, gd.ThamChieu AS reference,
                    gd.GhiNhanLuc AS collectedAt
             FROM GiaoDichThanhToan gd
             INNER JOIN ThanhToan tt ON tt.ThanhToanID=gd.ThanhToanID
             WHERE gd.IdempotencyKey=?"
        );
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row, true) : null;
    }

    /** @param array<string, mixed> $metadata */
    public function createCollection(
        int $paymentId,
        int $bookingId,
        int $actorId,
        Money $amount,
        string $method,
        ?string $reference,
        string $idempotencyKey,
        DateTimeImmutable $at,
        array $metadata,
    ): CollectedPayment {
        $statement = $this->pdo->prepare(
            "INSERT INTO GiaoDichThanhToan
                (ThanhToanID, LoaiGiaoDich, PhuongThuc, SoTien, TienTe, TrangThai,
                 IdempotencyKey, ThamChieu, NguoiGhiNhanID, GhiNhanLuc, MetadataJSON)
             VALUES (:paymentId, 'COLLECTION', :method, :amount, 'VND', 'SUCCEEDED',
                     :idempotencyKey, :reference, :actorId, :collectedAt, :metadata)"
        );
        $statement->execute([
            'paymentId' => $paymentId,
            'method' => $method,
            'amount' => $amount->decimal(),
            'idempotencyKey' => $idempotencyKey,
            'reference' => $reference,
            'actorId' => $actorId,
            'collectedAt' => self::utc($at),
            'metadata' => CanonicalJson::encode($metadata),
        ]);

        return new CollectedPayment(
            (int) $this->pdo->lastInsertId(),
            $bookingId,
            $amount,
            $method,
            $reference,
            $at,
            false,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, bool $replayed): CollectedPayment
    {
        return new CollectedPayment(
            (int) $row['id'],
            (int) $row['bookingId'],
            Money::vnd((string) $row['amount']),
            (string) $row['method'],
            $row['reference'] === null ? null : (string) $row['reference'],
            new DateTimeImmutable((string) $row['collectedAt'], new DateTimeZone('UTC')),
            $replayed,
        );
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

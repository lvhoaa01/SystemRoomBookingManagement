<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\PreviewExpiryService;
use App\Modules\Bookings\Domain\PreviewDraft;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PDO;
use Tests\Support\TestCase;

final class PreviewExpiryServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
    }

    public function test_invalidates_expired_preview_once_and_writes_audit(): void
    {
        $repository = new PreviewRepository($this->pdo);
        $clock = new FrozenClock(new DateTimeImmutable('2035-01-01T00:30:00Z'));
        $runId = 'test-preview-expiry-' . bin2hex(random_bytes(5));
        $preview = $repository->create(new PreviewDraft(
            hash('sha256', $runId),
            $this->customerId(),
            $this->propertyId(),
            '2035-02-01',
            '2035-02-02',
            Money::vnd(100_000),
            Money::vnd(0),
            Money::vnd(100_000),
            ['version' => 1, 'paymentTiming' => 'PAY_AT_PROPERTY', 'rooms' => []],
            hash('sha256', $runId . '-fingerprint'),
            $clock->now()->modify('-1 minute'),
            $clock->now()->modify('-16 minutes'),
        ));

        try {
            $service = new PreviewExpiryService(
                $repository,
                new TransactionManager($this->pdo, static fn (int $_): null => null),
                new DatabaseAuditTrail($this->pdo),
                $clock,
            );

            self::assertSame(1, $service->invalidateDue(10, $runId));
            self::assertSame(0, $service->invalidateDue(10, $runId));
            self::assertSame('INVALIDATED', $this->pdo->query(
                'SELECT TrangThai FROM XemTruocDatCho WHERE XemTruocDatChoID=' . $preview->id,
            )->fetchColumn());
            self::assertSame('1', (string) $this->pdo->query(
                "SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='" . $runId . '-' . $preview->id . "'",
            )->fetchColumn());
        } finally {
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$runId . '-' . $preview->id]);
            $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID=?')->execute([$preview->id]);
        }
    }

    private function customerId(): int
    {
        return (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro='CUSTOMER' AND TrangThai='HOAT_DONG' ORDER BY NguoiDungID LIMIT 1",
        )->fetchColumn();
    }

    private function propertyId(): int
    {
        return (int) $this->pdo->query(
            "SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE TrangThai='ACTIVE' ORDER BY CoSoLuuTruID LIMIT 1",
        )->fetchColumn();
    }
}

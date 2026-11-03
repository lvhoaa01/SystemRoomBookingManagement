<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use InvalidArgumentException;

final readonly class PreviewExpiryService
{
    public function __construct(
        private PreviewRepository $previews,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function invalidateDue(int $limit = 100, ?string $runId = null): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Batch preview phải từ 1 đến 1000.');
        }

        $now = $this->clock->now();
        $runId ??= 'preview-expire-' . bin2hex(random_bytes(12));
        $invalidated = 0;
        foreach ($this->previews->expiredValidIds($now, $limit) as $previewId) {
            $changed = $this->transactions->run(function () use ($previewId, $now, $runId): bool {
                $preview = $this->previews->lockExpiryState($previewId);
                if ($preview === null || $preview['status'] !== 'VALID' || $preview['expiresAt'] > $now) {
                    return false;
                }
                if (!$this->previews->invalidateExpired($previewId, $now)) {
                    return false;
                }

                $this->audit->record(
                    null,
                    'PREVIEW_INVALIDATED_EXPIRED',
                    'XemTruocDatCho',
                    (string) $previewId,
                    $runId . '-' . $previewId,
                    ['status' => 'VALID'],
                    ['status' => 'INVALIDATED', 'expiredAt' => $preview['expiresAt']->format(DATE_ATOM)],
                    null,
                );
                return true;
            });
            $invalidated += $changed ? 1 : 0;
        }

        return $invalidated;
    }
}

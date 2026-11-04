<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Reports\Domain\RevenueSettlement;
use App\Modules\Reports\Infrastructure\RevenueSettlementRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use DomainException;

final readonly class RevenueSettlementService
{
    public function __construct(
        private RevenueSettlementRepository $settlements,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function generate(
        RevenueSettlementRequest $request,
        int $organizationId,
        int $actorId,
        string $requestId,
        string $clientIp,
    ): RevenueSettlement {
        return $this->transactions->run(function () use ($request, $organizationId, $actorId, $requestId, $clientIp): RevenueSettlement {
            if (!$this->settlements->propertyBelongsTo($request->propertyId, $organizationId)) {
                throw new DomainException('Cơ sở không thuộc tổ chức đang đăng nhập.');
            }
            if ($this->settlements->hasFinalizedOverlap($request->propertyId, $request->from, $request->to)) {
                throw new DomainException('Khoảng ngày này giao với một kỳ đối soát đã chốt.');
            }
            $lines = $this->settlements->sourceLines($request->propertyId, $request->from, $request->to);
            $settlement = $this->settlements->saveDraft(
                $organizationId,
                $request->propertyId,
                $request->from,
                $request->to,
                $lines,
                $actorId,
                $this->clock->now(),
            );
            $this->audit->record(
                $actorId,
                'REVENUE_SETTLEMENT_GENERATED',
                'KyDoiSoat',
                (string) $settlement->id,
                $requestId,
                null,
                ['status' => 'DRAFT', 'from' => $request->from, 'to' => $request->to,
                    'bookingCount' => $settlement->bookingCount, 'netRevenue' => $settlement->netRevenue->decimal()],
                $clientIp,
            );
            return $settlement;
        });
    }

    public function finalize(
        int $id,
        int $organizationId,
        int $actorId,
        string $requestId,
        string $clientIp,
    ): RevenueSettlement {
        return $this->transactions->run(function () use ($id, $organizationId, $actorId, $requestId, $clientIp): RevenueSettlement {
            $current = $this->settlements->findForOrganization($id, $organizationId)
                ?? throw new DomainException('Không tìm thấy kỳ đối soát.');
            if ($current->status === 'FINALIZED') {
                return $current;
            }
            if ($this->settlements->hasFinalizedOverlap($current->propertyId, $current->from, $current->to, $id)) {
                throw new DomainException('Kỳ này giao với một kỳ đã chốt khác.');
            }
            $settlement = $this->settlements->finalize($id, $organizationId, $actorId, $this->clock->now());
            $this->audit->record(
                $actorId,
                'REVENUE_SETTLEMENT_FINALIZED',
                'KyDoiSoat',
                (string) $id,
                $requestId,
                ['status' => 'DRAFT'],
                ['status' => 'FINALIZED', 'bookingCount' => $settlement->bookingCount,
                    'netRevenue' => $settlement->netRevenue->decimal()],
                $clientIp,
            );
            return $settlement;
        });
    }

    /** @return list<RevenueSettlement> */
    public function list(int $organizationId): array
    {
        return $this->settlements->listForOrganization($organizationId);
    }

    /** @return list<array{id:int, name:string}> */
    public function properties(int $organizationId): array
    {
        return $this->settlements->properties($organizationId);
    }

    public function get(int $id, int $organizationId): RevenueSettlement
    {
        return $this->settlements->findForOrganization($id, $organizationId)
            ?? throw new DomainException('Không tìm thấy kỳ đối soát.');
    }
}

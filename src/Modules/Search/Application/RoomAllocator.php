<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Shared\Contract\AllocationResult;

interface RoomAllocator
{
    /**
     * @param list<\App\Shared\Contract\RoomParty> $parties
     * @param list<AllocationCandidate> $candidates
     * @param array<int, int> $availability
     * @return list<AllocationResult>
     */
    public function allocate(
        array $parties,
        array $candidates,
        array $availability,
        ?string $paymentTiming = null,
    ): array;
}

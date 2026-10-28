<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Commercial;

use App\Modules\Commercial\Application\CancellationPolicyData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestCase;

final class CancellationPolicyDataTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, string}> */
    public static function normalizedPenalties(): iterable
    {
        yield 'không phạt' => ['NONE', '77', '0.00'];
        yield 'phần trăm' => ['PERCENT', '25.5', '25.50'];
        yield 'đêm đầu' => ['FIRST_NIGHT', '77', '1.00'];
        yield 'toàn kỳ' => ['FULL_STAY', '77', '100.00'];
    }

    #[DataProvider('normalizedPenalties')]
    public function test_normalizes_penalty_value_by_type(
        string $penaltyType,
        mixed $inputValue,
        string $expected,
    ): void {
        $data = CancellationPolicyData::fromArray([
            'code' => 'flex_1d',
            'name' => 'Linh hoạt một ngày',
            'policyType' => 'flexible',
            'freeCancellationHours' => '24',
            'penaltyType' => $penaltyType,
            'penaltyValue' => $inputValue,
            'noShowPenaltyRate' => '80.5',
            'status' => 'active',
        ])->persistenceData();

        self::assertSame('FLEX_1D', $data['code']);
        self::assertSame($expected, $data['penaltyValue']);
        self::assertSame('80.50', $data['noShowPenaltyRate']);
        self::assertSame('ACTIVE', $data['status']);
    }

    public function test_non_refundable_policy_removes_free_cancellation_window(): void
    {
        $data = CancellationPolicyData::fromArray([
            'code' => 'NON_REF', 'name' => 'Không hoàn tiền',
            'policyType' => 'NON_REFUNDABLE', 'freeCancellationHours' => '48',
            'penaltyType' => 'FULL_STAY', 'penaltyValue' => '5',
            'noShowPenaltyRate' => '100',
        ]);

        self::assertNull($data->freeCancellationHours);
        self::assertSame('100.00', $data->penaltyValue);
    }

    public function test_rejects_percentage_above_one_hundred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CancellationPolicyData::fromArray([
            'code' => 'FLEX', 'name' => 'Linh hoạt', 'policyType' => 'FLEXIBLE',
            'penaltyType' => 'PERCENT', 'penaltyValue' => '100.01',
            'noShowPenaltyRate' => '100',
        ]);
    }
}

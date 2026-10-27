<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use PDO;
use Tests\Support\TestCase;

final class PropertyReadinessRepositoryTest extends TestCase
{
    public function test_reads_commercial_readiness_facts_without_cross_property_links(): void
    {
        $pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $propertyId = (int) $pdo->query('SELECT CoSoLuuTruID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1')->fetchColumn();
        $repository = new PropertyReadinessRepository($pdo);

        self::assertTrue($repository->isOrganizationActive($propertyId));
        self::assertNotEmpty($repository->activeRooms($propertyId));
        $facts = $repository->commercialFacts($propertyId);
        self::assertArrayHasKey('hasCancellationPolicy', $facts);
        self::assertArrayHasKey('priceDays', $facts);
        self::assertFalse($facts['hasCrossPropertyRelation']);
    }
}

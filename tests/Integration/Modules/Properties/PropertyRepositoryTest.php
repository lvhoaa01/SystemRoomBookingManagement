<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Properties\Domain\PropertyStatus;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use InvalidArgumentException;
use PDO;
use Tests\Support\TestCase;

final class PropertyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PropertyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->repository = new PropertyRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_partner_queries_are_always_scoped_to_organization(): void
    {
        $property = $this->pdo->query(
            'SELECT CoSoLuuTruID, ToChucDoiTacID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($property);

        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $property['ToChucDoiTacID'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        self::assertNotNull($this->repository->findForOrganization(
            (int) $property['CoSoLuuTruID'],
            (int) $property['ToChucDoiTacID'],
        ));
        self::assertNull($this->repository->findForOrganization(
            (int) $property['CoSoLuuTruID'],
            $otherOrganizationId,
        ));

        foreach ($this->repository->listForOrganization($otherOrganizationId) as $listed) {
            self::assertSame($otherOrganizationId, (int) $listed['organizationId']);
        }
    }

    public function test_transition_uses_expected_status_as_optimistic_lock(): void
    {
        $propertyId = (int) $this->pdo->query(
            "SELECT CoSoLuuTruID FROM CoSoLuuTru WHERE TrangThai = 'DRAFT' ORDER BY CoSoLuuTruID LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $propertyId);

        self::assertFalse($this->repository->transition(
            $propertyId,
            PropertyStatus::PENDING_REVIEW,
            PropertyStatus::ACTIVE,
            1,
            null,
        ));
        self::assertTrue($this->repository->transition(
            $propertyId,
            PropertyStatus::DRAFT,
            PropertyStatus::PENDING_REVIEW,
            null,
            null,
        ));
    }

    public function test_transition_rejects_undocumented_workflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->transition(
            1,
            PropertyStatus::DRAFT,
            PropertyStatus::ACTIVE,
            1,
            null,
        );
    }

    public function test_partner_update_cannot_mutate_property_from_another_organization(): void
    {
        $property = $this->repository->findById(1);
        self::assertNotNull($property);
        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $property['organizationId'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        $updated = $this->repository->updateForOrganization(1, $otherOrganizationId, [
            'type' => $property['type'], 'name' => 'Tên không được phép',
            'sourceName' => $property['sourceName'], 'nameSource' => $property['nameSource'],
            'sourceRecordCode' => $property['sourceRecordCode'], 'slug' => $property['slug'],
            'address' => $property['address'], 'addressSource' => $property['addressSource'],
            'addressVerifiedAt' => $property['addressVerifiedAt'], 'description' => $property['description'],
            'imagesJson' => $property['imagesJson'], 'latitude' => $property['latitude'],
            'longitude' => $property['longitude'], 'coordinatesSource' => $property['coordinatesSource'],
            'coordinatesVerifiedAt' => $property['coordinatesVerifiedAt'],
            'reconciliationStatus' => $property['reconciliationStatus'], 'mapUrl' => $property['mapUrl'],
            'starRating' => $property['starRating'], 'taxRate' => $property['taxRate'],
            'taxIncluded' => $property['taxIncluded'], 'serviceFeeRate' => $property['serviceFeeRate'],
            'serviceFeeIncluded' => $property['serviceFeeIncluded'], 'checkInTime' => $property['checkInTime'],
            'checkOutTime' => $property['checkOutTime'], 'timezone' => $property['timezone'],
            'status' => $property['status'], 'reviewerId' => $property['reviewerId'],
            'reviewedAt' => $property['reviewedAt'], 'reviewNote' => $property['reviewNote'],
        ]);

        self::assertFalse($updated);
        self::assertSame($property['name'], $this->repository->findById(1)['name']);
    }
}

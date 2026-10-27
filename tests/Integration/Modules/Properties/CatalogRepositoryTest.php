<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Properties;

use App\Bootstrap\App;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use PDO;
use Tests\Support\TestCase;

final class CatalogRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RoomTypeRepository $rooms;
    private FacilityRepository $facilities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->rooms = new RoomTypeRepository($this->pdo);
        $this->facilities = new FacilityRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_room_reads_and_writes_cannot_cross_organization_boundary(): void
    {
        $room = $this->pdo->query(
            'SELECT lp.LoaiPhongID, lp.CoSoLuuTruID, cs.ToChucDoiTacID
             FROM LoaiPhong lp INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             ORDER BY lp.LoaiPhongID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($room);
        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $room['ToChucDoiTacID'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        self::assertNull($this->rooms->findForOrganization((int) $room['LoaiPhongID'], $otherOrganizationId));
        self::assertSame([], $this->rooms->listForProperty((int) $room['CoSoLuuTruID'], $otherOrganizationId));
        self::assertNull($this->rooms->create((int) $room['CoSoLuuTruID'], $otherOrganizationId, $this->roomData()));
    }

    public function test_facility_repository_enforces_scope_and_tenant(): void
    {
        $property = $this->pdo->query(
            'SELECT CoSoLuuTruID, ToChucDoiTacID FROM CoSoLuuTru ORDER BY CoSoLuuTruID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($property);
        $otherOrganizationId = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID <> '
            . (int) $property['ToChucDoiTacID'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        self::assertNotEmpty($this->facilities->listActiveCatalog(FacilityRepository::SCOPE_PROPERTY));
        self::assertNotEmpty($this->facilities->listActiveCatalog(FacilityRepository::SCOPE_ROOM));
        self::assertFalse($this->facilities->replaceForProperty(
            (int) $property['CoSoLuuTruID'],
            $otherOrganizationId,
            [],
        ));
    }

    /** @return array<string, mixed> */
    private function roomData(): array
    {
        return [
            'code' => 'TEST', 'name' => 'Phòng kiểm thử', 'description' => null,
            'maxGuests' => 2, 'maxAdults' => 2, 'maxChildren' => 0,
            'bedsJson' => '[{"quantity":1,"type":"DOUBLE"}]',
            'areaM2' => '20.00', 'smokingAllowed' => 0,
            'imagesJson' => '[{"alt":"Ảnh","cover":true,"path":"/uploads/properties/test.webp","sort":1}]',
            'status' => 'ACTIVE',
        ];
    }
}

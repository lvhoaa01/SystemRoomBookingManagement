<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use App\Bootstrap\App;
use App\Modules\Admin\Application\AdminCatalogService;
use App\Modules\Admin\Infrastructure\AdminCatalogRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use InvalidArgumentException;
use PDO;
use Tests\Support\TestCase;

final class AdminCatalogServiceTest extends TestCase
{
    private PDO $pdo;
    private AdminCatalogService $service;
    /** @var list<string> */
    private array $requestIds = [];
    /** @var list<int> */
    private array $facilityIds = [];
    /** @var array<int, string> */
    private array $accountStatuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->service = new AdminCatalogService(
            new AdminCatalogRepository($this->pdo),
            new TransactionManager($this->pdo, static fn (int $_): null => null),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->accountStatuses as $id => $status) {
            $this->pdo->prepare('UPDATE NguoiDung SET TrangThai=? WHERE NguoiDungID=?')->execute([$status, $id]);
        }
        foreach ($this->requestIds as $requestId) {
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$requestId]);
        }
        foreach ($this->facilityIds as $id) {
            $this->pdo->prepare('DELETE FROM TienNghi WHERE TienNghiID=?')->execute([$id]);
        }
        parent::tearDown();
    }

    public function test_admin_locks_account_idempotently_but_cannot_lock_self(): void
    {
        $account = $this->pdo->query(
            "SELECT NguoiDungID id, MaNguoiDung code, TrangThai status FROM NguoiDung
             WHERE VaiTro='CUSTOMER' AND TrangThai='HOAT_DONG' ORDER BY NguoiDungID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($account);
        $id = (int) $account['id'];
        $this->accountStatuses[$id] = (string) $account['status'];
        $requestId = $this->requestId('account');

        $this->service->changeAccountStatus($id, 'KHOA', 1, $requestId, '127.0.0.1');
        $this->service->changeAccountStatus($id, 'KHOA', 1, $requestId, '127.0.0.1');

        self::assertSame('KHOA', $this->scalar('SELECT TrangThai FROM NguoiDung WHERE NguoiDungID=' . $id));
        self::assertSame('1', $this->scalar("SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='{$requestId}'"));
        $page = $this->service->accounts(1, 'CUSTOMER', 'KHOA', (string) $account['code']);
        self::assertGreaterThanOrEqual(1, $page->total);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không được tự khóa');
        $this->service->changeAccountStatus(1, 'KHOA', 1, $this->requestId('self'), '127.0.0.1');
    }

    public function test_admin_creates_and_updates_facility_with_payload_safe_replay(): void
    {
        $createId = $this->requestId('facility-create');
        $code = 'TEST_' . strtoupper(bin2hex(random_bytes(4)));
        $payload = ['code' => $code, 'name' => 'Tiện nghi kiểm thử', 'scope' => 'PROPERTY', 'status' => 'HOAT_DONG'];
        $id = $this->service->createFacility($payload, 1, $createId, '127.0.0.1');
        $this->facilityIds[] = $id;
        self::assertSame($id, $this->service->createFacility($payload, 1, $createId, '127.0.0.1'));

        $updateId = $this->requestId('facility-update');
        $updated = ['name' => 'Tiện nghi đã ngừng', 'status' => 'NGUNG'];
        $this->service->updateFacility($id, $updated, 1, $updateId, '127.0.0.1');
        $this->service->updateFacility($id, $updated, 1, $updateId, '127.0.0.1');

        $row = array_values(array_filter(
            $this->service->facilities('PROPERTY', 'NGUNG'),
            static fn (array $facility): bool => (int) $facility['id'] === $id,
        ));
        self::assertCount(1, $row);
        self::assertSame('Tiện nghi đã ngừng', $row[0]['name']);
        self::assertSame('0', (string) $row[0]['usageCount']);

        try {
            $this->service->updateFacility($id, ['name' => 'Payload khác', 'status' => 'NGUNG'], 1, $updateId, '127.0.0.1');
            self::fail('Replay khác payload phải bị từ chối.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('RequestID', $exception->getMessage());
        }
    }

    private function requestId(string $suffix): string
    {
        $id = 'admin-' . $suffix . '-' . bin2hex(random_bytes(5));
        $this->requestIds[] = $id;
        return $id;
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}

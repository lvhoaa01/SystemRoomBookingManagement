<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Admin;

use App\Bootstrap\App;
use App\Modules\Admin\Application\AuditLogService;
use App\Modules\Admin\Infrastructure\AuditLogRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use InvalidArgumentException;
use PDO;
use Tests\Support\TestCase;

final class AuditLogServiceTest extends TestCase
{
    private PDO $pdo;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->requestId = 'audit-view-' . bin2hex(random_bytes(6));
        (new DatabaseAuditTrail($this->pdo))->record(
            1,
            'AUDIT_MASK_TEST',
            'DatCho',
            'test-15',
            $this->requestId,
            ['bookerEmail' => 'before@example.test', 'bookerName' => 'Nguyễn Văn A'],
            ['contact' => ['email' => 'after@example.test', 'phone' => '0901234567'], 'status' => 'CONFIRMED'],
            '192.168.10.25',
        );
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$this->requestId]);
        parent::tearDown();
    }

    public function test_audit_page_masks_pii_and_supports_allow_list_filters(): void
    {
        $service = new AuditLogService(new AuditLogRepository($this->pdo));
        $page = $service->page(1, 'AUDIT_MASK_TEST', 'DatCho', $this->requestId);

        self::assertSame(1, $page->total);
        self::assertCount(1, $page->items);
        $item = $page->items[0];
        self::assertSame('b***@example.test', $item['before']['bookerEmail']);
        self::assertSame('[ĐÃ CHE]', $item['before']['bookerName']);
        self::assertSame('a***@example.test', $item['after']['contact']['email']);
        self::assertSame('*******567', $item['after']['contact']['phone']);
        self::assertSame('192.168.10.xxx', $item['clientIp']);
        self::assertSame('CONFIRMED', $item['after']['status']);
        self::assertContains('AUDIT_MASK_TEST', $service->filterOptions()['actions']);
    }

    public function test_audit_writer_rejects_non_standard_action_and_long_request_id(): void
    {
        $audit = new DatabaseAuditTrail($this->pdo);
        try {
            $audit->record(1, 'bad action', 'DatCho', '1', 'valid-request-id', null, null, '127.0.0.1');
            self::fail('Action sai quy ước phải bị từ chối.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('UPPER_SNAKE_CASE', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RequestID audit');
        $audit->record(1, 'VALID_ACTION', 'DatCho', '1', str_repeat('a', 81), null, null, '127.0.0.1');
    }
}

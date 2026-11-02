<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Infrastructure\AdminCatalogRepository;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Database\TransactionManager;
use InvalidArgumentException;
use PDOException;

final readonly class AdminCatalogService
{
    private const FACILITY_STATUSES = ['HOAT_DONG', 'NGUNG'];

    public function __construct(
        private AdminCatalogRepository $catalog,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function accounts(int $page, ?string $role, ?string $status, ?string $query): AdminPage
    {
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('Số trang tài khoản không hợp lệ.');
        }
        $role = $this->optionalEnum($role, UserAccount::roles(), 'Vai trò tài khoản');
        $status = $this->optionalEnum($status, UserAccount::statuses(), 'Trạng thái tài khoản');
        $query = $this->query($query);
        $result = $this->catalog->paginateAccounts($page, 25, $role, $status, $query);
        return new AdminPage($result['items'], $page, 25, $result['total']);
    }

    public function changeAccountStatus(
        int $userId,
        string $status,
        int $actorId,
        string $requestId,
        string $clientIp,
    ): void {
        $status = $this->enum($status, UserAccount::statuses(), 'Trạng thái tài khoản');
        $this->ids($userId, $actorId);
        if ($userId === $actorId) {
            throw new InvalidArgumentException('Admin không được tự khóa tài khoản đang sử dụng.');
        }
        $requestId = $this->requestId($requestId);
        $this->transactions->run(function () use ($userId, $status, $actorId, $requestId, $clientIp): void {
            $account = $this->catalog->lockAccount($userId)
                ?? throw new InvalidArgumentException('Không tìm thấy tài khoản.');
            if ($this->replay($requestId, 'ACCOUNT_STATUS_CHANGED', (string) $userId, $actorId, ['status' => $status])) {
                return;
            }
            $this->catalog->updateAccountStatus($userId, $status);
            $this->audit->record($actorId, 'ACCOUNT_STATUS_CHANGED', 'NguoiDung', (string) $userId, $requestId,
                ['status' => $account['status'], 'role' => $account['role']],
                ['status' => $status, 'role' => $account['role']], $clientIp);
        });
    }

    /** @return list<array<string, mixed>> */
    public function facilities(?string $scope, ?string $status): array
    {
        $scope = $this->optionalEnum($scope, [FacilityRepository::SCOPE_PROPERTY, FacilityRepository::SCOPE_ROOM], 'Phạm vi tiện nghi');
        $status = $this->optionalEnum($status, self::FACILITY_STATUSES, 'Trạng thái tiện nghi');
        return $this->catalog->facilities($scope, $status);
    }

    /** @param array<string, mixed> $input */
    public function createFacility(array $input, int $actorId, string $requestId, string $clientIp): int
    {
        [$code, $name, $scope, $status] = $this->facilityInput($input, true);
        $this->ids($actorId);
        $requestId = $this->requestId($requestId);
        try {
            return $this->transactions->run(function () use ($code, $name, $scope, $status, $actorId, $requestId, $clientIp): int {
                $existing = $this->audit->findByRequestId($requestId);
                if ($existing !== null) {
                    if (($existing['action'] ?? null) !== 'FACILITY_CREATED' || (int) ($existing['actorId'] ?? 0) !== $actorId
                        || !$this->matchesAfter($existing['after'] ?? null, [
                            'code' => $code, 'name' => $name, 'scope' => $scope, 'status' => $status,
                        ])) {
                        throw new InvalidArgumentException('RequestID đã được dùng cho thao tác khác.');
                    }
                    return (int) $existing['entityId'];
                }
                $id = $this->catalog->createFacility($code, $name, $scope, $status);
                $this->audit->record($actorId, 'FACILITY_CREATED', 'TienNghi', (string) $id, $requestId, null,
                    ['code' => $code, 'name' => $name, 'scope' => $scope, 'status' => $status], $clientIp);
                return $id;
            });
        } catch (PDOException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                throw new InvalidArgumentException('Mã tiện nghi đã tồn tại.', 0, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function updateFacility(int $facilityId, array $input, int $actorId, string $requestId, string $clientIp): void
    {
        [, $name, , $status] = $this->facilityInput($input, false);
        $this->ids($facilityId, $actorId);
        $requestId = $this->requestId($requestId);
        $this->transactions->run(function () use ($facilityId, $name, $status, $actorId, $requestId, $clientIp): void {
            $facility = $this->catalog->lockFacility($facilityId)
                ?? throw new InvalidArgumentException('Không tìm thấy tiện nghi.');
            if ($this->replay($requestId, 'FACILITY_UPDATED', (string) $facilityId, $actorId,
                ['name' => $name, 'status' => $status])) {
                return;
            }
            $this->catalog->updateFacility($facilityId, $name, $status);
            $this->audit->record($actorId, 'FACILITY_UPDATED', 'TienNghi', (string) $facilityId, $requestId,
                ['name' => $facility['name'], 'status' => $facility['status']],
                ['name' => $name, 'status' => $status], $clientIp);
        });
    }

    /** @param array<string, mixed> $input @return array{string,string,string,string} */
    private function facilityInput(array $input, bool $creating): array
    {
        $code = strtoupper(trim(is_string($input['code'] ?? null) ? $input['code'] : ''));
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $scope = strtoupper(trim(is_string($input['scope'] ?? null) ? $input['scope'] : ''));
        $status = strtoupper(trim(is_string($input['status'] ?? null) ? $input['status'] : ''));
        if ($creating && preg_match('/^[A-Z0-9_]{2,40}$/', $code) !== 1) {
            throw new InvalidArgumentException('Mã tiện nghi phải dài 2–40 ký tự A-Z, 0-9 hoặc dấu gạch dưới.');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Tên tiện nghi phải dài 2–120 ký tự.');
        }
        if ($creating) {
            $scope = $this->enum($scope, [FacilityRepository::SCOPE_PROPERTY, FacilityRepository::SCOPE_ROOM], 'Phạm vi tiện nghi');
        }
        $status = $this->enum($status, self::FACILITY_STATUSES, 'Trạng thái tiện nghi');
        return [$code, $name, $scope, $status];
    }

    /** @param array<string, scalar|null> $expectedAfter */
    private function replay(string $requestId, string $action, string $entityId, int $actorId, array $expectedAfter): bool
    {
        $existing = $this->audit->findByRequestId($requestId);
        if ($existing === null) {
            return false;
        }
        if (($existing['action'] ?? null) !== $action || ($existing['entityId'] ?? null) !== $entityId
            || (int) ($existing['actorId'] ?? 0) !== $actorId
            || !$this->matchesAfter($existing['after'] ?? null, $expectedAfter)) {
            throw new InvalidArgumentException('RequestID đã được dùng cho thao tác khác.');
        }
        return true;
    }

    /** @param array<string, scalar|null> $expected */
    private function matchesAfter(mixed $after, array $expected): bool
    {
        if (!is_array($after)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (($after[$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $allowed */
    private function optionalEnum(?string $value, array $allowed, string $label): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' ? null : $this->enum($value, $allowed, $label);
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed, string $label): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
        return $value;
    }

    private function query(?string $query): ?string
    {
        $query = trim((string) $query);
        if (mb_strlen($query) > 190) {
            throw new InvalidArgumentException('Từ khóa tài khoản không được vượt quá 190 ký tự.');
        }
        return $query === '' ? null : $query;
    }

    private function ids(int ...$ids): void
    {
        if ($ids === [] || min($ids) < 1) {
            throw new InvalidArgumentException('ID quản trị không hợp lệ.');
        }
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $requestId) !== 1) {
            throw new InvalidArgumentException('RequestID quản trị không hợp lệ.');
        }
        return $requestId;
    }
}

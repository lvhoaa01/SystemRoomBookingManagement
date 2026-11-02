<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use PDO;
use RuntimeException;

final readonly class AdminCatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginateAccounts(
        int $page,
        int $perPage,
        ?string $role,
        ?string $status,
        ?string $query,
    ): array {
        $where = [];
        $params = [];
        if ($role !== null) {
            $where[] = 'nd.VaiTro=:role';
            $params['role'] = $role;
        }
        if ($status !== null) {
            $where[] = 'nd.TrangThai=:status';
            $params['status'] = $status;
        }
        if ($query !== null) {
            $where[] = '(nd.MaNguoiDung LIKE :queryCode OR nd.HoTen LIKE :queryName OR nd.Email LIKE :queryEmail)';
            $params['queryCode'] = '%' . $query . '%';
            $params['queryName'] = '%' . $query . '%';
            $params['queryEmail'] = '%' . $query . '%';
        }
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM NguoiDung nd' . $filter);
        $count->execute($params);

        $offset = ($page - 1) * $perPage;
        $statement = $this->pdo->prepare(
            'SELECT nd.NguoiDungID AS id, nd.MaNguoiDung AS code, nd.HoTen AS fullName,
                    nd.Email AS email, nd.SoDienThoai AS phone, nd.VaiTro AS role,
                    nd.TrangThai AS status, nd.TaoLuc AS createdAt, nd.CapNhatLuc AS updatedAt,
                    tc.ToChucDoiTacID AS organizationId, tc.TenToChuc AS organizationName
             FROM NguoiDung nd
             LEFT JOIN ThanhVienDoiTac tv ON tv.NguoiDungID=nd.NguoiDungID
             LEFT JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID=tv.ToChucDoiTacID'
            . $filter . ' ORDER BY nd.NguoiDungID DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);
        return ['items' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function lockAccount(int $userId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT NguoiDungID AS id, MaNguoiDung AS code, VaiTro AS role, TrangThai AS status
             FROM NguoiDung WHERE NguoiDungID=? FOR UPDATE'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function updateAccountStatus(int $userId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE NguoiDung SET TrangThai=? WHERE NguoiDungID=?');
        $statement->execute([$status, $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function facilities(?string $scope, ?string $status): array
    {
        $where = [];
        $params = [];
        if ($scope !== null) {
            $where[] = 'tn.PhamVi=:scope';
            $params['scope'] = $scope;
        }
        if ($status !== null) {
            $where[] = 'tn.TrangThai=:status';
            $params['status'] = $status;
        }
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $statement = $this->pdo->prepare(
            'SELECT tn.TienNghiID AS id, tn.MaTienNghi AS code, tn.TenTienNghi AS name,
                    tn.PhamVi AS scope, tn.TrangThai AS status,
                    (SELECT COUNT(*) FROM CoSoTienNghi cstn WHERE cstn.TienNghiID=tn.TienNghiID)
                        + (SELECT COUNT(*) FROM LoaiPhongTienNghi lptn WHERE lptn.TienNghiID=tn.TienNghiID) AS usageCount
             FROM TienNghi tn' . $filter . ' ORDER BY tn.PhamVi, tn.TenTienNghi, tn.TienNghiID'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function lockFacility(int $facilityId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT TienNghiID AS id, MaTienNghi AS code, TenTienNghi AS name,
                    PhamVi AS scope, TrangThai AS status
             FROM TienNghi WHERE TienNghiID=? FOR UPDATE'
        );
        $statement->execute([$facilityId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createFacility(string $code, string $name, string $scope, string $status): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO TienNghi (MaTienNghi,TenTienNghi,PhamVi,TrangThai) VALUES (?,?,?,?)'
        );
        $statement->execute([$code, $name, $scope, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateFacility(int $facilityId, string $name, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE TienNghi SET TenTienNghi=:name, TrangThai=:status WHERE TienNghiID=:id'
        );
        $statement->execute(['name' => $name, 'status' => $status, 'id' => $facilityId]);
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Dữ liệu quản trị chỉ được khóa trong transaction.');
        }
    }
}

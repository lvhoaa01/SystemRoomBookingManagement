<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure;

use PDO;

final readonly class AuditLogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(
        int $page,
        int $perPage,
        ?string $action,
        ?string $entityType,
        ?string $query,
    ): array {
        $where = [];
        $params = [];
        if ($action !== null) {
            $where[] = 'nk.HanhDong=:action';
            $params['action'] = $action;
        }
        if ($entityType !== null) {
            $where[] = 'nk.LoaiDoiTu=:entityType';
            $params['entityType'] = $entityType;
        }
        if ($query !== null) {
            $where[] = '(nk.RequestID LIKE :requestQuery OR nk.DoiTuID LIKE :entityQuery OR nd.MaNguoiDung LIKE :actorQuery)';
            $params['requestQuery'] = '%' . $query . '%';
            $params['entityQuery'] = '%' . $query . '%';
            $params['actorQuery'] = '%' . $query . '%';
        }
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM NhatKyHeThong nk LEFT JOIN NguoiDung nd ON nd.NguoiDungID=nk.NguoiThucHienID';
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from . $filter);
        $count->execute($params);

        $offset = ($page - 1) * $perPage;
        $statement = $this->pdo->prepare(
            'SELECT nk.NhatKyHeThongID AS id, nk.NguoiThucHienID AS actorId,
                    nd.MaNguoiDung AS actorCode, nd.HoTen AS actorName,
                    nk.HanhDong AS action, nk.LoaiDoiTu AS entityType,
                    nk.DoiTuID AS entityId, nk.RequestID AS requestId,
                    nk.DuLieuTruocJSON AS beforeJson, nk.DuLieuSauJSON AS afterJson,
                    nk.DiaChiIP AS clientIp, nk.TaoLuc AS createdAt'
            . $from . $filter . ' ORDER BY nk.NhatKyHeThongID DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);
        return ['items' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /** @return array{actions: list<string>, entityTypes: list<string>} */
    public function filterOptions(): array
    {
        $actions = $this->pdo->query('SELECT DISTINCT HanhDong FROM NhatKyHeThong ORDER BY HanhDong')
            ->fetchAll(PDO::FETCH_COLUMN);
        $types = $this->pdo->query('SELECT DISTINCT LoaiDoiTu FROM NhatKyHeThong ORDER BY LoaiDoiTu')
            ->fetchAll(PDO::FETCH_COLUMN);
        return [
            'actions' => array_map('strval', $actions),
            'entityTypes' => array_map('strval', $types),
        ];
    }
}

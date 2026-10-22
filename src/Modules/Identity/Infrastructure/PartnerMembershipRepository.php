<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\OrganizationContext;
use InvalidArgumentException;
use PDO;

final readonly class PartnerMembershipRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findOrganizationForUser(int $userId): ?OrganizationContext
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ID người dùng phải là số dương.');
        }

        $statement = $this->pdo->prepare(
            'SELECT tc.ToChucDoiTacID, tc.MaToChuc, tc.TenToChuc, tc.TrangThai, tv.LaChuSoHuu
             FROM ThanhVienDoiTac tv
             INNER JOIN ToChucDoiTac tc ON tc.ToChucDoiTacID = tv.ToChucDoiTacID
             WHERE tv.NguoiDungID = :userId
             LIMIT 1'
        );
        $statement->execute(['userId' => $userId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return new OrganizationContext(
            (int) $row['ToChucDoiTacID'],
            (string) $row['MaToChuc'],
            (string) $row['TenToChuc'],
            (string) $row['TrangThai'],
            (bool) $row['LaChuSoHuu'],
        );
    }
}

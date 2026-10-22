<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\UserAccount;
use InvalidArgumentException;
use PDO;

final readonly class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmailOrCode(string $identifier): ?UserAccount
    {
        $statement = $this->pdo->prepare(
            'SELECT NguoiDungID, MaNguoiDung, HoTen, Email, SoDienThoai, MatKhauHash, VaiTro, TrangThai
             FROM NguoiDung
             WHERE Email = :email OR MaNguoiDung = :code
             LIMIT 1'
        );
        $statement->execute([
            'email' => $identifier,
            'code' => $identifier,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $userId): ?UserAccount
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ID người dùng phải là số dương.');
        }

        $statement = $this->pdo->prepare(
            'SELECT NguoiDungID, MaNguoiDung, HoTen, Email, SoDienThoai, MatKhauHash, VaiTro, TrangThai
             FROM NguoiDung
             WHERE NguoiDungID = :userId'
        );
        $statement->execute(['userId' => $userId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM NguoiDung WHERE Email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        return $statement->fetchColumn() !== false;
    }

    public function createCustomer(
        string $code,
        string $fullName,
        string $email,
        ?string $phone,
        string $passwordHash,
    ): UserAccount {
        $statement = $this->pdo->prepare(
            "INSERT INTO NguoiDung (MaNguoiDung, HoTen, Email, SoDienThoai, MatKhauHash, VaiTro, TrangThai)
             VALUES (:code, :fullName, :email, :phone, :passwordHash, 'CUSTOMER', 'HOAT_DONG')"
        );
        $statement->execute([
            'code' => $code,
            'fullName' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'passwordHash' => $passwordHash,
        ]);

        $account = $this->findById((int) $this->pdo->lastInsertId());
        if ($account === null) {
            throw new \RuntimeException('Không thể đọc lại tài khoản vừa tạo.');
        }
        return $account;
    }

    public function updateStatus(int $userId, string $status): void
    {
        if (!in_array($status, UserAccount::statuses(), true)) {
            throw new InvalidArgumentException('Trạng thái tài khoản không hợp lệ.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE NguoiDung SET TrangThai = :status WHERE NguoiDungID = :userId'
        );
        $statement->execute([
            'status' => $status,
            'userId' => $userId,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): UserAccount
    {
        return new UserAccount(
            (int) $row['NguoiDungID'],
            (string) $row['MaNguoiDung'],
            (string) $row['HoTen'],
            (string) $row['Email'],
            $row['SoDienThoai'] === null ? null : (string) $row['SoDienThoai'],
            (string) $row['MatKhauHash'],
            (string) $row['VaiTro'],
            (string) $row['TrangThai'],
        );
    }
}

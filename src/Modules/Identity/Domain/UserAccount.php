<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

final readonly class UserAccount
{
    public const ROLE_CUSTOMER = 'CUSTOMER';
    public const ROLE_PARTNER = 'PARTNER';
    public const ROLE_ADMIN = 'ADMIN';

    public const STATUS_ACTIVE = 'HOAT_DONG';
    public const STATUS_LOCKED = 'KHOA';

    public function __construct(
        public int $id,
        public string $code,
        public string $fullName,
        public string $email,
        public ?string $phone,
        private string $passwordHash,
        public string $role,
        public string $status,
    ) {
        if ($this->id <= 0 || trim($this->code) === '' || trim($this->fullName) === '') {
            throw new InvalidArgumentException('Thông tin tài khoản không hợp lệ.');
        }
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email tài khoản không hợp lệ.');
        }
        if (!in_array($this->role, self::roles(), true)) {
            throw new InvalidArgumentException('Vai trò tài khoản không hợp lệ.');
        }
        if (!in_array($this->status, self::statuses(), true)) {
            throw new InvalidArgumentException('Trạng thái tài khoản không hợp lệ.');
        }
        if ($this->passwordHash === '') {
            throw new InvalidArgumentException('Tài khoản phải có mật khẩu đã băm.');
        }
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function toCurrentUser(): CurrentUser
    {
        return new CurrentUser(
            $this->id,
            $this->code,
            $this->fullName,
            $this->email,
            $this->role,
            $this->status,
        );
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_CUSTOMER, self::ROLE_PARTNER, self::ROLE_ADMIN];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_LOCKED];
    }
}

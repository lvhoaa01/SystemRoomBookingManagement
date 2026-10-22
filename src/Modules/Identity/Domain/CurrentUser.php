<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

final readonly class CurrentUser
{
    public function __construct(
        public int $id,
        public string $code,
        public string $fullName,
        public string $email,
        public string $role,
        public string $status,
    ) {
        if ($this->id <= 0 || trim($this->code) === '' || trim($this->fullName) === '') {
            throw new InvalidArgumentException('Danh tính người dùng không hợp lệ.');
        }
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email người dùng không hợp lệ.');
        }
        if (!in_array($this->role, UserAccount::roles(), true)) {
            throw new InvalidArgumentException('Vai trò người dùng không hợp lệ.');
        }
        if (!in_array($this->status, UserAccount::statuses(), true)) {
            throw new InvalidArgumentException('Trạng thái người dùng không hợp lệ.');
        }
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isActive(): bool
    {
        return $this->status === UserAccount::STATUS_ACTIVE;
    }
}

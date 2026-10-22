<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Bootstrap\SessionBootstrap;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Domain\UserAccount;
use InvalidArgumentException;

final readonly class IdentitySession
{
    private const USER_KEY = '_current_user';
    private const ORGANIZATION_KEY = '_organization_context';

    public function __construct(private SessionBootstrap $session)
    {
    }

    public function signIn(CurrentUser $user, ?OrganizationContext $organization): void
    {
        if (!$user->isActive()) {
            throw new InvalidArgumentException('Không thể tạo phiên cho tài khoản không hoạt động.');
        }
        if (!$user->hasRole(UserAccount::ROLE_PARTNER) && $organization !== null) {
            throw new InvalidArgumentException('Chỉ tài khoản đối tác mới có phạm vi tổ chức.');
        }

        $this->session->regenerateForAuthentication();
        $_SESSION[self::USER_KEY] = [
            'id' => $user->id,
            'code' => $user->code,
            'fullName' => $user->fullName,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];

        if ($organization === null) {
            unset($_SESSION[self::ORGANIZATION_KEY]);
            return;
        }
        $_SESSION[self::ORGANIZATION_KEY] = [
            'id' => $organization->id,
            'code' => $organization->code,
            'name' => $organization->name,
            'status' => $organization->status,
            'isOwner' => $organization->isOwner,
        ];
    }

    public function currentUser(): ?CurrentUser
    {
        $data = $_SESSION[self::USER_KEY] ?? null;
        if (!is_array($data)) {
            return null;
        }

        try {
            return new CurrentUser(
                $this->positiveInt($data['id'] ?? null),
                $this->string($data['code'] ?? null),
                $this->string($data['fullName'] ?? null),
                $this->string($data['email'] ?? null),
                $this->string($data['role'] ?? null),
                $this->string($data['status'] ?? null),
            );
        } catch (InvalidArgumentException) {
            unset($_SESSION[self::USER_KEY], $_SESSION[self::ORGANIZATION_KEY]);
            return null;
        }
    }

    public function organization(): ?OrganizationContext
    {
        $data = $_SESSION[self::ORGANIZATION_KEY] ?? null;
        if (!is_array($data)) {
            return null;
        }

        try {
            return new OrganizationContext(
                $this->positiveInt($data['id'] ?? null),
                $this->string($data['code'] ?? null),
                $this->string($data['name'] ?? null),
                $this->string($data['status'] ?? null),
                $this->boolean($data['isOwner'] ?? null),
            );
        } catch (InvalidArgumentException) {
            unset($_SESSION[self::ORGANIZATION_KEY]);
            return null;
        }
    }

    public function signOut(): void
    {
        $this->session->destroy();
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        throw new InvalidArgumentException('Giá trị session không phải ID hợp lệ.');
    }

    private function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Giá trị session không phải chuỗi.');
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Giá trị session không phải boolean.');
    }
}

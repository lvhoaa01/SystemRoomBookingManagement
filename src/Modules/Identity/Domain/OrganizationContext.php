<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

final readonly class OrganizationContext
{
    public const STATUS_ACTIVE = 'HOAT_DONG';
    public const STATUS_SUSPENDED = 'TAM_KHOA';

    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $status,
        public bool $isOwner,
    ) {
        if ($this->id <= 0 || trim($this->code) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Phạm vi tổ chức không hợp lệ.');
        }
        if (!in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
            throw new InvalidArgumentException('Trạng thái tổ chức không hợp lệ.');
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}

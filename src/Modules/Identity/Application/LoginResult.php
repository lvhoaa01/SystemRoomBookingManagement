<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Shared\Validation\ValidationResult;
use InvalidArgumentException;

final readonly class LoginResult
{
    private function __construct(
        public ?CurrentUser $user,
        public ?OrganizationContext $organization,
        public ValidationResult $validation,
        public int $retryAfterSeconds = 0,
    ) {
        if (($this->user === null) === $this->validation->isValid()) {
            throw new InvalidArgumentException('Kết quả đăng nhập không nhất quán.');
        }
        if ($this->retryAfterSeconds < 0 || ($this->user !== null && $this->retryAfterSeconds !== 0)) {
            throw new InvalidArgumentException('Thời gian chờ đăng nhập không nhất quán.');
        }
    }

    public static function success(CurrentUser $user, ?OrganizationContext $organization): self
    {
        return new self($user, $organization, ValidationResult::valid());
    }

    public static function failed(): self
    {
        return new self(null, null, ValidationResult::invalid([
            'credentials' => ['Thông tin đăng nhập không chính xác hoặc tài khoản không hoạt động.'],
        ]));
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(null, null, ValidationResult::invalid([
            'credentials' => ['Bạn đã thử đăng nhập quá nhiều lần. Vui lòng đợi một lúc rồi thử lại.'],
        ]), max(1, $retryAfterSeconds));
    }

    public function succeeded(): bool
    {
        return $this->user !== null;
    }
}

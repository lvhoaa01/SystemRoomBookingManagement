<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\CurrentUser;
use App\Shared\Validation\ValidationResult;
use InvalidArgumentException;

final readonly class RegisterCustomerResult
{
    private function __construct(
        public ?CurrentUser $user,
        public ValidationResult $validation,
    ) {
        if (($this->user === null) === $this->validation->isValid()) {
            throw new InvalidArgumentException('Kết quả đăng ký không nhất quán.');
        }
    }

    public static function success(CurrentUser $user): self
    {
        return new self($user, ValidationResult::valid());
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        return new self(null, ValidationResult::invalid($errors));
    }

    public function succeeded(): bool
    {
        return $this->user !== null;
    }
}

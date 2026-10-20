<?php

declare(strict_types=1);

namespace App\Shared\Validation;

final readonly class ValidationResult
{
    /** @param array<string, list<string>> $errors */
    private function __construct(private array $errors)
    {
    }

    public static function valid(): self
    {
        return new self([]);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        $normalized = [];
        foreach ($errors as $field => $messages) {
            if (!is_string($field) || $field === '' || $messages === []) {
                throw new \InvalidArgumentException('Lỗi validation phải có field và message.');
            }
            foreach ($messages as $message) {
                if (!is_string($message) || trim($message) === '') {
                    throw new \InvalidArgumentException('Message validation không được để trống.');
                }
                $normalized[$field][] = $message;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('ValidationResult không hợp lệ phải có ít nhất một lỗi.');
        }

        return new self($normalized);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function merge(self $other): self
    {
        if ($this->isValid()) {
            return $other;
        }
        if ($other->isValid()) {
            return $this;
        }

        $errors = $this->errors;
        foreach ($other->errors as $field => $messages) {
            $errors[$field] = [...($errors[$field] ?? []), ...$messages];
        }

        return self::invalid($errors);
    }
}

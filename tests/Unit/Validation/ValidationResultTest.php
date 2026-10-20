<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Shared\Validation\ValidationResult;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class ValidationResultTest extends TestCase
{
    public function test_valid_result_has_no_errors(): void
    {
        $result = ValidationResult::valid();

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors());
    }

    public function test_invalid_result_exposes_errors_by_field(): void
    {
        $result = ValidationResult::invalid([
            'email' => ['Email không hợp lệ.'],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(['Email không hợp lệ.'], $result->errorsFor('email'));
        self::assertSame([], $result->errorsFor('password'));
    }

    public function test_merges_errors_without_mutating_original_results(): void
    {
        $first = ValidationResult::invalid(['email' => ['Email không hợp lệ.']]);
        $second = ValidationResult::invalid(['email' => ['Email đã tồn tại.'], 'name' => ['Tên bị thiếu.']]);

        $merged = $first->merge($second);

        self::assertSame(['Email không hợp lệ.'], $first->errorsFor('email'));
        self::assertSame(['Email không hợp lệ.', 'Email đã tồn tại.'], $merged->errorsFor('email'));
        self::assertSame(['Tên bị thiếu.'], $merged->errorsFor('name'));
    }

    public function test_invalid_result_requires_at_least_one_non_empty_message(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ValidationResult::invalid(['email' => []]);
    }
}

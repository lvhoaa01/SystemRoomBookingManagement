<?php

declare(strict_types=1);

namespace Tests\Unit\Json;

use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function test_sorts_object_keys_recursively_but_preserves_list_order(): void
    {
        $first = [
            'z' => ['b' => 2, 'a' => 1],
            'list' => [['y' => 2, 'x' => 1], 'second'],
        ];
        $second = [
            'list' => [['x' => 1, 'y' => 2], 'second'],
            'z' => ['a' => 1, 'b' => 2],
        ];

        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
        self::assertSame(CanonicalJson::hash($first), CanonicalJson::hash($second));
        self::assertStringContainsString('"list":[{"x":1,"y":2},"second"]', CanonicalJson::encode($first));
    }

    public function test_normalizes_json_serializable_value_objects(): void
    {
        self::assertSame(
            '{"amount":"1000.00","currency":"VND"}',
            CanonicalJson::encode(Money::vnd(1000)),
        );
    }

    public function test_rejects_float_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalJson::encode(['price' => 10.5]);
    }

    public function test_keeps_non_list_with_numeric_keys_as_json_object(): void
    {
        self::assertSame('{"0":"zero","2":"two"}', CanonicalJson::encode([2 => 'two', 0 => 'zero']));
    }
}

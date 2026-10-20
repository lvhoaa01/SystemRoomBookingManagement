<?php

declare(strict_types=1);

namespace App\Shared\Json;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;

final class CanonicalJson
{
    /** @throws JsonException */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @throws JsonException */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical JSON không chấp nhận float; hãy dùng chuỗi thập phân.');
        }

        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Canonical JSON chỉ hỗ trợ scalar, array và JsonSerializable.');
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $items = [];
        foreach ($value as $key => $item) {
            $items[(string) $key] = self::normalize($item);
        }
        ksort($items, SORT_STRING);

        $normalized = new \stdClass();
        foreach ($items as $key => $item) {
            $normalized->{$key} = $item;
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class FlashBag
{
    private const KEY = '_flash_messages';

    public function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][$type][] = $message;
    }

    /** @return list<string> */
    public function consume(string $type): array
    {
        $messages = $_SESSION[self::KEY][$type] ?? [];
        unset($_SESSION[self::KEY][$type]);

        return is_array($messages) ? array_values(array_map('strval', $messages)) : [];
    }

    /** @return array<string, list<string>> */
    public function consumeAll(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $type => $items) {
            if (is_string($type) && is_array($items)) {
                $normalized[$type] = array_values(array_map('strval', $items));
            }
        }
        return $normalized;
    }
}

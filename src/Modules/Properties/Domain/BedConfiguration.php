<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;

use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

final readonly class BedConfiguration implements JsonSerializable
{
    private const ALLOWED_TYPES = ['SINGLE', 'DOUBLE', 'KING', 'SOFA_BED'];

    /** @var list<array{type: string, quantity: int}> */
    private array $items;

    /** @param list<array{type: string, quantity: int}> $items */
    public function __construct(array $items)
    {
        if ($items === []) {
            throw new InvalidArgumentException('Cấu hình giường phải có ít nhất một loại giường.');
        }

        $seenTypes = [];
        foreach ($items as $item) {
            if (array_keys($item) !== ['type', 'quantity']) {
                throw new InvalidArgumentException('Mỗi loại giường phải có đúng type và quantity.');
            }

            if (!in_array($item['type'], self::ALLOWED_TYPES, true)) {
                throw new InvalidArgumentException('Loại giường không được hỗ trợ.');
            }

            if ($item['quantity'] < 1 || $item['quantity'] > 10) {
                throw new InvalidArgumentException('Số lượng mỗi loại giường phải từ 1 đến 10.');
            }

            if (isset($seenTypes[$item['type']])) {
                throw new InvalidArgumentException('Mỗi loại giường chỉ được xuất hiện một lần.');
            }

            $seenTypes[$item['type']] = true;
        }

        $this->items = array_values($items);
    }

    public static function fromJson(string $json): self
    {
        try {
            $items = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Cấu hình giường không phải JSON hợp lệ.', 0, $exception);
        }

        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Cấu hình giường phải là một danh sách JSON.');
        }

        /** @var list<array{type: string, quantity: int}> $items */
        return new self($items);
    }

    /** @return list<array{type: string, quantity: int}> */
    public function items(): array
    {
        return $this->items;
    }

    public function totalBeds(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->items);
    }

    /** @return list<array{type: string, quantity: int}> */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}

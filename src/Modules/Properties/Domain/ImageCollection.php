<?php

declare(strict_types=1);

namespace App\Modules\Properties\Domain;

use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

final readonly class ImageCollection implements JsonSerializable
{
    private const MAX_IMAGES = 12;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var list<array{path: string, alt: string, sort: int, cover: bool}> */
    private array $images;

    /** @param list<array{path: string, alt: string, sort: int, cover: bool}> $images */
    public function __construct(array $images)
    {
        if (count($images) > self::MAX_IMAGES) {
            throw new InvalidArgumentException('Mỗi danh sách chỉ được có tối đa 12 ảnh.');
        }

        $coverCount = 0;
        $sortOrders = [];
        $paths = [];

        foreach ($images as $image) {
            if (!is_array($image) || !self::hasExactKeys($image, ['path', 'alt', 'sort', 'cover'])
                || !is_string($image['path']) || !is_string($image['alt'])
                || !is_int($image['sort']) || !is_bool($image['cover'])) {
                throw new InvalidArgumentException('Mỗi ảnh phải có đúng path, alt, sort và cover.');
            }

            self::assertPath($image['path']);

            if (trim($image['alt']) === '' || mb_strlen($image['alt']) > 255) {
                throw new InvalidArgumentException('Mô tả ảnh phải có từ 1 đến 255 ký tự.');
            }

            if ($image['sort'] < 1 || isset($sortOrders[$image['sort']])) {
                throw new InvalidArgumentException('Thứ tự ảnh phải là số dương và không trùng nhau.');
            }

            if (isset($paths[$image['path']])) {
                throw new InvalidArgumentException('Đường dẫn ảnh không được trùng nhau.');
            }

            $sortOrders[$image['sort']] = true;
            $paths[$image['path']] = true;
            $coverCount += $image['cover'] ? 1 : 0;
        }

        if ($images !== [] && $coverCount !== 1) {
            throw new InvalidArgumentException('Danh sách ảnh phải có đúng một ảnh bìa.');
        }

        usort($images, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);
        $this->images = array_values($images);
    }

    public static function fromJson(string $json): self
    {
        try {
            $images = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Danh sách ảnh không phải JSON hợp lệ.', 0, $exception);
        }

        if (!is_array($images) || !array_is_list($images)) {
            throw new InvalidArgumentException('Danh sách ảnh phải là một mảng JSON.');
        }

        /** @var list<array{path: string, alt: string, sort: int, cover: bool}> $images */
        return new self($images);
    }

    public function isEmpty(): bool
    {
        return $this->images === [];
    }

    /** @return array{path: string, alt: string, sort: int, cover: bool}|null */
    public function cover(): ?array
    {
        foreach ($this->images as $image) {
            if ($image['cover']) {
                return $image;
            }
        }

        return null;
    }

    /** @return list<array{path: string, alt: string, sort: int, cover: bool}> */
    public function images(): array
    {
        return $this->images;
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->images);
    }

    /** @return list<array{path: string, alt: string, sort: int, cover: bool}> */
    public function jsonSerialize(): array
    {
        return $this->images;
    }

    private static function assertPath(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isRelativeWebPath = str_starts_with($path, '/')
            && !str_contains($path, '..')
            && !str_contains($path, '\\')
            && filter_var($path, FILTER_VALIDATE_URL) === false;

        if (!$isRelativeWebPath || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Đường dẫn ảnh phải là đường dẫn web tương đối đến tệp ảnh được hỗ trợ.');
        }
    }

    /** @param array<mixed> $item @param list<string> $expected */
    private static function hasExactKeys(array $item, array $expected): bool
    {
        $keys = array_keys($item);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}

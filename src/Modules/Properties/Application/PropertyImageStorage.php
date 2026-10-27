<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Shared\Security\TokenGenerator;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final readonly class PropertyImageStorage
{
    private const MAX_BYTES = 5_242_880;
    private const MAX_DIMENSION = 8_000;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var Closure(string): bool */
    private Closure $isUploadedFile;

    /** @var Closure(string, string): bool */
    private Closure $moveUploadedFile;

    public function __construct(
        private string $storageRoot,
        private TokenGenerator $tokens,
        ?callable $isUploadedFile = null,
        ?callable $moveUploadedFile = null,
    ) {
        $this->isUploadedFile = $isUploadedFile === null
            ? is_uploaded_file(...)
            : Closure::fromCallable($isUploadedFile);
        $this->moveUploadedFile = $moveUploadedFile === null
            ? move_uploaded_file(...)
            : Closure::fromCallable($moveUploadedFile);
    }

    /**
     * @param array{error?: int, size?: int, tmp_name?: string, name?: string, type?: string} $file
     * @return array{path: string, mime: string, width: int, height: int, size: int}
     */
    public function store(string $propertyCode, array $file): array
    {
        if (preg_match('/^[A-Z0-9_-]{3,24}$/', $propertyCode) !== 1) {
            throw new InvalidArgumentException('Mã cơ sở lưu trú không hợp lệ.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Tệp ảnh tải lên không hoàn tất.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $declaredSize = (int) ($file['size'] ?? 0);
        if ($temporaryPath === '' || !($this->isUploadedFile)($temporaryPath)) {
            throw new InvalidArgumentException('Tệp tải lên không hợp lệ.');
        }
        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize < 1 || $actualSize > self::MAX_BYTES || $declaredSize !== $actualSize) {
            throw new InvalidArgumentException('Ảnh phải có dung lượng từ 1 byte đến 5 MiB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (!is_string($realMime) || !isset(self::MIME_EXTENSIONS[$realMime]) || $imageInfo === false) {
            throw new InvalidArgumentException('Tệp không phải ảnh JPEG, PNG hoặc WebP giải mã được.');
        }
        if (($imageInfo['mime'] ?? null) !== $realMime) {
            throw new InvalidArgumentException('MIME của tệp không khớp nội dung ảnh.');
        }

        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('Kích thước ảnh phải nằm trong giới hạn 8000 x 8000 pixel.');
        }

        $directoryName = strtolower($propertyCode);
        $directory = rtrim($this->storageRoot, '/\\') . DIRECTORY_SEPARATOR . $directoryName;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Không thể tạo thư mục lưu ảnh.');
        }

        $filename = $this->tokens->generate() . '.' . self::MIME_EXTENSIONS[$realMime];
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!($this->moveUploadedFile)($temporaryPath, $destination)) {
            throw new RuntimeException('Không thể lưu ảnh tải lên.');
        }
        @chmod($destination, 0640);

        return [
            'path' => '/uploads/properties/' . $directoryName . '/' . $filename,
            'mime' => $realMime,
            'width' => $width,
            'height' => $height,
            'size' => $actualSize,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Properties\Http;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Http\NotFoundHttpException;

final readonly class PropertyImageController
{
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private string $storageRoot)
    {
    }

    public function __invoke(Request $request): Response
    {
        $propertyCode = strtolower((string) $request->route('propertyCode', ''));
        $filename = strtolower((string) $request->route('filename', ''));
        if (preg_match('/^[a-z0-9_-]{3,24}$/', $propertyCode) !== 1
            || preg_match('/^[a-f0-9]{64}\.(jpg|png|webp)$/', $filename, $matches) !== 1) {
            throw new NotFoundHttpException();
        }

        $path = rtrim($this->storageRoot, '/\\') . DIRECTORY_SEPARATOR . $propertyCode . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new NotFoundHttpException();
        }
        return Response::binary($contents, self::MIME[$matches[1]], 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Properties\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use JsonException;
use RuntimeException;

final readonly class ImageSourceController
{
    public function __construct(
        private string $assetLockPath,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->identity->currentUser();
        return Response::html($this->views->render('sources/images', [
            'title' => 'Nguồn ảnh · StayBooking',
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user?->fullName,
            'currentUserRole' => $user?->role,
            'sources' => $this->sources(),
        ], 'layouts/app'), 200, [
            'Cache-Control' => $user === null ? 'public, max-age=3600' : 'no-store, private',
        ]);
    }

    /** @return list<array{file:string,author:string,license:string,licenseUrl:string,sourcePage:string,changes:string}> */
    private function sources(): array
    {
        $json = file_get_contents($this->assetLockPath);
        if ($json === false) {
            throw new RuntimeException('Không thể đọc hồ sơ nguồn ảnh.');
        }
        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Hồ sơ nguồn ảnh không hợp lệ.', 0, $exception);
        }
        $files = is_array($manifest) && is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $sources = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $sourcePage = (string) ($file['sourcePage'] ?? '');
            $licenseUrl = (string) ($file['licenseUrl'] ?? '');
            if (!str_starts_with($sourcePage, 'https://commons.wikimedia.org/wiki/File:')
                || !str_starts_with($licenseUrl, 'https://creativecommons.org/')) {
                continue;
            }
            $sources[] = [
                'file' => (string) ($file['localFile'] ?? ''),
                'author' => (string) ($file['author'] ?? 'Không rõ'),
                'license' => (string) ($file['license'] ?? 'Không rõ'),
                'licenseUrl' => $licenseUrl,
                'sourcePage' => $sourcePage,
                'changes' => (string) ($file['changes'] ?? ''),
            ];
        }
        return $sources;
    }
}

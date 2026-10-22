<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Application\LogoutService;

final readonly class LogoutController
{
    public function __construct(
        private LogoutService $logout,
        private AppConfig $config,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->logout->logout();
        return Response::redirect(rtrim($this->config->url, '/') . '/auth/login', 303);
    }
}

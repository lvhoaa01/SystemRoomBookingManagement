<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Http\HealthController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/health', static fn (Request $request): Response =>
        ($container->get(HealthController::class))($request), name: 'api.health');
};


<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Http\HealthController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

return static function (Router $router, Container $container): void {
    $router->get('/', static fn (Request $request): Response => Response::html(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Accommodation Booking</title>'
        . '<h1>Accommodation Booking</h1><p>Khung Modular Monolith cho nhánh lưu trú đã sẵn sàng.</p></html>'
    ), name: 'home');

    $router->get('/health', static fn (Request $request): Response =>
        ($container->get(HealthController::class))($request), name: 'health');
};

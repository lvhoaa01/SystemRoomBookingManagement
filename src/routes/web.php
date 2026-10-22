<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\HealthController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\RegisterController;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;

return static function (Router $router, Container $container): void {
    $requestId = $container->get(RequestIdMiddleware::class);
    $csrf = $container->get(CsrfMiddleware::class);
    $auth = $container->get(AuthMiddleware::class);

    $router->get('/', static function (Request $request) use ($container): Response {
        $config = $container->get(AppConfig::class);
        $identity = $container->get(IdentitySession::class);
        $user = $identity->currentUser();
        return Response::html($container->get(ViewRenderer::class)->render('home', [
            'title' => 'StayBooking - Tìm chỗ nghỉ tại TP.HCM',
            'baseUrl' => rtrim($config->url, '/'),
            'csrfToken' => $container->get(SessionBootstrap::class)->csrfToken(),
            'flashes' => $container->get(FlashBag::class)->consumeAll(),
            'currentUserName' => $user?->fullName,
        ], 'layouts/app'));
    }, [$requestId], 'home');

    $router->get(
        '/auth/register',
        static fn (Request $request): Response => $container->get(RegisterController::class)->show($request),
        [$requestId],
        'auth.register.form',
    );
    $router->post(
        '/auth/register',
        static fn (Request $request): Response => $container->get(RegisterController::class)->store($request),
        [$requestId, $csrf],
        'auth.register',
    );
    $router->get(
        '/auth/login',
        static fn (Request $request): Response => $container->get(LoginController::class)->show($request),
        [$requestId],
        'auth.login.form',
    );
    $router->post(
        '/auth/login',
        static fn (Request $request): Response => $container->get(LoginController::class)->store($request),
        [$requestId, $csrf],
        'auth.login',
    );
    $router->post(
        '/auth/logout',
        static fn (Request $request): Response => ($container->get(LogoutController::class))($request),
        [$requestId, $csrf, $auth],
        'auth.logout',
    );

    $router->get('/health', static fn (Request $request): Response =>
        ($container->get(HealthController::class))($request),
        [$requestId],
        'health',
    );
};

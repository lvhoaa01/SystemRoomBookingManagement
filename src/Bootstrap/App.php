<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppConfig;
use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Shared\Database\Connection;
use App\Shared\Database\TransactionManager;
use PDO;

final class App
{
    private function __construct(
        private readonly Router $router,
        private readonly Container $container,
    ) {
    }

    public static function boot(string $projectRoot): self
    {
        date_default_timezone_set('UTC');
        Env::load($projectRoot . '/.env');

        $appConfig = AppConfig::fromEnv();
        $databaseConfig = DatabaseConfig::fromEnv();
        $container = new Container();

        $container->instance(AppConfig::class, $appConfig);
        $container->instance(DatabaseConfig::class, $databaseConfig);
        $container->singleton(PDO::class, static fn (): PDO => Connection::create($databaseConfig));
        $container->singleton(
            TransactionManager::class,
            static fn (Container $container): TransactionManager => new TransactionManager($container->get(PDO::class)),
        );

        $session = new SessionBootstrap($appConfig);
        $container->instance(SessionBootstrap::class, $session);
        if (PHP_SAPI !== 'cli') {
            $session->start();
        }

        $router = new Router();
        $registerWebRoutes = require $projectRoot . '/src/routes/web.php';
        $registerApiRoutes = require $projectRoot . '/src/routes/api.php';
        $registerWebRoutes($router);
        $registerApiRoutes($router);

        $container->instance(Router::class, $router);

        return new self($router, $container);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function run(): void
    {
        try {
            $response = $this->router->dispatch(Request::fromGlobals());
        } catch (\Throwable $exception) {
            $response = Response::json([
                'error' => 'INTERNAL_ERROR',
                'message' => 'Ứng dụng chưa thể xử lý yêu cầu.',
            ], 500);
        }

        $response->send();
    }
}

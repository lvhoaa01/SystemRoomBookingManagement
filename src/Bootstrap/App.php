<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppConfig;
use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\HealthController;
use App\Http\Middleware\RequestIdMiddleware;
use App\Shared\Clock\ClockInterface;
use App\Shared\Clock\SystemClock;
use App\Shared\Database\Connection;
use App\Shared\Database\TransactionManager;
use App\Shared\Http\ExceptionHandler;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use App\Shared\Logging\FileLogger;
use App\Shared\Logging\Logger;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use PDO;
use Throwable;

final class App
{
    private function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly ExceptionHandler $exceptionHandler,
    ) {
    }

    public static function boot(string $projectRoot): self
    {
        date_default_timezone_set('UTC');
        // Nạp env
        Env::load($projectRoot . '/.env');
        //tạo config
        $appConfig = AppConfig::fromEnv();
        $databaseConfig = DatabaseConfig::fromEnv();

        $container = new Container();
        $container->instance(AppConfig::class, $appConfig);
        $container->instance(DatabaseConfig::class, $databaseConfig);

        $container->singleton(ClockInterface::class, static fn (Container $_): ClockInterface => new SystemClock());
        $container->singleton(TokenGenerator::class, static fn (Container $_): TokenGenerator => new TokenGenerator());
        $container->singleton(Hasher::class, 
        static fn (Container $_): Hasher => new Hasher());

        $container->singleton(
            PDO::class,
            static fn (Container $_): PDO => Connection::create($databaseConfig),
        );
        $container->singleton(
            TransactionManager::class,
            static fn (Container $container): TransactionManager => new TransactionManager($container->get(PDO::class)),
        );

        $container->singleton(
            Logger::class,
            static fn (Container $container): Logger => new FileLogger(
                $projectRoot . '/storage/logs/app.log',
                $container->get(ClockInterface::class),
            ),
        );

        $container->singleton(
            ViewRenderer::class,
            static fn (Container $_): ViewRenderer => new ViewRenderer($projectRoot . '/views'),
        );

        $container->singleton(
            FlashBag::class,
            static fn (Container $_): FlashBag => new FlashBag(),
        );
        $container->instance(RequestIdMiddleware::class, new RequestIdMiddleware());

        $container->singleton(
            HealthController::class,
            static fn (Container $container): HealthController => new HealthController(
                $container->get(PDO::class),
                $container->get(ClockInterface::class),
            ),
        );
        $session = new SessionBootstrap(
            $appConfig,
            $projectRoot . '/storage/tmp',
            $container->get(ClockInterface::class),
            $container->get(TokenGenerator::class),
        );
        $container->instance(
            SessionBootstrap::class,
            $session,
        );
        if (PHP_SAPI !== 'cli') {
            $session->start();
        }

        // Đăng ký router
        $router = new Router();
        $registerWebRoutes = require $projectRoot . '/src/routes/web.php';
        $registerApiRoutes = require $projectRoot . '/src/routes/api.php';
        $registerWebRoutes($router, $container);
        $registerApiRoutes($router, $container);

        $container->instance(Router::class, $router);
        $exceptionHandler = new ExceptionHandler($appConfig, $container->get(Logger::class));
        $container->instance(ExceptionHandler::class, $exceptionHandler);

        return new self($router, $container, $exceptionHandler);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
            $response = $this->handle($request);
        } catch (Throwable $exception) {
            $request = Request::fallbackFromGlobals();
            $response = $this->exceptionHandler->handle($exception, $request);
        }

        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $exception) {
            $response = $this->exceptionHandler->handle($exception, $request);
        }

        return $response->withHeader('X-Request-ID', $request->requestId);
    }
}

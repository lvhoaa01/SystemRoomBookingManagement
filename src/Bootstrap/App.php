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
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\PartnerContextMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Application\LogoutService;
use App\Modules\Identity\Application\RegisterCustomerService;
use App\Modules\Identity\Http\LoginController;
use App\Modules\Identity\Http\LogoutController;
use App\Modules\Identity\Http\RegisterController;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\LoginThrottle;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
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

        $container->singleton(
            UserRepository::class,
            static fn (Container $container): UserRepository => new UserRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PartnerMembershipRepository::class,
            static fn (Container $container): PartnerMembershipRepository => new PartnerMembershipRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            IdentitySession::class,
            static fn (Container $container): IdentitySession => new IdentitySession(
                $container->get(SessionBootstrap::class),
            ),
        );
        $container->singleton(
            LoginThrottle::class,
            static fn (Container $container): LoginThrottle => new LoginThrottle(
                $container->get(ClockInterface::class),
            ),
        );
        $container->singleton(
            RegisterCustomerService::class,
            static fn (Container $container): RegisterCustomerService => new RegisterCustomerService(
                $container->get(UserRepository::class),
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            LoginService::class,
            static fn (Container $container): LoginService => new LoginService(
                $container->get(UserRepository::class),
                $container->get(PartnerMembershipRepository::class),
                $container->get(IdentitySession::class),
                $container->get(LoginThrottle::class),
            ),
        );
        $container->singleton(
            LogoutService::class,
            static fn (Container $container): LogoutService => new LogoutService(
                $container->get(IdentitySession::class),
            ),
        );
        $container->singleton(
            RegisterController::class,
            static fn (Container $container): RegisterController => new RegisterController(
                $container->get(RegisterCustomerService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            LoginController::class,
            static fn (Container $container): LoginController => new LoginController(
                $container->get(LoginService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            LogoutController::class,
            static fn (Container $container): LogoutController => new LogoutController(
                $container->get(LogoutService::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            CsrfMiddleware::class,
            static fn (Container $container): CsrfMiddleware => new CsrfMiddleware(
                $container->get(SessionBootstrap::class),
            ),
        );
        $container->singleton(
            AuthMiddleware::class,
            static fn (Container $container): AuthMiddleware => new AuthMiddleware(
                $container->get(IdentitySession::class),
            ),
        );
        $container->singleton(
            PartnerContextMiddleware::class,
            static fn (Container $container): PartnerContextMiddleware => new PartnerContextMiddleware(
                $container->get(IdentitySession::class),
            ),
        );

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

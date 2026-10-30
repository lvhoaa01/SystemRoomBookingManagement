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
use App\Modules\Commercial\Application\ManageCancellationPolicyService;
use App\Modules\Commercial\Application\ManageDailyRateService;
use App\Modules\Commercial\Application\ManagePromotionService;
use App\Modules\Commercial\Application\ManageRoomProductService;
use App\Modules\Commercial\Application\PriceCalculator;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Commercial\Http\PartnerCommercialController;
use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use App\Modules\Commercial\Infrastructure\PromotionRepository;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
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
use App\Modules\Inventory\Application\AvailabilityReader;
use App\Modules\Inventory\Infrastructure\DatabaseAvailabilityReader;
use App\Modules\Properties\Application\CreatePropertyService;
use App\Modules\Properties\Application\ManageRoomTypeService;
use App\Modules\Properties\Application\PropertyImageStorage;
use App\Modules\Properties\Application\ReviewPropertyService;
use App\Modules\Properties\Application\SubmitPropertyForReviewService;
use App\Modules\Properties\Application\UpdateFacilitiesService;
use App\Modules\Properties\Application\UpdatePropertyService;
use App\Modules\Properties\Domain\PropertyReadinessChecker;
use App\Modules\Properties\Http\AdminPropertyController;
use App\Modules\Properties\Http\PartnerPropertyController;
use App\Modules\Properties\Http\PropertyImageController;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Audit\DatabaseAuditTrail;
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

        $container->singleton(
            AuditTrail::class,
            static fn (Container $container): AuditTrail => new DatabaseAuditTrail($container->get(PDO::class)),
        );
        $container->singleton(
            PropertyRepository::class,
            static fn (Container $container): PropertyRepository => new PropertyRepository($container->get(PDO::class)),
        );
        $container->singleton(
            CancellationPolicyRepository::class,
            static fn (Container $container): CancellationPolicyRepository => new CancellationPolicyRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            RoomProductRepository::class,
            static fn (Container $container): RoomProductRepository => new RoomProductRepository($container->get(PDO::class)),
        );
        $container->singleton(
            DailyRateRepository::class,
            static fn (Container $container): DailyRateRepository => new DailyRateRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PromotionRepository::class,
            static fn (Container $container): PromotionRepository => new PromotionRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PriceCalculator::class,
            static fn (Container $_): PriceCalculator => new StandardPriceCalculator(),
        );
        $container->singleton(
            AvailabilityReader::class,
            static fn (Container $container): AvailabilityReader => new DatabaseAvailabilityReader(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            RoomTypeRepository::class,
            static fn (Container $container): RoomTypeRepository => new RoomTypeRepository($container->get(PDO::class)),
        );
        $container->singleton(
            FacilityRepository::class,
            static fn (Container $container): FacilityRepository => new FacilityRepository($container->get(PDO::class)),
        );
        $container->singleton(
            PropertyReadinessRepository::class,
            static fn (Container $container): PropertyReadinessRepository => new PropertyReadinessRepository(
                $container->get(PDO::class),
            ),
        );
        $container->singleton(
            PropertyReadinessChecker::class,
            static fn (Container $_): PropertyReadinessChecker => new PropertyReadinessChecker(),
        );
        $container->singleton(
            PropertyImageStorage::class,
            static fn (Container $container): PropertyImageStorage => new PropertyImageStorage(
                $projectRoot . '/storage/uploads/properties',
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            CreatePropertyService::class,
            static fn (Container $container): CreatePropertyService => new CreatePropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
                $container->get(TokenGenerator::class),
            ),
        );
        $container->singleton(
            UpdatePropertyService::class,
            static fn (Container $container): UpdatePropertyService => new UpdatePropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            SubmitPropertyForReviewService::class,
            static fn (Container $container): SubmitPropertyForReviewService => new SubmitPropertyForReviewService(
                $container->get(PropertyRepository::class),
                $container->get(PropertyReadinessRepository::class),
                $container->get(PropertyReadinessChecker::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ReviewPropertyService::class,
            static fn (Container $container): ReviewPropertyService => new ReviewPropertyService(
                $container->get(PropertyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageRoomTypeService::class,
            static fn (Container $container): ManageRoomTypeService => new ManageRoomTypeService(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            UpdateFacilitiesService::class,
            static fn (Container $container): UpdateFacilitiesService => new UpdateFacilitiesService(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(FacilityRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageCancellationPolicyService::class,
            static fn (Container $container): ManageCancellationPolicyService => new ManageCancellationPolicyService(
                $container->get(PropertyRepository::class),
                $container->get(CancellationPolicyRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageRoomProductService::class,
            static fn (Container $container): ManageRoomProductService => new ManageRoomProductService(
                $container->get(PropertyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManageDailyRateService::class,
            static fn (Container $container): ManageDailyRateService => new ManageDailyRateService(
                $container->get(PropertyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(DailyRateRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            ManagePromotionService::class,
            static fn (Container $container): ManagePromotionService => new ManagePromotionService(
                $container->get(PropertyRepository::class),
                $container->get(PromotionRepository::class),
                $container->get(TransactionManager::class),
                $container->get(AuditTrail::class),
            ),
        );
        $container->singleton(
            PartnerCommercialController::class,
            static fn (Container $container): PartnerCommercialController => new PartnerCommercialController(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(CancellationPolicyRepository::class),
                $container->get(RoomProductRepository::class),
                $container->get(DailyRateRepository::class),
                $container->get(PromotionRepository::class),
                $container->get(ManageCancellationPolicyService::class),
                $container->get(ManageRoomProductService::class),
                $container->get(ManageDailyRateService::class),
                $container->get(ManagePromotionService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            PartnerPropertyController::class,
            static fn (Container $container): PartnerPropertyController => new PartnerPropertyController(
                $container->get(PropertyRepository::class),
                $container->get(RoomTypeRepository::class),
                $container->get(FacilityRepository::class),
                $container->get(CreatePropertyService::class),
                $container->get(UpdatePropertyService::class),
                $container->get(SubmitPropertyForReviewService::class),
                $container->get(ManageRoomTypeService::class),
                $container->get(UpdateFacilitiesService::class),
                $container->get(PropertyImageStorage::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->singleton(
            AdminPropertyController::class,
            static fn (Container $container): AdminPropertyController => new AdminPropertyController(
                $container->get(PropertyRepository::class),
                $container->get(PropertyReadinessRepository::class),
                $container->get(ReviewPropertyService::class),
                $container->get(IdentitySession::class),
                $container->get(SessionBootstrap::class),
                $container->get(ViewRenderer::class),
                $container->get(FlashBag::class),
                $container->get(AppConfig::class),
            ),
        );
        $container->instance(
            PropertyImageController::class,
            new PropertyImageController($projectRoot . '/storage/uploads/properties'),
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

<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Commercial\Application\ManageCancellationPolicyService;
use App\Modules\Commercial\Application\ManageDailyRateService;
use App\Modules\Commercial\Application\ManagePromotionService;
use App\Modules\Commercial\Application\ManageRoomProductService;
use App\Modules\Commercial\Infrastructure\CancellationPolicyRepository;
use App\Modules\Commercial\Infrastructure\DailyRateRepository;
use App\Modules\Commercial\Infrastructure\PromotionRepository;
use App\Modules\Commercial\Infrastructure\RoomProductRepository;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final readonly class PartnerCommercialController
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
        private CancellationPolicyRepository $policies,
        private RoomProductRepository $products,
        private DailyRateRepository $rates,
        private PromotionRepository $promotions,
        private ManageCancellationPolicyService $managePolicies,
        private ManageRoomProductService $manageProducts,
        private ManageDailyRateService $manageRates,
        private ManagePromotionService $managePromotions,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function index(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $property = $this->ownedProperty($this->routeId($request, 'propertyId'), $organization);
        [$fromDate, $toDate] = $this->dateRange($request, (string) $property['timezone']);
        $products = $this->products->listForProperty((int) $property['id'], $organization->id);
        $ratesByProduct = [];
        foreach ($products as $product) {
            $ratesByProduct[(int) $product['id']] = $this->rates->listForProduct(
                (int) $product['id'],
                $organization->id,
                $fromDate,
                $toDate,
            );
        }

        return $this->view('commercial/partner/index', [
            'title' => 'Thiết lập thương mại · ' . $property['name'],
            'property' => $property,
            'rooms' => $this->rooms->listForProperty((int) $property['id'], $organization->id),
            'policies' => $this->policies->listForProperty((int) $property['id'], $organization->id),
            'products' => $products,
            'ratesByProduct' => $ratesByProduct,
            'promotions' => $this->promotions->listForProperty((int) $property['id'], $organization->id),
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ], $user);
    }

    public function createPolicy(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $this->managePolicies->create(
                $propertyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã tạo chính sách hủy.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect($propertyId, 'policies');
    }

    public function updatePolicy(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $policyId = $this->routeId($request, 'policyId');
        $policy = $this->policies->findForOrganization($policyId, $organization->id);
        if ($policy === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->managePolicies->update(
                $policyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật chính sách hủy.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect((int) $policy['propertyId'], 'policies');
    }

    public function createProduct(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $this->manageProducts->create(
                $propertyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã tạo sản phẩm phòng.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect($propertyId, 'products');
    }

    public function updateProduct(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $productId = $this->routeId($request, 'productId');
        $product = $this->products->findForOrganization($productId, $organization->id);
        if ($product === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->manageProducts->update(
                $productId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật sản phẩm phòng.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect((int) $product['propertyId'], 'products');
    }

    public function updateRate(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $productId = $this->routeId($request, 'productId');
        $product = $this->products->findForOrganization($productId, $organization->id);
        if ($product === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->manageRates->updateCalendar(
                $productId, $organization, $user->id, [$request->body], $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã lưu ngày giá và tăng phiên bản.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect((int) $product['propertyId'], 'rates');
    }

    public function createPromotion(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $this->managePromotions->create(
                $propertyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã tạo khuyến mãi.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect($propertyId, 'promotions');
    }

    public function updatePromotion(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $promotionId = $this->routeId($request, 'promotionId');
        $promotion = $this->promotions->findForOrganization($promotionId, $organization->id);
        if ($promotion === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->managePromotions->update(
                $promotionId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật khuyến mãi.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return $this->redirect((int) $promotion['propertyId'], 'promotions');
    }

    /** @return array{CurrentUser, OrganizationContext} */
    private function identity(): array
    {
        $user = $this->identity->currentUser();
        $organization = $this->identity->organization();
        if ($user === null || $organization === null) {
            throw new DomainException('Phiên đối tác không hợp lệ.');
        }
        return [$user, $organization];
    }

    /** @return array<string, mixed> */
    private function ownedProperty(int $propertyId, OrganizationContext $organization): array
    {
        $property = $this->properties->findForOrganization($propertyId, $organization->id);
        if ($property === null) {
            throw new NotFoundHttpException();
        }
        return $property;
    }

    private function routeId(Request $request, string $name): int
    {
        $value = $request->route($name, '');
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new NotFoundHttpException();
        }
        return (int) $value;
    }

    /** @return array{string, string} */
    private function dateRange(Request $request, string $timezone): array
    {
        try {
            $today = new DateTimeImmutable('today', new DateTimeZone($timezone));
        } catch (\Throwable) {
            $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Ho_Chi_Minh'));
        }
        $from = trim((string) ($request->query['from'] ?? $today->format('Y-m-d')));
        $to = trim((string) ($request->query['to'] ?? $today->modify('+13 days')->format('Y-m-d')));
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($fromDate === false || $toDate === false
            || $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to
            || $fromDate > $toDate || (int) $fromDate->diff($toDate)->days > 60) {
            throw new InvalidArgumentException('Khoảng lịch giá phải hợp lệ và không vượt quá 61 ngày.');
        }
        return [$from, $to];
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, CurrentUser $user): Response
    {
        return Response::html($this->views->render($template, [
            ...$data,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
        ], 'layouts/app'));
    }

    private function redirect(int $propertyId, string $section): Response
    {
        return Response::redirect(
            rtrim($this->config->url, '/') . '/partner/properties/' . $propertyId . '/commercial#' . $section,
            303,
        );
    }
}

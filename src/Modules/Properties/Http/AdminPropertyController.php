<?php

declare(strict_types=1);

namespace App\Modules\Properties\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Properties\Application\ReviewPropertyService;
use App\Modules\Properties\Domain\PropertyReviewAction;
use App\Modules\Properties\Infrastructure\PropertyReadinessRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ViewRenderer;
use DomainException;
use ValueError;

final readonly class AdminPropertyController
{
    public function __construct(
        private PropertyRepository $properties,
        private PropertyReadinessRepository $readiness,
        private ReviewPropertyService $review,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view('properties/admin/index', [
            'title' => 'Hàng đợi duyệt cơ sở lưu trú',
            'properties' => $this->properties->listPendingReview(),
        ]);
    }

    public function show(Request $request): Response
    {
        $property = $this->property($request);
        return $this->view('properties/admin/show', [
            'title' => 'Duyệt ' . $property['name'],
            'property' => $property,
            'activeRooms' => $this->readiness->activeRooms((int) $property['id']),
            'commercial' => $this->readiness->commercialFacts((int) $property['id']),
        ]);
    }

    public function review(Request $request): Response
    {
        $propertyId = $this->routeId($request);
        $user = $this->identity->currentUser();
        if ($user === null) {
            throw new DomainException('Phiên quản trị không hợp lệ.');
        }
        try {
            $action = PropertyReviewAction::from(strtoupper(trim((string) ($request->body['action'] ?? ''))));
            $note = trim((string) ($request->body['note'] ?? ''));
            $this->review->review(
                $propertyId, $user, $action, $note === '' ? null : $note,
                $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật trạng thái cơ sở lưu trú.');
        } catch (DomainException|ValueError $exception) {
            $this->flash->add('info', $exception instanceof ValueError ? 'Hành động duyệt không hợp lệ.' : $exception->getMessage());
        }
        return Response::redirect($this->url('/admin/properties/' . $propertyId), 303);
    }

    /** @return array<string, mixed> */
    private function property(Request $request): array
    {
        $property = $this->properties->findById($this->routeId($request));
        if ($property === null) {
            throw new NotFoundHttpException();
        }
        return $property;
    }

    private function routeId(Request $request): int
    {
        $value = $request->route('propertyId', '');
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new NotFoundHttpException();
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data): Response
    {
        $user = $this->identity->currentUser();
        return Response::html($this->views->render($template, [
            ...$data,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user?->fullName,
            'currentUserRole' => $user?->role,
        ], 'layouts/app'));
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}

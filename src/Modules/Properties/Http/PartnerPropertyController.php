<?php

declare(strict_types=1);

namespace App\Modules\Properties\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Properties\Application\CreatePropertyService;
use App\Modules\Properties\Application\ManageRoomTypeService;
use App\Modules\Properties\Application\PropertyImageStorage;
use App\Modules\Properties\Application\SubmitPropertyForReviewService;
use App\Modules\Properties\Application\UpdateFacilitiesService;
use App\Modules\Properties\Application\UpdatePropertyService;
use App\Modules\Properties\Infrastructure\FacilityRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ValidationHttpException;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class PartnerPropertyController
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
        private FacilityRepository $facilities,
        private CreatePropertyService $createProperty,
        private UpdatePropertyService $updateProperty,
        private SubmitPropertyForReviewService $submitProperty,
        private ManageRoomTypeService $manageRooms,
        private UpdateFacilitiesService $updateFacilities,
        private PropertyImageStorage $images,
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
        return $this->view('properties/partner/index', [
            'title' => 'Cơ sở lưu trú của bạn',
            'properties' => $this->properties->listForOrganization($organization->id),
            'organization' => $organization,
        ], $user);
    }

    public function create(Request $request): Response
    {
        [$user] = $this->identity();
        return $this->view('properties/partner/form', [
            'title' => 'Thêm cơ sở lưu trú', 'property' => null, 'errors' => [],
        ], $user);
    }

    public function store(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        try {
            $propertyId = $this->createProperty->create(
                $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã tạo bản nháp cơ sở lưu trú.');
            return Response::redirect($this->url('/partner/properties/' . $propertyId), 303);
        } catch (InvalidArgumentException|DomainException $exception) {
            return $this->view('properties/partner/form', [
                'title' => 'Thêm cơ sở lưu trú', 'property' => $request->body,
                'errors' => ['form' => [$exception->getMessage()]],
            ], $user, 422);
        }
    }

    public function show(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $property = $this->ownedProperty($request, $organization);
        $rooms = $this->rooms->listForProperty((int) $property['id'], $organization->id);
        $roomFacilities = [];
        foreach ($rooms as $room) {
            $roomFacilities[(int) $room['id']] = $this->facilities->listForRoomType(
                (int) $room['id'],
                $organization->id,
            );
        }
        return $this->view('properties/partner/show', [
            'title' => (string) $property['name'],
            'property' => $property,
            'rooms' => $rooms,
            'roomFacilities' => $roomFacilities,
            'propertyFacilities' => $this->facilities->listForProperty((int) $property['id'], $organization->id),
            'propertyFacilityCatalog' => $this->facilities->listActiveCatalog(FacilityRepository::SCOPE_PROPERTY),
            'roomFacilityCatalog' => $this->facilities->listActiveCatalog(FacilityRepository::SCOPE_ROOM),
        ], $user);
    }

    public function edit(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        return $this->view('properties/partner/form', [
            'title' => 'Chỉnh sửa cơ sở lưu trú',
            'property' => $this->ownedProperty($request, $organization),
            'errors' => [],
        ], $user);
    }

    public function update(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $status = $this->updateProperty->update(
                $propertyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật cơ sở lưu trú. Trạng thái hiện tại: ' . $status->value . '.');
            return Response::redirect($this->url('/partner/properties/' . $propertyId), 303);
        } catch (InvalidArgumentException|DomainException $exception) {
            return $this->view('properties/partner/form', [
                'title' => 'Chỉnh sửa cơ sở lưu trú',
                'property' => ['id' => $propertyId, ...$request->body],
                'errors' => ['form' => [$exception->getMessage()]],
            ], $user, 422);
        }
    }

    public function submit(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $report = $this->submitProperty->submit(
                $propertyId, $organization, $user->id, $request->requestId, $request->clientIp,
            );
            if (!$report->isReady()) {
                foreach ($report->missing as $message) {
                    $this->flash->add('info', $message);
                }
            } else {
                $this->flash->add('success', 'Đã gửi cơ sở lưu trú cho quản trị viên duyệt.');
            }
        } catch (DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/partner/properties/' . $propertyId), 303);
    }

    public function createRoom(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        try {
            $this->manageRooms->create(
                $propertyId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã thêm loại phòng.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/partner/properties/' . $propertyId), 303);
    }

    public function updateRoom(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $roomTypeId = $this->routeId($request, 'roomTypeId');
        $room = $this->rooms->findForOrganization($roomTypeId, $organization->id);
        if ($room === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->manageRooms->update(
                $roomTypeId, $organization, $user->id, $request->body, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật loại phòng.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/partner/properties/' . (int) $room['propertyId']), 303);
    }

    public function updatePropertyFacilities(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $propertyId = $this->routeId($request, 'propertyId');
        $ids = $request->body['facilityIds'] ?? [];
        if (!is_array($ids)) {
            throw new ValidationHttpException(['facilityIds' => ['Danh sách tiện nghi không hợp lệ.']]);
        }
        $facilities = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $facilities[] = ['id' => (int) $id, 'free' => true, 'note' => null];
            }
        }
        try {
            $this->updateFacilities->updateProperty(
                $propertyId, $organization, $user->id, $facilities, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật tiện nghi cơ sở lưu trú.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/partner/properties/' . $propertyId), 303);
    }

    public function updateRoomFacilities(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $roomTypeId = $this->routeId($request, 'roomTypeId');
        $room = $this->rooms->findForOrganization($roomTypeId, $organization->id);
        if ($room === null) {
            throw new NotFoundHttpException();
        }
        $ids = $request->body['facilityIds'] ?? [];
        if (!is_array($ids)) {
            throw new ValidationHttpException(['facilityIds' => ['Danh sách tiện nghi không hợp lệ.']]);
        }
        $facilities = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $facilities[] = ['id' => (int) $id, 'quantity' => null, 'note' => null];
            }
        }
        try {
            $this->updateFacilities->updateRoomType(
                $roomTypeId, $organization, $user->id, $facilities, $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật tiện nghi loại phòng.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/partner/properties/' . (int) $room['propertyId']), 303);
    }

    public function uploadImage(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $property = $this->ownedProperty($request, $organization);
        $file = $request->files['image'] ?? null;
        if (!is_array($file)) {
            throw new ValidationHttpException(['image' => ['Bạn chưa chọn ảnh.']]);
        }
        try {
            $stored = $this->images->store((string) $property['code'], $file);
            return Response::json(['data' => $stored], 201);
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            throw new ValidationHttpException(['image' => [$exception->getMessage()]]);
        }
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
    private function ownedProperty(Request $request, OrganizationContext $organization): array
    {
        $property = $this->properties->findForOrganization($this->routeId($request, 'propertyId'), $organization->id);
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

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, CurrentUser $user, int $status = 200): Response
    {
        return Response::html($this->views->render($template, [
            ...$data,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
        ], 'layouts/app'), $status);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}

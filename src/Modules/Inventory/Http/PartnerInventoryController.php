<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Inventory\Application\InventoryCalendarService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Properties\Infrastructure\PropertyRepository;
use App\Modules\Properties\Infrastructure\RoomTypeRepository;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ViewRenderer;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final readonly class PartnerInventoryController
{
    public function __construct(
        private PropertyRepository $properties,
        private RoomTypeRepository $rooms,
        private InventoryRepository $inventory,
        private InventoryCalendarService $calendar,
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
        $propertyId = $this->routeId($request, 'propertyId');
        $property = $this->properties->findForOrganization($propertyId, $organization->id);
        if ($property === null) {
            throw new NotFoundHttpException();
        }
        [$fromDate, $toDate] = $this->dateRange($request, (string) $property['timezone']);
        $rooms = $this->rooms->listForProperty($propertyId, $organization->id);
        $inventoryByRoom = [];
        foreach ($rooms as $room) {
            $inventoryByRoom[(int) $room['id']] = $this->inventory->listForRoomType(
                (int) $room['id'],
                $organization->id,
                $fromDate,
                $toDate,
            );
        }

        return $this->view('partner/inventory/index', [
            'title' => 'Lịch tồn phòng · ' . $property['name'],
            'property' => $property,
            'rooms' => $rooms,
            'inventoryByRoom' => $inventoryByRoom,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ], $user);
    }

    public function update(Request $request): Response
    {
        [$user, $organization] = $this->identity();
        $roomTypeId = $this->routeId($request, 'roomTypeId');
        $room = $this->rooms->findForOrganization($roomTypeId, $organization->id);
        if ($room === null) {
            throw new NotFoundHttpException();
        }
        try {
            $this->calendar->updateCalendar(
                $roomTypeId,
                $organization,
                $user->id,
                [$request->body],
                $request->requestId,
                $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật tổng tồn phòng và tăng phiên bản.');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }

        return Response::redirect(
            rtrim($this->config->url, '/') . '/partner/properties/' . (int) $room['propertyId'] . '/inventory',
            303,
        );
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

    private function routeId(Request $request, string $name): int
    {
        $value = $request->route($name, '');
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
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
        $fromValue = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toValue = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($fromValue === false || $toValue === false
            || $fromValue->format('Y-m-d') !== $from || $toValue->format('Y-m-d') !== $to
            || $fromValue > $toValue || (int) $fromValue->diff($toValue)->days > 60) {
            throw new InvalidArgumentException('Khoảng lịch tồn phải hợp lệ và không vượt quá 61 ngày.');
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
}

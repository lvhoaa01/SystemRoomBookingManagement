<?php

declare(strict_types=1);

namespace App\Modules\Search\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Search\Application\PropertyAvailabilityResult;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Modules\Search\Application\PropertySearchResult;
use App\Modules\Search\Application\SearchCriteriaFactory;
use App\Modules\Search\Application\SearchPage;
use App\Modules\Search\Application\SearchService;
use App\Shared\Contract\AllocationResult;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ValidationHttpException;
use App\Shared\Http\ViewRenderer;
use InvalidArgumentException;

final readonly class PublicSearchController
{
    public function __construct(
        private SearchCriteriaFactory $criteriaFactory,
        private SearchService $searchService,
        private PropertyAvailabilityService $availabilityService,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function search(Request $request): Response
    {
        try {
            $criteria = $this->criteriaFactory->fromArray($request->query);
            $page = $this->searchService->search($criteria);
        } catch (InvalidArgumentException $exception) {
            throw new ValidationHttpException(['criteria' => $exception->getMessage()], $exception->getMessage());
        }
        $payload = $this->searchPayload($page);
        if ($request->expectsJson()) {
            return Response::json($payload);
        }
        return $this->view('search/index', [
            'title' => 'Kết quả tìm kiếm tại TP.HCM',
            'search' => $payload,
            'query' => $request->query,
        ]);
    }

    public function property(Request $request): Response
    {
        $result = $this->availabilityResult($request);
        $payload = $this->availabilityPayload($result);
        if ($request->expectsJson()) {
            return Response::json($payload);
        }
        return $this->view('properties/public/show', [
            'title' => $result->property->name . ' · StayBooking',
            'availability' => $payload,
            'query' => $request->query,
        ]);
    }

    public function availability(Request $request): Response
    {
        return Response::json($this->availabilityPayload($this->availabilityResult($request)));
    }

    private function availabilityResult(Request $request): PropertyAvailabilityResult
    {
        try {
            $criteria = $this->criteriaFactory->fromArray($request->query);
            $result = $this->availabilityService->check(
                (string) $request->route('slug', ''),
                $criteria,
                $this->optionalString($request->query['paymentTiming'] ?? null),
                $this->optionalString($request->query['selectionFingerprint'] ?? null),
                $this->selected($request->query['selected'] ?? null),
            );
        } catch (InvalidArgumentException $exception) {
            throw new ValidationHttpException(['criteria' => $exception->getMessage()], $exception->getMessage());
        }
        if ($result === null) {
            throw new NotFoundHttpException();
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function searchPayload(SearchPage $page): array
    {
        return [
            'criteria' => $this->jsonArray($page->criteria),
            'pagination' => [
                'total' => $page->total,
                'page' => $page->page,
                'pageSize' => $page->pageSize,
                'totalPages' => $page->totalPages,
            ],
            'results' => array_map(fn (PropertySearchResult $result): array => [
                'property' => $this->propertyPayload($result->property),
                'representative' => $this->jsonArray($result->representative),
                'availableTimings' => array_map(
                    static fn (AllocationResult $allocation): string => $allocation->paymentTiming,
                    $result->allocationOptions,
                ),
                'minimumAvailable' => $result->minimumAvailable,
                'taxFeeTotal' => $result->taxFeeTotal->decimal(),
                'cancellationLabel' => $result->cancellationLabel,
            ], $page->results),
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityPayload(PropertyAvailabilityResult $result): array
    {
        $allocation = $result->allocation === null ? null : $this->jsonArray($result->allocation);
        return [
            'property' => $this->propertyPayload($result->property, true),
            'criteria' => $this->jsonArray($result->criteria),
            'available' => $result->available(),
            'paymentTiming' => $result->paymentTiming,
            'allocation' => $allocation,
            'timingSummary' => $result->timingSummary,
            'selectionChanged' => $result->selectionChanged,
            'changeReason' => $result->changeReason,
        ];
    }

    /** @return array<string, mixed> */
    private function propertyPayload(\App\Modules\Search\Application\PropertySearchSnapshot $property, bool $withRooms = false): array
    {
        $payload = [
            'id' => $property->propertyId,
            'slug' => $property->slug,
            'name' => $property->name,
            'type' => $property->propertyType,
            'address' => $property->address,
            'description' => $property->description,
            'images' => $property->images,
            'starRating' => $property->starRating,
            'reviewScore' => $property->reviewScore,
            'reviewCount' => $property->reviewCount,
            'facilities' => $property->facilities,
            'checkInTime' => $property->checkInTime,
            'checkOutTime' => $property->checkOutTime,
            'mapUrl' => $property->mapUrl,
        ];
        if ($withRooms) {
            $payload['roomProducts'] = array_map(static fn ($product): array => [
                'roomTypeId' => $product->roomTypeId,
                'roomTypeName' => $product->roomTypeName,
                'roomDescription' => $product->roomDescription,
                'maximumGuests' => $product->maximumGuests,
                'maximumAdults' => $product->maximumAdults,
                'maximumChildren' => $product->maximumChildren,
                'bedConfiguration' => $product->bedConfiguration,
                'areaSquareMeters' => $product->areaSquareMeters,
                'smokingAllowed' => $product->smokingAllowed,
                'images' => $product->roomImages,
                'facilities' => $product->roomFacilities,
                'productId' => $product->productId,
                'productName' => $product->productName,
                'mealType' => $product->mealType,
                'paymentTiming' => $product->paymentTiming,
                'minimumAvailable' => $product->minimumAvailable,
                'cancellationPolicy' => $product->pricing->cancellationSnapshot,
            ], $property->roomProducts);
        }
        return $payload;
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function selected(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }
        $selected = [];
        foreach (explode(',', (string) $value) as $choice) {
            $parts = array_map('trim', explode(':', $choice));
            if (count($parts) !== 3) {
                throw new InvalidArgumentException('Tham số selected không hợp lệ.');
            }
            [$roomOrder, $roomTypeId, $productId] = $parts;
            if (filter_var($roomOrder, FILTER_VALIDATE_INT) === false
                || filter_var($roomTypeId, FILTER_VALIDATE_INT) === false
                || filter_var($productId, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Tham số selected không hợp lệ.');
            }
            $selected[(int) $roomOrder] = [
                'roomTypeId' => (int) $roomTypeId,
                'productId' => (int) $productId,
            ];
        }
        return $selected;
    }

    private function optionalString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    private function jsonArray(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
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
}

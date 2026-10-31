<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Search;

use App\Bootstrap\App;
use App\Http\Request;
use Tests\Support\TestCase;

final class SearchHttpFlowTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
    }

    public function test_anonymous_guest_can_search_and_open_detail_without_authentication(): void
    {
        $query = $this->query();
        $search = $this->app->handle(new Request(
            'GET',
            '/search',
            $query,
            headers: ['accept' => 'application/json'],
            requestId: 'search-http-results',
        ));

        self::assertSame(200, $search->status());
        $payload = json_decode($search->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $payload['pagination']['total']);
        $card = $payload['results'][0];
        $allocation = $card['representative'];

        $detailQuery = [
            ...$query,
            'paymentTiming' => $allocation['paymentTiming'],
            'selectionFingerprint' => $allocation['selectionFingerprint'],
            'selected' => implode(',', array_map(
                static fn (array $room): string => $room['roomOrder'] . ':' . $room['roomTypeId'] . ':' . $room['productId'],
                $allocation['rooms'],
            )),
        ];
        $detail = $this->app->handle(new Request(
            'GET',
            '/properties/' . $card['property']['slug'],
            $detailQuery,
            headers: ['accept' => 'application/json'],
            requestId: 'search-http-detail',
        ));

        self::assertSame(200, $detail->status());
        $detailPayload = json_decode($detail->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertFalse($detailPayload['selectionChanged']);
        self::assertSame($allocation['selectionFingerprint'], $detailPayload['allocation']['selectionFingerprint']);
    }

    public function test_availability_endpoint_returns_only_requested_timing_and_detects_stale_fingerprint(): void
    {
        $search = $this->app->handle(new Request(
            'GET',
            '/search',
            $this->query(),
            headers: ['accept' => 'application/json'],
            requestId: 'search-http-source',
        ));
        $card = json_decode($search->body(), true, 64, JSON_THROW_ON_ERROR)['results'][0];
        $timing = $card['representative']['paymentTiming'];
        $response = $this->app->handle(new Request(
            'GET',
            '/properties/' . $card['property']['slug'] . '/availability',
            [...$this->query(), 'paymentTiming' => $timing, 'selectionFingerprint' => str_repeat('f', 64)],
            requestId: 'search-http-availability',
        ));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($timing, $payload['paymentTiming']);
        self::assertTrue($payload['selectionChanged']);
        foreach ($payload['allocation']['rooms'] as $room) {
            self::assertSame($timing, $room['paymentTiming']);
        }
    }

    public function test_invalid_search_input_returns_validation_error(): void
    {
        $response = $this->app->handle(new Request(
            'GET',
            '/search',
            [...$this->query(), 'checkOut' => '2026-12-01'],
            headers: ['accept' => 'application/json'],
            requestId: 'search-http-invalid',
        ));

        self::assertSame(400, $response->status());
        $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('VALIDATION_ERROR', $payload['error']['code']);
    }

    /** @return array<string, mixed> */
    private function query(): array
    {
        return [
            'destination' => 'HCM',
            'checkIn' => '2026-12-10',
            'checkOut' => '2026-12-12',
            'rooms' => '[{"roomOrder":1,"adults":2,"childAges":[7]}]',
            'sort' => 'PRICE_ASC',
        ];
    }
}

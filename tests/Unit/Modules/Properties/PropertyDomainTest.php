<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Domain\BedConfiguration;
use App\Modules\Properties\Domain\ImageCollection;
use App\Modules\Properties\Domain\PropertyStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestCase;

final class PropertyDomainTest extends TestCase
{
    public function test_property_status_only_allows_documented_workflow_transitions(): void
    {
        self::assertTrue(PropertyStatus::DRAFT->canTransitionTo(PropertyStatus::PENDING_REVIEW));
        self::assertTrue(PropertyStatus::PENDING_REVIEW->canTransitionTo(PropertyStatus::ACTIVE));
        self::assertTrue(PropertyStatus::PENDING_REVIEW->canTransitionTo(PropertyStatus::DRAFT));
        self::assertTrue(PropertyStatus::PENDING_REVIEW->canTransitionTo(PropertyStatus::REJECTED));
        self::assertTrue(PropertyStatus::ACTIVE->canTransitionTo(PropertyStatus::SUSPENDED));
        self::assertTrue(PropertyStatus::SUSPENDED->canTransitionTo(PropertyStatus::ACTIVE));
        self::assertFalse(PropertyStatus::REJECTED->canTransitionTo(PropertyStatus::DRAFT));
        self::assertFalse(PropertyStatus::DRAFT->canTransitionTo(PropertyStatus::ACTIVE));
    }

    public function test_bed_configuration_round_trips_seed_compatible_json(): void
    {
        $configuration = BedConfiguration::fromJson(
            '[{"type":"KING","quantity":1},{"type":"SOFA_BED","quantity":1}]',
        );

        self::assertSame(2, $configuration->totalBeds());
        self::assertSame('KING', $configuration->items()[0]['type']);
        self::assertJsonStringEqualsJsonString(
            '[{"type":"KING","quantity":1},{"type":"SOFA_BED","quantity":1}]',
            $configuration->toJson(),
        );
    }

    public function test_image_collection_orders_images_and_finds_cover(): void
    {
        $images = new ImageCollection([
            ['path' => '/uploads/properties/CS001/room.webp', 'alt' => 'Phòng', 'sort' => 2, 'cover' => false],
            ['path' => '/assets/images/accommodation/shared/cover.png', 'alt' => 'Mặt tiền', 'sort' => 1, 'cover' => true],
        ]);

        self::assertSame('/assets/images/accommodation/shared/cover.png', $images->cover()['path']);
        self::assertSame(1, $images->images()[0]['sort']);
        self::assertFalse($images->isEmpty());
        self::assertEquals($images, ImageCollection::fromJson($images->toJson()));
    }

    public function test_bed_configuration_accepts_canonical_key_order_on_round_trip(): void
    {
        $beds = BedConfiguration::fromJson('[{"type":"DOUBLE","quantity":2}]');
        self::assertEquals($beds, BedConfiguration::fromJson($beds->toJson()));
    }

    #[DataProvider('invalidValueProvider')]
    public function test_domain_values_reject_invalid_input(callable $create): void
    {
        $this->expectException(InvalidArgumentException::class);
        $create();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValueProvider(): iterable
    {
        yield 'empty beds' => [static fn (): BedConfiguration => new BedConfiguration([])];
        yield 'duplicate bed type' => [static fn (): BedConfiguration => new BedConfiguration([
            ['type' => 'DOUBLE', 'quantity' => 1],
            ['type' => 'DOUBLE', 'quantity' => 2],
        ])];
        yield 'unsupported bed type' => [static fn (): BedConfiguration => new BedConfiguration([
            ['type' => 'WATERBED', 'quantity' => 1],
        ])];
        yield 'unsafe image path' => [static fn (): ImageCollection => new ImageCollection([
            ['path' => '/../shell.php', 'alt' => 'Ảnh', 'sort' => 1, 'cover' => true],
        ])];
        yield 'missing cover' => [static fn (): ImageCollection => new ImageCollection([
            ['path' => '/uploads/properties/CS001/a.webp', 'alt' => 'Ảnh', 'sort' => 1, 'cover' => false],
        ])];
    }
}

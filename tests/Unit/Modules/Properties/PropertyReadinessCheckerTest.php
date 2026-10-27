<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Domain\PropertyReadinessChecker;
use Tests\Support\TestCase;

final class PropertyReadinessCheckerTest extends TestCase
{
    public function test_ready_property_passes_all_checks(): void
    {
        $report = (new PropertyReadinessChecker())->check(true, $this->property(), $this->rooms(), $this->commercial());
        self::assertTrue($report->isReady());
        self::assertSame([], $report->missing);
    }

    public function test_report_lists_every_missing_section_instead_of_stopping_early(): void
    {
        $property = $this->property();
        $property['description'] = '';
        $property['imagesJson'] = '[]';
        $commercial = $this->commercial();
        $commercial['hasCancellationPolicy'] = false;
        $commercial['hasProduct'] = false;
        $commercial['priceDays'] = 12;
        $commercial['inventoryDays'] = 20;
        $commercial['hasCrossPropertyRelation'] = true;

        $report = (new PropertyReadinessChecker())->check(false, $property, [], $commercial);

        self::assertFalse($report->isReady());
        self::assertCount(9, $report->missing);
        self::assertStringContainsString('Tổ chức', $report->missing[0]);
        self::assertStringContainsString('tham chiếu chéo', $report->missing[8]);
    }

    public function test_majestic_assets_only_make_cs016_ready(): void
    {
        $property = $this->property();
        $property['imagesJson'] = '[{"path":"/assets/images/accommodation/majestic/front.webp","alt":"Mặt tiền","sort":1,"cover":true}]';

        $wrongProperty = (new PropertyReadinessChecker())->check(true, $property, $this->rooms(), $this->commercial());
        self::assertFalse($wrongProperty->isReady());
        self::assertStringContainsString('ít nhất một ảnh', $wrongProperty->missing[0]);

        $property['code'] = 'CS016';
        self::assertTrue((new PropertyReadinessChecker())->check(
            true, $property, $this->rooms(), $this->commercial(),
        )->isReady());
    }

    /** @return array<string, mixed> */
    private function property(): array
    {
        return [
            'code' => 'CS001', 'type' => 'HOTEL', 'name' => 'Khách sạn', 'sourceName' => 'Khách sạn nguồn',
            'nameSource' => 'PARTNER_PORTAL', 'sourceRecordCode' => 'ROW-1',
            'address' => 'Quận 1, TP.HCM', 'addressSource' => 'PARTNER_PORTAL',
            'addressVerifiedAt' => '2026-10-27 00:00:00', 'description' => 'Mô tả',
            'reconciliationStatus' => 'PARTNER_DECLARED',
            'imagesJson' => '[{"path":"/uploads/properties/a.webp","alt":"Ảnh","sort":1,"cover":true}]',
            'latitude' => null, 'longitude' => null, 'coordinatesSource' => null, 'coordinatesVerifiedAt' => null,
            'taxRate' => '8.00', 'serviceFeeRate' => '5.00',
            'checkInTime' => '14:00:00', 'checkOutTime' => '12:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rooms(): array
    {
        return [[
            'bedsJson' => '[{"type":"DOUBLE","quantity":1}]',
            'imagesJson' => '[{"path":"/uploads/properties/room.webp","alt":"Phòng","sort":1,"cover":true}]',
        ]];
    }

    /** @return array{hasCancellationPolicy: bool, hasProduct: bool, priceDays: int, inventoryDays: int, hasCrossPropertyRelation: bool} */
    private function commercial(): array
    {
        return [
            'hasCancellationPolicy' => true, 'hasProduct' => true,
            'priceDays' => 30, 'inventoryDays' => 30, 'hasCrossPropertyRelation' => false,
        ];
    }
}

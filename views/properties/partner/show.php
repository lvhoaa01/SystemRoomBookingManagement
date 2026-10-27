<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selectedFacilityIds = array_map(static fn (array $facility): int => (int) $facility['id'], $propertyFacilities);
?>
<a class="back-link" href="<?= $e($baseUrl) ?>/partner/properties">← Danh sách cơ sở</a>
<section class="property-hero">
    <div><span class="status-badge status-badge--<?= strtolower($e($property['status'])) ?>"><?= $e($property['status']) ?></span><h1><?= $e($property['name']) ?></h1><p><?= $e($property['address']) ?></p></div>
    <div class="action-row"><a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit">Chỉnh sửa hồ sơ</a>
        <?php if ($property['status'] === 'DRAFT'): ?><form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/submit"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="button button--primary">Gửi duyệt</button></form><?php endif; ?>
    </div>
</section>

<div class="dashboard-layout">
    <div class="dashboard-main">
        <section class="panel"><div class="panel__heading"><h2>Loại phòng</h2><p>Phòng hoạt động cần có cấu hình giường và ảnh hợp lệ.</p></div>
            <?php if ($rooms === []): ?><p class="muted">Chưa có loại phòng.</p><?php endif; ?>
            <div class="room-list"><?php foreach ($rooms as $room): ?><article><div><strong><?= $e($room['name']) ?></strong><span><?= $e($room['code']) ?> · <?= (int) $room['maxGuests'] ?> khách · <?= $e($room['status']) ?></span></div><span><?= $e($room['areaM2'] ?? '—') ?> m²</span></article><?php endforeach; ?></div>
        </section>
        <section class="panel"><div class="panel__heading"><h2>Thêm loại phòng</h2></div>
            <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/rooms">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <div class="field-grid"><label class="field"><span>Mã phòng</span><input name="code" placeholder="DLX" required></label><label class="field"><span>Tên phòng</span><input name="name" required></label></div>
                <div class="field-grid field-grid--three"><label class="field"><span>Tổng khách</span><input type="number" min="1" name="maxGuests" value="2"></label><label class="field"><span>Người lớn</span><input type="number" min="1" name="maxAdults" value="2"></label><label class="field"><span>Trẻ em</span><input type="number" min="0" name="maxChildren" value="0"></label></div>
                <label class="field"><span>Cấu hình giường JSON</span><textarea name="bedsJson" rows="2">[{"type":"DOUBLE","quantity":1}]</textarea></label>
                <label class="field"><span>Ảnh phòng JSON</span><textarea name="imagesJson" rows="2">[]</textarea></label>
                <div class="field-grid"><label class="field"><span>Diện tích m²</span><input type="number" min="1" step="0.01" name="areaM2"></label><label class="field"><span>Trạng thái</span><select name="status"><option>ACTIVE</option><option>INACTIVE</option></select></label></div>
                <label class="check-line"><input type="checkbox" name="smokingAllowed" value="1"> Cho phép hút thuốc</label>
                <button class="button button--primary">Thêm loại phòng</button>
            </form>
        </section>
    </div>
    <aside class="dashboard-side">
        <section class="panel"><div class="panel__heading"><h2>Tiện nghi cơ sở</h2></div>
            <form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/facilities">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <div class="facility-list"><?php foreach ($propertyFacilityCatalog as $facility): ?><label><input type="checkbox" name="facilityIds[]" value="<?= (int) $facility['id'] ?>" <?= in_array((int) $facility['id'], $selectedFacilityIds, true) ? 'checked' : '' ?>> <?= $e($facility['name']) ?></label><?php endforeach; ?></div>
                <button class="button button--secondary button--block">Lưu tiện nghi</button>
            </form>
        </section>
        <section class="panel summary-list"><h2>Thông tin vận hành</h2><dl><div><dt>Nhận phòng</dt><dd><?= $e($property['checkInTime']) ?></dd></div><div><dt>Trả phòng</dt><dd><?= $e($property['checkOutTime']) ?></dd></div><div><dt>Thuế</dt><dd><?= $e($property['taxRate']) ?>%</dd></div><div><dt>Phí dịch vụ</dt><dd><?= $e($property['serviceFeeRate']) ?>%</dd></div></dl></section>
    </aside>
</div>

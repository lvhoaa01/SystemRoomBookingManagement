<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selectedFacilityIds = array_map(static fn (array $facility): int => (int) $facility['id'], $propertyFacilities);
$propertyImages = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
$propertyImages = is_array($propertyImages) ? $propertyImages : [];
$coverImage = null;
foreach ($propertyImages as $image) {
    if (is_array($image) && ($image['cover'] ?? false) === true) {
        $coverImage = $image;
        break;
    }
}
$activeRooms = array_filter($rooms, static fn (array $room): bool => $room['status'] === 'ACTIVE');
$completionChecks = [
    ['done' => trim((string) $property['description']) !== '', 'label' => 'Thông tin và mô tả cơ sở'],
    ['done' => $propertyImages !== [], 'label' => 'Ảnh đại diện chỗ nghỉ'],
    ['done' => $activeRooms !== [], 'label' => 'Ít nhất một phòng hoạt động'],
    ['done' => $propertyFacilities !== [], 'label' => 'Tiện nghi của cơ sở'],
];
$completed = count(array_filter($completionChecks, static fn (array $check): bool => $check['done']));
$completion = $completed * 25;
$status = (string) $property['status'];
$statusLabels = ['DRAFT' => 'Bản nháp', 'PENDING_REVIEW' => 'Đang chờ duyệt', 'CHANGES_REQUESTED' => 'Cần chỉnh sửa', 'ACTIVE' => 'Đang hoạt động', 'REJECTED' => 'Bị từ chối', 'SUSPENDED' => 'Tạm ngưng'];
?>
<nav class="breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/partner/properties">Cơ sở lưu trú</a><span>/</span><span aria-current="page"><?= $e($property['name']) ?></span></nav>

<section class="property-profile-hero">
    <div class="property-profile-hero__media">
        <?php if ($coverImage !== null): ?>
            <img src="<?= $e($baseUrl . $coverImage['path']) ?>" alt="<?= $e($coverImage['alt'] ?? $property['name']) ?>">
        <?php else: ?>
            <div class="property-profile-hero__placeholder"><span>SB</span><small>Chưa có ảnh bìa</small></div>
        <?php endif; ?>
    </div>
    <div class="property-profile-hero__content">
        <div class="profile-kicker"><span class="status-badge status-badge--<?= strtolower($e($status)) ?>"><?= $e($statusLabels[$status] ?? $status) ?></span><span><?= $e(str_replace('_', ' ', $property['type'])) ?></span></div>
        <h1><?= $e($property['name']) ?></h1>
        <p class="profile-address"><span aria-hidden="true">⌖</span><?= $e($property['address']) ?></p>
        <div class="profile-meta"><span>Mã cơ sở <strong><?= $e($property['code']) ?></strong></span><span>Cập nhật <strong><?= $e($property['updatedAt']) ?></strong></span></div>
    </div>
    <div class="property-profile-hero__actions">
        <a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit">Chỉnh sửa hồ sơ</a>
        <?php if ($status === 'DRAFT' || $status === 'CHANGES_REQUESTED'): ?>
            <form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/submit"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="button button--primary">Gửi kiểm duyệt</button></form>
        <?php endif; ?>
    </div>
</section>

<nav class="section-nav" aria-label="Nội dung cơ sở"><a href="#overview">Tổng quan</a><a href="#rooms">Loại phòng</a><a href="#facilities">Tiện nghi</a><a href="#operations">Vận hành</a></nav>

<section id="overview" class="overview-grid">
    <article class="panel completion-card">
        <div class="completion-ring" style="--progress: <?= $completion ?>" aria-label="Mức hoàn thiện <?= $completion ?> phần trăm"><strong><?= $completion ?>%</strong><span>hoàn thiện</span></div>
        <div><span class="eyebrow eyebrow--blue">Sẵn sàng đón khách</span><h2>Hoàn thiện hồ sơ của bạn</h2><p>Thông tin đầy đủ giúp quá trình kiểm duyệt nhanh hơn và khách dễ ra quyết định hơn.</p></div>
    </article>
    <article class="panel readiness-card">
        <div class="panel-title-row"><h2>Danh sách cần hoàn thiện</h2><span><?= $completed ?>/<?= count($completionChecks) ?></span></div>
        <ul class="readiness-list">
            <?php foreach ($completionChecks as $check): ?><li class="<?= $check['done'] ? 'is-done' : '' ?>"><span><?= $check['done'] ? '✓' : '○' ?></span><?= $e($check['label']) ?></li><?php endforeach; ?>
        </ul>
    </article>
</section>

<div class="dashboard-layout dashboard-layout--property">
    <div class="dashboard-main">
        <section id="rooms" class="panel section-panel">
            <div class="panel-title-row panel-title-row--bordered"><div><span class="eyebrow eyebrow--blue">Kho phòng</span><h2>Loại phòng</h2><p><?= count($rooms) ?> loại phòng · <?= count($activeRooms) ?> đang hoạt động</p></div><button class="button button--primary" type="button" data-toggle-target="new-room">＋ Thêm loại phòng</button></div>
            <?php if ($rooms === []): ?>
                <div class="empty-state empty-state--inline"><span class="empty-state__icon">▱</span><div><h3>Chưa có loại phòng</h3><p>Thêm phòng đầu tiên để cấu hình sức chứa, giường và tiện nghi.</p></div></div>
            <?php else: ?>
                <div class="room-cards">
                    <?php foreach ($rooms as $room): ?>
                        <?php
                        $selectedRoomFacilityIds = array_map(static fn (array $facility): int => (int) $facility['id'], $roomFacilities[(int) $room['id']] ?? []);
                        $roomImages = json_decode((string) $room['imagesJson'], true);
                        $roomImage = is_array($roomImages) && isset($roomImages[0]['path']) ? $roomImages[0] : null;
                        ?>
                        <details class="room-editor room-card">
                            <summary>
                                <span class="room-card__media"><?php if ($roomImage !== null): ?><img src="<?= $e($baseUrl . $roomImage['path']) ?>" alt="<?= $e($roomImage['alt'] ?? $room['name']) ?>"><?php else: ?><span>▱</span><?php endif; ?></span>
                                <span class="room-card__info"><strong><?= $e($room['name']) ?></strong><small><?= $e($room['code']) ?> · Tối đa <?= (int) $room['maxGuests'] ?> khách</small><span class="room-tags"><i><?= $e($room['areaM2'] ?: '—') ?> m²</i><i><?= count($selectedRoomFacilityIds) ?> tiện nghi</i></span></span>
                                <span class="status-badge status-badge--<?= strtolower($e($room['status'])) ?>"><?= $room['status'] === 'ACTIVE' ? 'Hoạt động' : 'Tạm ẩn' ?></span>
                                <span class="room-card__chevron" aria-hidden="true">⌄</span>
                            </summary>
                            <div class="room-card__editor">
                                <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/partner/rooms/<?= (int) $room['id'] ?>/update">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <div class="field-grid"><label class="field"><span>Mã phòng</span><input name="code" value="<?= $e($room['code']) ?>" required></label><label class="field"><span>Tên hiển thị</span><input name="name" value="<?= $e($room['name']) ?>" required></label></div>
                                    <label class="field"><span>Mô tả trải nghiệm phòng</span><textarea name="description" rows="3"><?= $e($room['description']) ?></textarea></label>
                                    <div class="field-grid field-grid--three"><label class="field"><span>Tổng khách</span><input type="number" min="1" name="maxGuests" value="<?= (int) $room['maxGuests'] ?>"></label><label class="field"><span>Người lớn</span><input type="number" min="1" name="maxAdults" value="<?= (int) $room['maxAdults'] ?>"></label><label class="field"><span>Trẻ em</span><input type="number" min="0" name="maxChildren" value="<?= (int) $room['maxChildren'] ?>"></label></div>
                                    <div class="field-grid"><label class="field"><span>Diện tích (m²)</span><input type="number" min="1" step="0.01" name="areaM2" value="<?= $e($room['areaM2']) ?>"></label><label class="field"><span>Trạng thái bán</span><select name="status"><option value="ACTIVE" <?= $room['status'] === 'ACTIVE' ? 'selected' : '' ?>>Đang hoạt động</option><option value="INACTIVE" <?= $room['status'] === 'INACTIVE' ? 'selected' : '' ?>>Tạm ẩn</option></select></label></div>
                                    <label class="check-card"><input type="checkbox" name="smokingAllowed" value="1" <?= (int) $room['smokingAllowed'] === 1 ? 'checked' : '' ?>><span><strong>Cho phép hút thuốc</strong><small>Hiển thị chính sách này cho khách.</small></span></label>
                                    <details class="advanced-fields"><summary>Cấu hình nâng cao</summary><label class="field"><span>Cấu hình giường JSON</span><textarea name="bedsJson" rows="2"><?= $e($room['bedsJson']) ?></textarea></label><label class="field"><span>Ảnh phòng JSON</span><textarea name="imagesJson" rows="2"><?= $e($room['imagesJson']) ?></textarea></label></details>
                                    <button class="button button--primary">Lưu thông tin phòng</button>
                                </form>
                                <form class="room-facilities" method="post" action="<?= $e($baseUrl) ?>/partner/rooms/<?= (int) $room['id'] ?>/facilities">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <div><strong>Tiện nghi trong phòng</strong><p class="muted">Chọn các tiện ích khách sẽ thấy khi xem phòng.</p></div>
                                    <div class="facility-grid"><?php foreach ($roomFacilityCatalog as $facility): ?><label class="facility-option"><input type="checkbox" name="facilityIds[]" value="<?= (int) $facility['id'] ?>" <?= in_array((int) $facility['id'], $selectedRoomFacilityIds, true) ? 'checked' : '' ?>><span>✓</span><?= $e($facility['name']) ?></label><?php endforeach; ?></div>
                                    <button class="button button--secondary">Lưu tiện nghi phòng</button>
                                </form>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="new-room" class="panel section-panel new-room-panel" hidden>
            <div class="panel-title-row panel-title-row--bordered"><div><span class="eyebrow eyebrow--blue">Phòng mới</span><h2>Thêm loại phòng</h2><p>Thông tin có thể được chỉnh sửa lại bất cứ lúc nào.</p></div><button class="icon-button" type="button" data-toggle-target="new-room" aria-label="Đóng">×</button></div>
            <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/rooms">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <div class="field-grid"><label class="field"><span>Mã phòng</span><input name="code" placeholder="Ví dụ: DLX" required></label><label class="field"><span>Tên phòng</span><input name="name" placeholder="Ví dụ: Deluxe hướng thành phố" required></label></div>
                <div class="field-grid field-grid--three"><label class="field"><span>Tổng khách</span><input type="number" min="1" name="maxGuests" value="2"></label><label class="field"><span>Người lớn</span><input type="number" min="1" name="maxAdults" value="2"></label><label class="field"><span>Trẻ em</span><input type="number" min="0" name="maxChildren" value="0"></label></div>
                <div class="field-grid"><label class="field"><span>Diện tích (m²)</span><input type="number" min="1" step="0.01" name="areaM2" placeholder="28"></label><label class="field"><span>Trạng thái bán</span><select name="status"><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Tạm ẩn</option></select></label></div>
                <input type="hidden" name="bedsJson" value='[{"type":"DOUBLE","quantity":1}]'><input type="hidden" name="imagesJson" value="[]">
                <label class="check-card"><input type="checkbox" name="smokingAllowed" value="1"><span><strong>Cho phép hút thuốc</strong><small>Khách sẽ nhìn thấy thông tin này.</small></span></label>
                <div class="form-actions"><button class="button button--secondary" type="button" data-toggle-target="new-room">Hủy</button><button class="button button--primary">Thêm loại phòng</button></div>
            </form>
        </section>
    </div>

    <aside class="dashboard-side">
        <section id="facilities" class="panel side-panel"><div class="panel__heading"><span class="eyebrow eyebrow--blue">Trải nghiệm khách</span><h2>Tiện nghi cơ sở</h2><p>Những tiện ích nổi bật tại chỗ nghỉ.</p></div>
            <form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/facilities">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <div class="facility-grid facility-grid--single"><?php foreach ($propertyFacilityCatalog as $facility): ?><label class="facility-option"><input type="checkbox" name="facilityIds[]" value="<?= (int) $facility['id'] ?>" <?= in_array((int) $facility['id'], $selectedFacilityIds, true) ? 'checked' : '' ?>><span>✓</span><?= $e($facility['name']) ?></label><?php endforeach; ?></div>
                <button class="button button--secondary button--block">Lưu tiện nghi</button>
            </form>
        </section>
        <section id="operations" class="panel summary-list side-panel"><div class="panel__heading"><span class="eyebrow eyebrow--blue">Thiết lập</span><h2>Thông tin vận hành</h2></div><dl><div><dt>Nhận phòng</dt><dd><?= $e(substr((string) $property['checkInTime'], 0, 5)) ?></dd></div><div><dt>Trả phòng</dt><dd><?= $e(substr((string) $property['checkOutTime'], 0, 5)) ?></dd></div><div><dt>Thuế</dt><dd><?= $e($property['taxRate']) ?>%</dd></div><div><dt>Phí dịch vụ</dt><dd><?= $e($property['serviceFeeRate']) ?>%</dd></div></dl><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit">Chỉnh sửa thiết lập →</a></section>
    </aside>
</div>

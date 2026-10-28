<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selectedFacilityIds = array_map(static fn (array $facility): int => (int) $facility['id'], $propertyFacilities);
$propertyImages = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
$propertyImages = is_array($propertyImages) ? $propertyImages : [];
$activeRooms = array_values(array_filter($rooms, static fn (array $room): bool => $room['status'] === 'ACTIVE'));
$checks = [
    ['done' => trim((string) $property['description']) !== '', 'label' => 'Thông tin cơ bản', 'help' => 'Tên, loại hình và mô tả'],
    ['done' => trim((string) $property['address']) !== '', 'label' => 'Địa chỉ', 'help' => 'Địa chỉ chỗ nghỉ'],
    ['done' => $propertyFacilities !== [], 'label' => 'Tiện nghi', 'help' => 'Tiện nghi của cơ sở'],
    ['done' => $propertyImages !== [], 'label' => 'Hình ảnh', 'help' => 'Ảnh thực tế của chỗ nghỉ'],
    ['done' => $activeRooms !== [], 'label' => 'Phòng hoạt động', 'help' => 'Ít nhất một loại phòng'],
];
$completed = count(array_filter($checks, static fn (array $check): bool => $check['done']));
$completion = (int) round($completed / count($checks) * 100);
$status = (string) $property['status'];
$statusLabels = ['DRAFT' => 'Bản nháp', 'PENDING_REVIEW' => 'Chờ duyệt', 'CHANGES_REQUESTED' => 'Cần chỉnh sửa', 'ACTIVE' => 'Đang hoạt động', 'REJECTED' => 'Bị từ chối', 'SUSPENDED' => 'Tạm ngưng'];
?>
<nav class="resource-breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/partner/properties">Cơ sở lưu trú</a><svg class="app-icon"><use href="#icon-chevron"/></svg><span aria-current="page"><?= $e($property['name']) ?></span></nav>

<header class="detail-header">
    <div><div class="detail-header__meta"><span class="status-label status-label--<?= strtolower($e($status)) ?>"><i></i><?= $e($statusLabels[$status] ?? $status) ?></span><span><?= $e($property['code']) ?></span></div><h1><?= $e($property['name']) ?></h1><p><svg class="app-icon"><use href="#icon-pin"/></svg><?= $e($property['address']) ?></p></div>
    <div class="detail-header__actions"><a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit"><svg class="app-icon"><use href="#icon-edit"/></svg>Chỉnh sửa</a><?php if (in_array($status, ['DRAFT', 'CHANGES_REQUESTED'], true)): ?><form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/submit"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button class="button button--primary"><svg class="app-icon"><use href="#icon-send"/></svg>Gửi duyệt</button></form><?php endif; ?></div>
</header>

<nav class="detail-tabs" aria-label="Các phần của cơ sở"><a class="is-active" href="#overview">Tổng quan</a><a href="#rooms">Phòng</a><a href="#facilities">Tiện nghi</a><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/commercial">Giá &amp; chính sách</a><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit#property-media">Hình ảnh</a></nav>

<div class="detail-layout">
    <main class="detail-main">
        <section id="overview" class="detail-surface completion-surface">
            <div class="surface-heading"><div><h2>Hoàn thiện thông tin</h2><p>Các mục cần thiết trước khi gửi cơ sở đi kiểm duyệt.</p></div><strong><?= $completion ?>%</strong></div>
            <div class="progress-track" aria-label="Hoàn thiện <?= $completion ?> phần trăm"><span style="width: <?= $completion ?>%"></span></div>
            <div class="completion-list"><?php foreach ($checks as $check): ?><div><svg class="app-icon <?= $check['done'] ? 'is-complete' : '' ?>"><use href="#icon-<?= $check['done'] ? 'check-circle' : 'circle' ?>"/></svg><span><strong><?= $e($check['label']) ?></strong><small><?= $e($check['help']) ?></small></span><svg class="app-icon completion-list__arrow"><use href="#icon-chevron"/></svg></div><?php endforeach; ?></div>
        </section>

        <section id="rooms" class="detail-surface rooms-surface">
            <div class="surface-heading surface-heading--bordered"><div><h2>Danh sách phòng</h2><p><?= count($rooms) ?> loại phòng · <?= count($activeRooms) ?> đang hoạt động</p></div><button class="button button--secondary button--small" type="button" data-toggle-target="new-room"><svg class="app-icon"><use href="#icon-plus"/></svg>Thêm loại phòng</button></div>
            <?php if ($rooms === []): ?>
                <div class="resource-empty resource-empty--small"><svg class="app-icon"><use href="#icon-bed"/></svg><h3>Chưa có loại phòng</h3><p>Thêm loại phòng đầu tiên để cấu hình sức chứa và tiện nghi.</p></div>
            <?php else: ?>
                <div class="room-table-header"><span>Loại phòng</span><span>Sức chứa</span><span>Diện tích</span><span>Trạng thái</span><span></span></div>
                <div class="room-records">
                    <?php foreach ($rooms as $room): ?>
                        <?php $selectedRoomFacilityIds = array_map(static fn (array $facility): int => (int) $facility['id'], $roomFacilities[(int) $room['id']] ?? []); ?>
                        <details class="room-record">
                            <summary><span><strong><?= $e($room['name']) ?></strong><small><?= $e($room['code']) ?></small></span><span><?= (int) $room['maxGuests'] ?> khách</span><span><?= $e($room['areaM2'] ?: '—') ?> m²</span><span><span class="status-label status-label--<?= $room['status'] === 'ACTIVE' ? 'active' : 'draft' ?>"><i></i><?= $room['status'] === 'ACTIVE' ? 'Hoạt động' : 'Tạm ẩn' ?></span></span><svg class="app-icon"><use href="#icon-chevron"/></svg></summary>
                            <div class="room-record__body">
                                <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/partner/rooms/<?= (int) $room['id'] ?>/update">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <div class="field-grid"><label class="field"><span>Mã phòng</span><input name="code" value="<?= $e($room['code']) ?>" required></label><label class="field"><span>Tên phòng</span><input name="name" value="<?= $e($room['name']) ?>" required></label></div>
                                    <label class="field"><span>Mô tả</span><textarea name="description" rows="3"><?= $e($room['description']) ?></textarea></label>
                                    <div class="field-grid field-grid--three"><label class="field"><span>Tổng khách</span><input type="number" min="1" name="maxGuests" value="<?= (int) $room['maxGuests'] ?>"></label><label class="field"><span>Người lớn</span><input type="number" min="1" name="maxAdults" value="<?= (int) $room['maxAdults'] ?>"></label><label class="field"><span>Trẻ em</span><input type="number" min="0" name="maxChildren" value="<?= (int) $room['maxChildren'] ?>"></label></div>
                                    <div class="field-grid"><label class="field"><span>Diện tích (m²)</span><input type="number" min="1" step="0.01" name="areaM2" value="<?= $e($room['areaM2']) ?>"></label><label class="field"><span>Trạng thái</span><select name="status"><option value="ACTIVE" <?= $room['status'] === 'ACTIVE' ? 'selected' : '' ?>>Đang hoạt động</option><option value="INACTIVE" <?= $room['status'] === 'INACTIVE' ? 'selected' : '' ?>>Tạm ẩn</option></select></label></div>
                                    <label class="check-row-simple"><input type="checkbox" name="smokingAllowed" value="1" <?= (int) $room['smokingAllowed'] === 1 ? 'checked' : '' ?>>Cho phép hút thuốc trong phòng</label>
                                    <details class="advanced-fields"><summary>Cấu hình nâng cao</summary><label class="field"><span>Cấu hình giường JSON</span><textarea name="bedsJson" rows="2"><?= $e($room['bedsJson']) ?></textarea></label><label class="field"><span>Ảnh phòng JSON</span><textarea name="imagesJson" rows="2"><?= $e($room['imagesJson']) ?></textarea></label></details>
                                    <div class="inline-actions"><button class="button button--primary button--small">Lưu thông tin phòng</button></div>
                                </form>
                                <form class="room-facility-form" method="post" action="<?= $e($baseUrl) ?>/partner/rooms/<?= (int) $room['id'] ?>/facilities">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><h3>Tiện nghi trong phòng</h3><div class="compact-check-grid"><?php foreach ($roomFacilityCatalog as $facility): ?><label><input type="checkbox" name="facilityIds[]" value="<?= (int) $facility['id'] ?>" <?= in_array((int) $facility['id'], $selectedRoomFacilityIds, true) ? 'checked' : '' ?>><?= $e($facility['name']) ?></label><?php endforeach; ?></div><button class="button button--secondary button--small">Lưu tiện nghi</button>
                                </form>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="new-room" class="detail-surface" hidden>
            <div class="surface-heading surface-heading--bordered"><div><h2>Thêm loại phòng</h2><p>Tạo thông tin cơ bản; bạn có thể bổ sung chi tiết sau.</p></div><button class="icon-button" type="button" data-toggle-target="new-room" aria-label="Đóng"><svg class="app-icon"><use href="#icon-plus"/></svg></button></div>
            <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/rooms"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><div class="field-grid"><label class="field"><span>Mã phòng</span><input name="code" placeholder="DLX" required></label><label class="field"><span>Tên phòng</span><input name="name" required></label></div><div class="field-grid field-grid--three"><label class="field"><span>Tổng khách</span><input type="number" min="1" name="maxGuests" value="2"></label><label class="field"><span>Người lớn</span><input type="number" min="1" name="maxAdults" value="2"></label><label class="field"><span>Trẻ em</span><input type="number" min="0" name="maxChildren" value="0"></label></div><div class="field-grid"><label class="field"><span>Diện tích (m²)</span><input type="number" min="1" step="0.01" name="areaM2"></label><label class="field"><span>Trạng thái</span><select name="status"><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Tạm ẩn</option></select></label></div><input type="hidden" name="bedsJson" value='[{"type":"DOUBLE","quantity":1}]'><input type="hidden" name="imagesJson" value="[]"><label class="check-row-simple"><input type="checkbox" name="smokingAllowed" value="1">Cho phép hút thuốc trong phòng</label><div class="inline-actions"><button class="button button--secondary" type="button" data-toggle-target="new-room">Hủy</button><button class="button button--primary">Thêm loại phòng</button></div></form>
        </section>

        <section id="facilities" class="detail-surface">
            <div class="surface-heading surface-heading--bordered"><div><h2>Tiện nghi cơ sở</h2><p>Chọn các tiện nghi khách có thể sử dụng tại chỗ nghỉ.</p></div></div>
            <form method="post" action="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/facilities"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><div class="compact-check-grid"><?php foreach ($propertyFacilityCatalog as $facility): ?><label><input type="checkbox" name="facilityIds[]" value="<?= (int) $facility['id'] ?>" <?= in_array((int) $facility['id'], $selectedFacilityIds, true) ? 'checked' : '' ?>><?= $e($facility['name']) ?></label><?php endforeach; ?></div><div class="inline-actions"><button class="button button--secondary button--small">Lưu tiện nghi</button></div></form>
        </section>
    </main>

    <aside class="detail-aside">
        <section class="detail-surface aside-summary"><h2>Tình trạng vận hành</h2><dl><div><dt><svg class="app-icon"><use href="#icon-bed"/></svg>Tổng số phòng</dt><dd><?= count($rooms) ?></dd></div><div><dt><svg class="app-icon"><use href="#icon-check-circle"/></svg>Phòng hoạt động</dt><dd><?= count($activeRooms) ?></dd></div><div><dt><svg class="app-icon"><use href="#icon-image"/></svg>Hình ảnh</dt><dd><?= count($propertyImages) ?></dd></div><div><dt><svg class="app-icon"><use href="#icon-star"/></svg>Hạng sao</dt><dd><?= $e($property['starRating'] ?? '—') ?></dd></div></dl></section>
        <section class="detail-surface aside-summary"><div class="aside-heading"><h2>Cài đặt vận hành</h2><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/edit">Chỉnh sửa</a></div><dl><div><dt><svg class="app-icon"><use href="#icon-clock"/></svg>Giờ nhận phòng</dt><dd><?= $e(substr((string) $property['checkInTime'], 0, 5)) ?></dd></div><div><dt><svg class="app-icon"><use href="#icon-clock"/></svg>Giờ trả phòng</dt><dd><?= $e(substr((string) $property['checkOutTime'], 0, 5)) ?></dd></div><div><dt>Thuế</dt><dd><?= $e($property['taxRate']) ?>%</dd></div><div><dt>Phí dịch vụ</dt><dd><?= $e($property['serviceFeeRate']) ?>%</dd></div></dl></section>
    </aside>
</div>

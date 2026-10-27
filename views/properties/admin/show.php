<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = (string) $property['status'];
$statusLabels = ['PENDING_REVIEW' => 'Chờ duyệt', 'ACTIVE' => 'Đang hoạt động', 'SUSPENDED' => 'Tạm ngưng', 'CHANGES_REQUESTED' => 'Cần chỉnh sửa', 'REJECTED' => 'Bị từ chối'];
$typeLabels = ['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'];
$checks = [
    ['label' => 'Chính sách hủy đang hoạt động', 'pass' => (bool) $commercial['hasCancellationPolicy'], 'value' => $commercial['hasCancellationPolicy'] ? 'Đạt' : 'Còn thiếu'],
    ['label' => 'Sản phẩm phòng hợp lệ', 'pass' => (bool) $commercial['hasProduct'], 'value' => $commercial['hasProduct'] ? 'Đạt' : 'Còn thiếu'],
    ['label' => 'Dữ liệu giá trong 30 ngày', 'pass' => (int) $commercial['priceDays'] >= 30, 'value' => (int) $commercial['priceDays'] . '/30 ngày'],
    ['label' => 'Tồn phòng trong 30 ngày', 'pass' => (int) $commercial['inventoryDays'] >= 30, 'value' => (int) $commercial['inventoryDays'] . '/30 ngày'],
    ['label' => 'Không có liên kết chéo cơ sở', 'pass' => !(bool) $commercial['hasCrossPropertyRelation'], 'value' => $commercial['hasCrossPropertyRelation'] ? 'Cần kiểm tra' : 'Đạt'],
];
$passedChecks = count(array_filter($checks, static fn (array $check): bool => $check['pass']));
$actions = match ($status) {
    'PENDING_REVIEW' => ['APPROVE' => ['Phê duyệt', 'Cho phép cơ sở chuyển sang hoạt động.'], 'REQUEST_CHANGES' => ['Yêu cầu chỉnh sửa', 'Trả hồ sơ về đối tác để bổ sung.'], 'REJECT' => ['Từ chối', 'Không chấp nhận hồ sơ hiện tại.']],
    'ACTIVE' => ['SUSPEND' => ['Tạm ngưng', 'Dừng hiển thị cơ sở với khách.']],
    'SUSPENDED' => ['RESTORE' => ['Khôi phục', 'Đưa cơ sở hoạt động trở lại.']],
    default => [],
};
?>
<nav class="resource-breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/admin/properties/review">Kiểm duyệt cơ sở</a><svg class="app-icon"><use href="#icon-chevron"/></svg><span aria-current="page"><?= $e($property['name']) ?></span></nav>

<header class="detail-header"><div><div class="detail-header__meta"><span class="status-label status-label--<?= strtolower($e($status)) ?>"><i></i><?= $e($statusLabels[$status] ?? $status) ?></span><span><?= $e($property['code']) ?></span></div><h1><?= $e($property['name']) ?></h1><p><svg class="app-icon"><use href="#icon-pin"/></svg><?= $e($property['address']) ?></p></div><a class="button button--secondary" href="<?= $e($baseUrl) ?>/admin/properties/review">Quay lại hàng đợi</a></header>

<nav class="detail-tabs" aria-label="Nội dung kiểm duyệt"><a class="is-active" href="#profile">Hồ sơ</a><a href="#commercial">Khả năng bán</a><a href="#active-rooms">Phòng</a></nav>

<div class="review-detail-layout">
    <main class="detail-main">
        <section id="profile" class="detail-surface">
            <div class="surface-heading surface-heading--bordered"><div><h2>Thông tin cơ sở lưu trú</h2><p>Dữ liệu do đối tác khai báo trong hồ sơ.</p></div></div>
            <dl class="profile-data-grid"><div><dt>Loại hình</dt><dd><?= $e($typeLabels[$property['type']] ?? $property['type']) ?></dd></div><div><dt>Hạng sao</dt><dd><?= $property['starRating'] !== null ? $e($property['starRating']) . ' sao' : 'Chưa xếp hạng' ?></dd></div><div><dt>Giờ nhận phòng</dt><dd><?= $e(substr((string) $property['checkInTime'], 0, 5)) ?></dd></div><div><dt>Giờ trả phòng</dt><dd><?= $e(substr((string) $property['checkOutTime'], 0, 5)) ?></dd></div><div><dt>Thuế</dt><dd><?= $e($property['taxRate']) ?>%</dd></div><div><dt>Phí dịch vụ</dt><dd><?= $e($property['serviceFeeRate']) ?>%</dd></div><div><dt>Nguồn tên</dt><dd><?= $e($property['nameSource']) ?></dd></div><div><dt>Nguồn địa chỉ</dt><dd><?= $e($property['addressSource']) ?></dd></div></dl>
            <div class="profile-description"><span>Mô tả từ đối tác</span><p><?= nl2br($e($property['description'])) ?></p></div>
        </section>

        <section id="commercial" class="detail-surface">
            <div class="surface-heading surface-heading--bordered"><div><h2>Khả năng bán trong 30 ngày</h2><p>Kiểm tra tự động các điều kiện cần thiết để kích hoạt.</p></div><span class="check-summary"><?= $passedChecks ?>/<?= count($checks) ?> đạt</span></div>
            <div class="review-check-list"><?php foreach ($checks as $check): ?><div><svg class="app-icon <?= $check['pass'] ? 'is-pass' : 'is-fail' ?>"><use href="#icon-<?= $check['pass'] ? 'check-circle' : 'circle' ?>"/></svg><span><?= $e($check['label']) ?></span><strong><?= $e($check['value']) ?></strong></div><?php endforeach; ?></div>
        </section>

        <section id="active-rooms" class="detail-surface">
            <div class="surface-heading surface-heading--bordered"><div><h2>Phòng đang hoạt động</h2><p><?= count($activeRooms) ?> loại phòng được đưa vào vận hành.</p></div></div>
            <?php if ($activeRooms === []): ?><div class="resource-empty resource-empty--small"><svg class="app-icon"><use href="#icon-bed"/></svg><h3>Chưa có phòng hoạt động</h3><p>Cơ sở chưa đáp ứng điều kiện phòng để phục vụ khách.</p></div><?php else: ?><div class="review-room-table"><div class="review-room-table__head"><span>Loại phòng</span><span>Sức chứa</span><span>Diện tích</span><span>Trạng thái</span></div><?php foreach ($activeRooms as $room): ?><div><span><strong><?= $e($room['name']) ?></strong><small><?= $e($room['code']) ?></small></span><span><?= (int) $room['maxGuests'] ?> khách</span><span><?= $e($room['areaM2'] ?: '—') ?> m²</span><span class="status-label status-label--active"><i></i>Hoạt động</span></div><?php endforeach; ?></div><?php endif; ?>
        </section>
    </main>

    <aside class="review-decision-panel">
        <section class="detail-surface"><h2>Quyết định kiểm duyệt</h2><p class="decision-help">Chọn kết quả sau khi đã xem toàn bộ hồ sơ.</p>
        <?php if ($actions === []): ?><div class="form-note"><svg class="app-icon"><use href="#icon-review"/></svg><span>Không có hành động phù hợp với trạng thái hiện tại.</span></div><?php else: ?><form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>/review"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><fieldset class="review-actions"><legend>Kết quả</legend><?php foreach ($actions as $code => [$label, $description]): ?><label><input type="radio" name="action" value="<?= $e($code) ?>" <?= array_key_first($actions) === $code ? 'checked' : '' ?>><span><strong><?= $e($label) ?></strong><small><?= $e($description) ?></small></span></label><?php endforeach; ?></fieldset><label class="field"><span>Ghi chú cho đối tác</span><textarea name="note" rows="5" maxlength="1000" placeholder="Nêu rõ nội dung cần lưu ý..."></textarea><small>Bắt buộc khi yêu cầu sửa, từ chối hoặc tạm ngưng.</small></label><button class="button button--primary button--block">Xác nhận quyết định</button></form><?php endif; ?>
        <p class="decision-audit">Quyết định sẽ được ghi vào nhật ký kiểm toán.</p></section>
    </aside>
</div>

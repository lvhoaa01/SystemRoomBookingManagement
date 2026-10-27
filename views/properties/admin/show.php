<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$images = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
$images = is_array($images) ? $images : [];
$cover = null;
foreach ($images as $image) if (is_array($image) && ($image['cover'] ?? false) === true) { $cover = $image; break; }
$status = (string) $property['status'];
$statusLabels = ['PENDING_REVIEW' => 'Đang chờ duyệt', 'ACTIVE' => 'Đang hoạt động', 'SUSPENDED' => 'Tạm ngưng', 'CHANGES_REQUESTED' => 'Cần chỉnh sửa', 'REJECTED' => 'Bị từ chối'];
$checks = [
    ['label' => 'Chính sách hủy đang hoạt động', 'pass' => (bool) $commercial['hasCancellationPolicy'], 'value' => $commercial['hasCancellationPolicy'] ? 'Đạt' : 'Thiếu'],
    ['label' => 'Sản phẩm phòng hợp lệ', 'pass' => (bool) $commercial['hasProduct'], 'value' => $commercial['hasProduct'] ? 'Đạt' : 'Thiếu'],
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
<nav class="breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/admin/properties/review">Hàng đợi duyệt</a><span>/</span><span aria-current="page"><?= $e($property['name']) ?></span></nav>

<section class="review-profile-header">
    <div class="review-profile-header__media"><?php if ($cover !== null): ?><img src="<?= $e($baseUrl . $cover['path']) ?>" alt="<?= $e($cover['alt'] ?? $property['name']) ?>"><?php else: ?><span>SB</span><?php endif; ?></div>
    <div><div class="profile-kicker"><span class="status-badge status-badge--<?= strtolower($e($status)) ?>"><?= $e($statusLabels[$status] ?? $status) ?></span><span>Hồ sơ <?= $e($property['code']) ?></span></div><h1><?= $e($property['name']) ?></h1><p>⌖ <?= $e($property['address']) ?></p></div>
    <div class="review-score"><span>Mức sẵn sàng</span><strong><?= $passedChecks ?>/<?= count($checks) ?></strong><small>kiểm tra đạt</small></div>
</section>

<div class="review-layout">
    <main class="review-main">
        <section class="panel review-section">
            <div class="panel-title-row panel-title-row--bordered"><div><span class="eyebrow eyebrow--blue">Đối tác khai báo</span><h2>Thông tin cơ sở lưu trú</h2><p>Đối chiếu tính chính xác và chất lượng nội dung trước khi duyệt.</p></div></div>
            <div class="profile-facts"><div><span>Loại hình</span><strong><?= $e(str_replace('_', ' ', $property['type'])) ?></strong></div><div><span>Hạng sao</span><strong><?= $property['starRating'] !== null ? $e($property['starRating']) . ' ★' : 'Chưa xếp hạng' ?></strong></div><div><span>Nhận / trả phòng</span><strong><?= $e(substr((string) $property['checkInTime'], 0, 5)) ?> / <?= $e(substr((string) $property['checkOutTime'], 0, 5)) ?></strong></div><div><span>Thuế / phí</span><strong><?= $e($property['taxRate']) ?>% / <?= $e($property['serviceFeeRate']) ?>%</strong></div></div>
            <div class="description-block"><span>Mô tả từ đối tác</span><p><?= nl2br($e($property['description'])) ?></p></div>
            <div class="source-grid"><div><span>Nguồn tên</span><strong><?= $e($property['nameSource']) ?></strong></div><div><span>Nguồn địa chỉ</span><strong><?= $e($property['addressSource']) ?></strong></div></div>
        </section>

        <section class="panel review-section">
            <div class="panel-title-row panel-title-row--bordered"><div><span class="eyebrow eyebrow--blue">Kiểm tra tự động</span><h2>Khả năng bán trong 30 ngày</h2><p>Các điều kiện thương mại cần đạt trước khi kích hoạt.</p></div><span><?= $passedChecks ?>/<?= count($checks) ?> đạt</span></div>
            <div class="automated-checks"><?php foreach ($checks as $check): ?><article class="automated-check <?= $check['pass'] ? 'is-pass' : 'is-fail' ?>"><span class="automated-check__icon"><?= $check['pass'] ? '✓' : '!' ?></span><div><strong><?= $e($check['label']) ?></strong><small><?= $e($check['value']) ?></small></div></article><?php endforeach; ?></div>
        </section>

        <section class="panel review-section">
            <div class="panel-title-row panel-title-row--bordered"><div><span class="eyebrow eyebrow--blue">Kho phòng</span><h2>Phòng đang hoạt động</h2><p><?= count($activeRooms) ?> loại phòng được đối tác đưa vào vận hành.</p></div></div>
            <?php if ($activeRooms === []): ?><div class="alert alert--error">Chưa có phòng hoạt động để phục vụ khách.</div><?php else: ?><div class="admin-room-grid"><?php foreach ($activeRooms as $room): ?><article><span class="admin-room-grid__icon">▱</span><div><strong><?= $e($room['name']) ?></strong><small><?= $e($room['code']) ?> · <?= (int) $room['maxGuests'] ?> khách · <?= $e($room['areaM2'] ?: '—') ?> m²</small></div><span class="status-badge status-badge--active">Hoạt động</span></article><?php endforeach; ?></div><?php endif; ?>
        </section>
    </main>

    <aside class="review-decision">
        <section class="panel decision-card">
            <span class="eyebrow eyebrow--blue">Quyết định</span><h2>Hoàn tất kiểm duyệt</h2><p>Chọn kết quả phù hợp sau khi đã kiểm tra toàn bộ hồ sơ.</p>
            <?php if ($actions === []): ?><div class="info-banner"><span>i</span><p>Trạng thái hiện tại không có hành động kiểm duyệt.</p></div><?php else: ?>
                <form class="form-stack" method="post" action="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>/review">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <fieldset class="decision-options"><legend>Kết quả kiểm duyệt</legend><?php foreach ($actions as $code => [$label, $description]): ?><label class="decision-option decision-option--<?= strtolower($code) ?>"><input type="radio" name="action" value="<?= $e($code) ?>" <?= array_key_first($actions) === $code ? 'checked' : '' ?>><span class="decision-option__control"></span><span><strong><?= $e($label) ?></strong><small><?= $e($description) ?></small></span></label><?php endforeach; ?></fieldset>
                    <label class="field"><span>Ghi chú cho đối tác</span><textarea name="note" rows="5" maxlength="1000" placeholder="Nêu rõ nội dung cần lưu ý hoặc chỉnh sửa..."></textarea><small>Bắt buộc khi yêu cầu chỉnh sửa, từ chối hoặc tạm ngưng.</small></label>
                    <button class="button button--primary button--block">Xác nhận quyết định</button>
                </form>
            <?php endif; ?>
            <p class="decision-card__warning">Quyết định được ghi vào nhật ký kiểm toán và có thể ảnh hưởng đến khả năng hiển thị của cơ sở.</p>
        </section>
    </aside>
</div>

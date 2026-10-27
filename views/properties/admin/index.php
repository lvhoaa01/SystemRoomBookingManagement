<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$todayCount = count(array_filter($properties, static fn (array $property): bool => str_starts_with((string) $property['updatedAt'], date('Y-m-d'))));
$typeCount = count(array_unique(array_column($properties, 'type')));
$cover = static function (array $property): ?array {
    $images = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
    if (!is_array($images)) return null;
    foreach ($images as $image) if (is_array($image) && ($image['cover'] ?? false) === true) return $image;
    return null;
};
?>
<section class="admin-hero">
    <div><span class="eyebrow">Trung tâm kiểm duyệt</span><h1>Hồ sơ chờ xét duyệt</h1><p>Kiểm tra chất lượng thông tin, khả năng bán và tính nhất quán trước khi cơ sở xuất hiện với khách hàng.</p></div>
    <div class="admin-hero__badge"><strong><?= count($properties) ?></strong><span>hồ sơ đang chờ</span></div>
</section>

<section class="metric-grid metric-grid--admin" aria-label="Tổng quan hàng đợi">
    <article class="metric-card"><span class="metric-card__icon">▤</span><div><strong><?= count($properties) ?></strong><span>Tổng cần duyệt</span></div></article>
    <article class="metric-card"><span class="metric-card__icon metric-card__icon--warning">◷</span><div><strong><?= $todayCount ?></strong><span>Cập nhật hôm nay</span></div></article>
    <article class="metric-card"><span class="metric-card__icon metric-card__icon--success">⌂</span><div><strong><?= $typeCount ?></strong><span>Loại hình lưu trú</span></div></article>
</section>

<section class="panel review-queue" data-property-browser>
    <div class="section-heading section-heading--compact"><div><span class="eyebrow eyebrow--blue">Hàng đợi</span><h2>Duyệt theo thứ tự cập nhật</h2><p>Mở hồ sơ để xem toàn bộ dữ liệu và đưa ra quyết định.</p></div>
        <?php if ($properties !== []): ?><label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg><span class="visually-hidden">Tìm hồ sơ</span><input type="search" placeholder="Tìm tên, mã hoặc địa chỉ" data-property-search></label><?php endif; ?>
    </div>
    <?php if ($properties === []): ?>
        <div class="empty-state empty-state--illustrated empty-state--borderless"><span class="empty-state__icon">✓</span><h2>Hàng đợi đã trống</h2><p>Không có hồ sơ nào cần kiểm duyệt ở thời điểm này.</p></div>
    <?php else: ?>
        <div class="review-list">
            <?php foreach ($properties as $index => $property): ?>
                <?php $image = $cover($property); ?>
                <article class="review-row" data-property-card data-status="PENDING_REVIEW" data-search="<?= $e(mb_strtolower($property['name'] . ' ' . $property['code'] . ' ' . $property['address'])) ?>">
                    <span class="review-row__order"><?= $index + 1 ?></span>
                    <div class="review-row__media"><?php if ($image !== null): ?><img src="<?= $e($baseUrl . $image['path']) ?>" alt="<?= $e($image['alt'] ?? $property['name']) ?>"><?php else: ?><span>SB</span><?php endif; ?></div>
                    <div class="review-row__content"><span class="property-type"><?= $e(str_replace('_', ' ', $property['type'])) ?> · <?= $e($property['code']) ?></span><h3><?= $e($property['name']) ?></h3><p>⌖ <?= $e($property['address']) ?></p></div>
                    <div class="review-row__time"><small>Cập nhật gần nhất</small><strong><?= $e($property['updatedAt']) ?></strong></div>
                    <a class="button button--secondary" href="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>">Bắt đầu duyệt →</a>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="empty-state empty-state--compact" data-property-no-results hidden><h3>Không tìm thấy hồ sơ</h3><p>Hãy thử từ khóa khác.</p></div>
    <?php endif; ?>
</section>

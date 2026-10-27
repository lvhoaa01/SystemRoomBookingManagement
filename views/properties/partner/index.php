<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabels = [
    'DRAFT' => 'Bản nháp',
    'PENDING_REVIEW' => 'Đang chờ duyệt',
    'CHANGES_REQUESTED' => 'Cần chỉnh sửa',
    'ACTIVE' => 'Đang hoạt động',
    'REJECTED' => 'Bị từ chối',
    'SUSPENDED' => 'Tạm ngưng',
];
$statusCounts = array_count_values(array_column($properties, 'status'));
$attentionCount = ($statusCounts['CHANGES_REQUESTED'] ?? 0) + ($statusCounts['REJECTED'] ?? 0);
$cover = static function (array $property): ?array {
    $images = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
    if (!is_array($images)) {
        return null;
    }
    foreach ($images as $image) {
        if (is_array($image) && ($image['cover'] ?? false) === true && isset($image['path'])) {
            return $image;
        }
    }
    return null;
};
?>
<section class="workspace-hero">
    <div class="workspace-hero__content">
        <span class="eyebrow">Không gian đối tác</span>
        <h1>Xin chào, <?= $e($organization->name) ?></h1>
        <p>Quản lý hồ sơ, phòng và trạng thái xét duyệt của mọi chỗ nghỉ trong một màn hình.</p>
        <a class="button button--light" href="<?= $e($baseUrl) ?>/partner/properties/create">
            <span aria-hidden="true">＋</span> Thêm cơ sở lưu trú
        </a>
    </div>
    <div class="workspace-hero__art" aria-hidden="true">
        <svg viewBox="0 0 320 190">
            <path d="M18 166h286"/><path d="M46 165V62h98v103M144 89h95v76M74 88h18M108 88h18M74 116h18M108 116h18M74 143h18M108 143h18M169 112h18M205 112h18M169 140h18M205 140h18"/>
            <path d="M34 62h122L128 35H62zM224 89l-32-28-32 28M258 165v-35m-16 0h31M269 130l-11-31-11 31"/>
        </svg>
    </div>
</section>

<section class="metric-grid" aria-label="Tổng quan cơ sở lưu trú">
    <article class="metric-card metric-card--primary"><span class="metric-card__icon">⌂</span><div><strong><?= count($properties) ?></strong><span>Tổng cơ sở</span></div></article>
    <article class="metric-card"><span class="metric-card__icon metric-card__icon--success">✓</span><div><strong><?= (int) ($statusCounts['ACTIVE'] ?? 0) ?></strong><span>Đang hoạt động</span></div></article>
    <article class="metric-card"><span class="metric-card__icon metric-card__icon--warning">◷</span><div><strong><?= (int) ($statusCounts['PENDING_REVIEW'] ?? 0) ?></strong><span>Chờ kiểm duyệt</span></div></article>
    <article class="metric-card"><span class="metric-card__icon metric-card__icon--danger">!</span><div><strong><?= $attentionCount ?></strong><span>Cần bạn xử lý</span></div></article>
</section>

<section class="content-section" data-property-browser>
    <div class="section-heading">
        <div><span class="eyebrow eyebrow--blue">Danh mục</span><h2>Cơ sở lưu trú của bạn</h2><p>Chọn một cơ sở để tiếp tục hoàn thiện hoặc theo dõi vận hành.</p></div>
        <?php if ($properties !== []): ?>
            <label class="search-box">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>
                <span class="visually-hidden">Tìm cơ sở</span>
                <input type="search" placeholder="Tìm theo tên hoặc địa chỉ" data-property-search>
            </label>
        <?php endif; ?>
    </div>

    <?php if ($properties === []): ?>
        <div class="empty-state empty-state--illustrated">
            <span class="empty-state__icon" aria-hidden="true">⌂</span>
            <h2>Bắt đầu với cơ sở đầu tiên</h2>
            <p>Tạo hồ sơ chỗ nghỉ, bổ sung phòng và tiện nghi rồi gửi cho đội ngũ StayBooking kiểm duyệt.</p>
            <a class="button button--primary" href="<?= $e($baseUrl) ?>/partner/properties/create">Tạo cơ sở lưu trú</a>
        </div>
    <?php else: ?>
        <div class="filter-tabs" role="group" aria-label="Lọc theo trạng thái">
            <button class="filter-tab is-active" type="button" data-property-filter="ALL">Tất cả <span><?= count($properties) ?></span></button>
            <button class="filter-tab" type="button" data-property-filter="ACTIVE">Hoạt động <span><?= (int) ($statusCounts['ACTIVE'] ?? 0) ?></span></button>
            <button class="filter-tab" type="button" data-property-filter="PENDING_REVIEW">Chờ duyệt <span><?= (int) ($statusCounts['PENDING_REVIEW'] ?? 0) ?></span></button>
            <button class="filter-tab" type="button" data-property-filter="DRAFT">Bản nháp <span><?= (int) ($statusCounts['DRAFT'] ?? 0) ?></span></button>
        </div>
        <div class="property-grid property-grid--modern">
            <?php foreach ($properties as $property): ?>
                <?php $image = $cover($property); $status = (string) $property['status']; ?>
                <article class="property-card property-card--modern" data-property-card data-status="<?= $e($status) ?>" data-search="<?= $e(mb_strtolower($property['name'] . ' ' . $property['address'])) ?>">
                    <div class="property-card__media">
                        <?php if ($image !== null): ?>
                            <img src="<?= $e($baseUrl . $image['path']) ?>" alt="<?= $e($image['alt'] ?? $property['name']) ?>">
                        <?php else: ?>
                            <div class="property-card__placeholder" aria-label="Chưa có ảnh bìa"><span>SB</span><small>Thêm ảnh bìa</small></div>
                        <?php endif; ?>
                        <span class="status-badge status-badge--<?= strtolower($e($status)) ?>"><?= $e($statusLabels[$status] ?? $status) ?></span>
                    </div>
                    <div class="property-card__body">
                        <div class="property-card__title-row">
                            <div><span class="property-type"><?= $e(str_replace('_', ' ', $property['type'])) ?></span><h3><?= $e($property['name']) ?></h3></div>
                            <?php if ((float) ($property['starRating'] ?? 0) > 0): ?><span class="rating-pill"><?= $e($property['starRating']) ?> ★</span><?php endif; ?>
                        </div>
                        <p class="property-address"><span aria-hidden="true">⌖</span><?= $e($property['address']) ?></p>
                        <div class="property-card__footer">
                            <span class="property-code">Mã: <?= $e($property['code']) ?></span>
                            <a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>">Quản lý <span aria-hidden="true">→</span></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="empty-state empty-state--compact" data-property-no-results hidden><h3>Không tìm thấy cơ sở phù hợp</h3><p>Hãy thử từ khóa hoặc bộ lọc khác.</p></div>
    <?php endif; ?>
</section>

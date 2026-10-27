<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$typeLabels = ['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'];
$cover = static function (array $property): ?array {
    $images = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
    if (!is_array($images)) return null;
    foreach ($images as $image) if (is_array($image) && ($image['cover'] ?? false) === true && isset($image['path'])) return $image;
    return null;
};
?>
<header class="resource-header"><div><h1>Kiểm duyệt cơ sở</h1><p>Xem xét hồ sơ đối tác trước khi cơ sở được phép hoạt động.</p></div><span class="queue-count"><strong><?= count($properties) ?></strong> hồ sơ đang chờ</span></header>

<section class="resource-surface" data-property-browser>
    <div class="resource-toolbar"><label class="toolbar-search"><svg class="app-icon"><use href="#icon-search"/></svg><span class="visually-hidden">Tìm hồ sơ</span><input type="search" placeholder="Tìm theo tên, mã hoặc địa chỉ" data-property-search></label><span class="toolbar-note">Sắp xếp theo thời gian cập nhật gần nhất</span></div>
    <?php if ($properties === []): ?>
        <div class="resource-empty"><svg class="app-icon"><use href="#icon-check-circle"/></svg><h2>Không có hồ sơ chờ duyệt</h2><p>Hàng đợi kiểm duyệt hiện đã được xử lý hết.</p></div>
    <?php else: ?>
        <div class="resource-table-wrap"><table class="resource-table review-table"><thead><tr><th>Cơ sở lưu trú</th><th>Loại hình</th><th>Trạng thái</th><th>Cập nhật gần nhất</th><th><span class="visually-hidden">Thao tác</span></th></tr></thead><tbody>
        <?php foreach ($properties as $property): ?><?php $image = $cover($property); ?><tr data-property-card data-status="PENDING_REVIEW" data-search="<?= $e(mb_strtolower($property['name'] . ' ' . $property['code'] . ' ' . $property['address'])) ?>"><td><div class="resource-name"><span class="resource-thumbnail"><?php if ($image !== null): ?><img src="<?= $e($baseUrl . $image['path']) ?>" alt=""><?php else: ?><svg class="app-icon"><use href="#icon-building"/></svg><?php endif; ?></span><span><a href="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>"><?= $e($property['name']) ?></a><small><?= $e($property['code']) ?> · <?= $e($property['address']) ?></small></span></div></td><td><?= $e($typeLabels[$property['type']] ?? $property['type']) ?></td><td><span class="status-label status-label--pending_review"><i></i>Chờ duyệt</span></td><td><time><?= $e($property['updatedAt']) ?></time></td><td class="resource-actions"><a class="button button--secondary button--small" href="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>">Bắt đầu duyệt</a></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="resource-empty resource-empty--search" data-property-no-results hidden><svg class="app-icon"><use href="#icon-search"/></svg><h2>Không tìm thấy hồ sơ</h2><p>Hãy thử một từ khóa khác.</p></div>
        <footer class="resource-footer">Hiển thị <strong data-visible-count><?= count($properties) ?></strong> trong tổng số <?= count($properties) ?> hồ sơ</footer>
    <?php endif; ?>
</section>

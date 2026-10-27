<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabels = ['DRAFT' => 'Bản nháp', 'PENDING_REVIEW' => 'Chờ duyệt', 'CHANGES_REQUESTED' => 'Cần chỉnh sửa', 'ACTIVE' => 'Đang hoạt động', 'REJECTED' => 'Bị từ chối', 'SUSPENDED' => 'Tạm ngưng'];
$typeLabels = ['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'];
$formatDate = static fn (mixed $value): string => date('d/m/Y H:i', strtotime((string) $value));
$cover = static function (array $property): ?array {
    $images = json_decode((string) ($property['imagesJson'] ?? '[]'), true);
    if (!is_array($images)) return null;
    foreach ($images as $image) if (is_array($image) && ($image['cover'] ?? false) === true && isset($image['path'])) return $image;
    return null;
};
?>
<header class="resource-header">
    <div><h1>Cơ sở lưu trú</h1><p>Quản lý thông tin, phòng và trạng thái kiểm duyệt của các chỗ nghỉ.</p></div>
    <a class="button button--primary" href="<?= $e($baseUrl) ?>/partner/properties/create"><svg class="app-icon"><use href="#icon-plus"/></svg>Thêm cơ sở</a>
</header>

<section class="resource-surface" data-property-browser>
    <div class="resource-toolbar">
        <label class="toolbar-search"><svg class="app-icon"><use href="#icon-search"/></svg><span class="visually-hidden">Tìm cơ sở</span><input type="search" placeholder="Tìm theo tên, mã hoặc địa chỉ" data-property-search></label>
        <label class="toolbar-select"><span class="visually-hidden">Lọc trạng thái</span><select data-property-status-select><option value="ALL">Tất cả trạng thái</option><?php foreach ($statusLabels as $code => $label): ?><option value="<?= $e($code) ?>"><?= $e($label) ?></option><?php endforeach; ?></select></label>
    </div>

    <?php if ($properties === []): ?>
        <div class="resource-empty"><svg class="app-icon"><use href="#icon-building"/></svg><h2>Chưa có cơ sở lưu trú</h2><p>Tạo cơ sở đầu tiên để bắt đầu cấu hình phòng và tiện nghi.</p><a class="button button--primary" href="<?= $e($baseUrl) ?>/partner/properties/create">Thêm cơ sở</a></div>
    <?php else: ?>
        <div class="resource-table-wrap">
            <table class="resource-table">
                <thead><tr><th>Cơ sở lưu trú</th><th>Loại hình</th><th>Trạng thái</th><th>Cập nhật gần nhất</th><th><span class="visually-hidden">Thao tác</span></th></tr></thead>
                <tbody>
                <?php foreach ($properties as $property): ?>
                    <?php $image = $cover($property); $status = (string) $property['status']; ?>
                    <tr data-property-card data-status="<?= $e($status) ?>" data-search="<?= $e(mb_strtolower($property['name'] . ' ' . $property['code'] . ' ' . $property['address'])) ?>">
                        <td><div class="resource-name"><span class="resource-thumbnail"><?php if ($image !== null): ?><img src="<?= $e($baseUrl . $image['path']) ?>" alt=""><?php else: ?><svg class="app-icon"><use href="#icon-building"/></svg><?php endif; ?></span><span><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>"><?= $e($property['name']) ?></a><small><?= $e($property['code']) ?> · <?= $e($property['address']) ?></small></span></div></td>
                        <td><?= $e($typeLabels[$property['type']] ?? $property['type']) ?></td>
                        <td><span class="status-label status-label--<?= strtolower($e($status)) ?>"><i></i><?= $e($statusLabels[$status] ?? $status) ?></span></td>
                        <td><time><?= $e($formatDate($property['updatedAt'])) ?></time></td>
                        <td class="resource-actions"><a class="icon-button" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>" aria-label="Quản lý <?= $e($property['name']) ?>"><svg class="app-icon"><use href="#icon-chevron"/></svg></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="resource-empty resource-empty--search" data-property-no-results hidden><svg class="app-icon"><use href="#icon-search"/></svg><h2>Không tìm thấy cơ sở</h2><p>Hãy thử từ khóa hoặc trạng thái khác.</p></div>
        <footer class="resource-footer">Hiển thị <strong data-visible-count><?= count($properties) ?></strong> trong tổng số <?= count($properties) ?> cơ sở</footer>
    <?php endif; ?>
</section>

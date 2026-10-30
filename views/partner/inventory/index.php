<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$allRows = array_merge(...array_values($inventoryByRoom ?: [[]]));
$totalOpen = array_sum(array_map(static fn (array $row): int => (int) $row['totalQuantity'], $allRows));
$totalHeld = array_sum(array_map(static fn (array $row): int => (int) $row['heldQuantity'], $allRows));
$totalAvailable = $totalOpen - $totalHeld;
?>
<nav class="resource-breadcrumb" aria-label="Đường dẫn">
    <a href="<?= $e($baseUrl) ?>/partner/properties">Cơ sở lưu trú</a>
    <svg class="app-icon"><use href="#icon-chevron"/></svg>
    <a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>"><?= $e($property['name']) ?></a>
    <svg class="app-icon"><use href="#icon-chevron"/></svg><span aria-current="page">Tồn phòng</span>
</nav>

<header class="commercial-header">
    <div><span class="commercial-kicker">Inventory control</span><h1>Lịch tồn phòng</h1><p><?= $e($property['name']) ?> · Theo dõi tổng mở bán, số đang giữ và số còn khả dụng.</p></div>
    <div class="inline-actions">
        <a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/commercial">Giá &amp; chính sách</a>
        <a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>">Hồ sơ cơ sở</a>
    </div>
</header>

<div class="commercial-stats inventory-stats" aria-label="Tổng quan tồn phòng">
    <div><span>Loại phòng</span><strong><?= count($rooms) ?></strong><small>thuộc cơ sở hiện tại</small></div>
    <div><span>Room-night mở bán</span><strong><?= $totalOpen ?></strong><small><?= count($allRows) ?> ngày đã cấu hình</small></div>
    <div><span>Đang giữ</span><strong><?= $totalHeld ?></strong><small>ledger ACTIVE trong kỳ</small></div>
    <div><span>Còn khả dụng</span><strong><?= $totalAvailable ?></strong><small><?= $e($fromDate) ?> → <?= $e($toDate) ?></small></div>
</div>

<section class="commercial-section">
    <div class="commercial-section__heading">
        <div><span class="section-index">01</span><h2>Lịch theo loại phòng</h2><p>Không thể giảm tổng thấp hơn số đang giữ; mỗi lần lưu hợp lệ sẽ tăng phiên bản.</p></div>
        <form class="rate-range" method="get"><label>Từ <input type="date" name="from" value="<?= $e($fromDate) ?>"></label><label>Đến <input type="date" name="to" value="<?= $e($toDate) ?>"></label><button class="button button--secondary button--small">Xem kỳ</button></form>
    </div>
    <?php if ($rooms === []): ?><div class="commercial-empty"><strong>Chưa có loại phòng</strong><span>Tạo loại phòng trước khi mở bán tồn theo ngày.</span></div><?php endif; ?>
    <div class="rate-products">
        <?php foreach ($rooms as $index => $room): $rows = $inventoryByRoom[(int) $room['id']] ?? []; $roomHeld = array_sum(array_map(static fn (array $row): int => (int) $row['heldQuantity'], $rows)); ?>
            <details class="rate-product"<?= $index === 0 ? ' open' : '' ?>>
                <summary><span><strong><?= $e($room['name']) ?></strong><small><?= $e($room['code']) ?> · <?= count($rows) ?> ngày đã cấu hình</small></span><span class="inventory-room-metric"><b><?= $roomHeld ?></b><small>đang giữ</small></span><svg class="app-icon"><use href="#icon-chevron"/></svg></summary>
                <div class="rate-product__body">
                    <form class="rate-entry inventory-entry" method="post" action="<?= $e($baseUrl) ?>/partner/room-types/<?= (int) $room['id'] ?>/inventory">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <label class="field"><span>Ngày lưu trú</span><input type="date" name="stayDate" required></label>
                        <label class="field"><span>Tổng phòng mở bán</span><input type="number" min="0" max="65535" name="totalQuantity" required></label>
                        <button class="button button--primary button--small">Lưu tồn phòng</button>
                    </form>
                    <div class="rate-table-wrap"><table class="rate-table inventory-table"><thead><tr><th>Ngày lưu trú</th><th>Tổng mở bán</th><th>Đang giữ</th><th>Còn lại</th><th>Phiên bản</th><th>Cập nhật</th></tr></thead><tbody>
                        <?php foreach ($rows as $row): ?><tr><td><?= $e($row['stayDate']) ?></td><td><?= (int) $row['totalQuantity'] ?></td><td><span class="inventory-count inventory-count--held"><?= (int) $row['heldQuantity'] ?></span></td><td><span class="inventory-count inventory-count--available"><?= (int) $row['availableQuantity'] ?></span></td><td>v<?= (int) $row['version'] ?></td><td><?= $e(substr((string) $row['updatedAt'], 0, 16)) ?></td></tr><?php endforeach; ?>
                    </tbody></table><?php if ($rows === []): ?><div class="rate-empty">Chưa có tồn phòng trong khoảng đang xem.</div><?php endif; ?></div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</section>

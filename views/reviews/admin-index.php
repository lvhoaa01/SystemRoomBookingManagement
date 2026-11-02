<?php
declare(strict_types=1);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="resource-header"><div><span class="booking-eyebrow">Quản trị nội dung</span><h1>Kiểm duyệt đánh giá</h1><p>Ẩn hoặc xuất bản lại nội dung; quản trị viên không sửa lời khách.</p></div></header>
<section class="resource-card"><div class="table-wrap"><table class="data-table"><thead><tr><th>Booking / cơ sở</th><th>Khách</th><th>Điểm</th><th>Bình luận</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach ($reviews as $review): ?><tr><td><strong><?= $escape($review['bookingCode']) ?></strong><small><?= $escape($review['propertyName']) ?></small></td><td><?= $escape($review['customerName']) ?></td><td><strong><?= (int) $review['overall'] ?>/10</strong></td><td><?= $escape($review['comment'] ?? '—') ?></td><td><span class="booking-status booking-status--<?= strtolower((string) $review['status']) ?>"><i></i><?= $escape($review['status']) ?></span></td><td><form method="post" action="<?= $baseUrl ?>/admin/reviews/<?= (int) $review['id'] ?>/moderate"><input type="hidden" name="_token" value="<?= $csrfToken ?>"><input type="hidden" name="status" value="<?= $review['status'] === 'PUBLISHED' ? 'HIDDEN' : 'PUBLISHED' ?>"><button class="button button--secondary" type="submit"><?= $review['status'] === 'PUBLISHED' ? 'Ẩn' : 'Xuất bản' ?></button></form></td></tr><?php endforeach; ?>
<?php if ($reviews === []): ?><tr><td colspan="6"><div class="empty-state">Chưa có đánh giá.</div></td></tr><?php endif; ?>
</tbody></table></div></section>

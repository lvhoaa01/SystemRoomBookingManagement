<?php

declare(strict_types=1);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="dashboard-heading"><div><span class="eyebrow eyebrow--blue">Admin</span><h1>Hàng đợi duyệt cơ sở lưu trú</h1><p>Duyệt theo thứ tự gửi lên, có kiểm tra trạng thái lạc quan trước khi ghi.</p></div></section>
<div class="panel table-wrap"><table class="data-table"><thead><tr><th>Mã</th><th>Cơ sở</th><th>Loại</th><th>Cập nhật</th><th></th></tr></thead><tbody>
<?php foreach ($properties as $property): ?><tr><td><?= $e($property['code']) ?></td><td><strong><?= $e($property['name']) ?></strong><br><small><?= $e($property['address']) ?></small></td><td><?= $e($property['type']) ?></td><td><?= $e($property['updatedAt']) ?></td><td><a class="button button--secondary" href="<?= $e($baseUrl) ?>/admin/properties/<?= (int) $property['id'] ?>">Xem hồ sơ</a></td></tr><?php endforeach; ?>
<?php if ($properties === []): ?><tr><td colspan="5" class="muted">Không có hồ sơ chờ duyệt.</td></tr><?php endif; ?>
</tbody></table></div>

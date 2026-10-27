<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="dashboard-heading">
    <div>
        <span class="eyebrow eyebrow--blue">Partner portal</span>
        <h1>Cơ sở lưu trú của <?= $e($organization->name) ?></h1>
        <p>Quản lý hồ sơ, phòng, tiện nghi và trạng thái xét duyệt tại một nơi.</p>
    </div>
    <a class="button button--primary" href="<?= $e($baseUrl) ?>/partner/properties/create">Thêm cơ sở lưu trú</a>
</section>

<?php if ($properties === []): ?>
    <div class="empty-state"><h2>Chưa có cơ sở lưu trú</h2><p>Hãy tạo bản nháp đầu tiên để bắt đầu.</p></div>
<?php else: ?>
    <div class="property-grid">
        <?php foreach ($properties as $property): ?>
            <article class="property-card">
                <div class="property-card__body">
                    <span class="status-badge status-badge--<?= strtolower($e($property['status'])) ?>"><?= $e($property['status']) ?></span>
                    <h2><?= $e($property['name']) ?></h2>
                    <p><?= $e($property['address']) ?></p>
                    <dl class="meta-list"><div><dt>Mã</dt><dd><?= $e($property['code']) ?></dd></div><div><dt>Loại</dt><dd><?= $e($property['type']) ?></dd></div></dl>
                    <a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>">Quản lý</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

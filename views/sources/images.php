<?php declare(strict_types=1); ?>
<header class="source-page-header">
    <span class="eyebrow eyebrow--blue">Minh bạch tài sản</span>
    <h1>Nguồn ảnh</h1>
    <p>Ảnh Hotel Majestic Saigon được dùng đúng cho cơ sở mẫu tương ứng. Các ảnh dùng chung có nhãn “Ảnh minh họa” và không mô tả một cơ sở có thật.</p>
</header>

<section class="source-notice" aria-labelledby="illustration-heading">
    <h2 id="illustration-heading">Ảnh minh họa của dự án</h2>
    <p><code>accommodation-cover.png</code>, <code>accommodation-interior.png</code> và <code>room-placeholder.png</code> được tạo riêng cho dự án, không yêu cầu ghi công bên ngoài.</p>
</section>

<section class="source-list" aria-labelledby="commons-heading">
    <div class="resource-card-heading"><h2 id="commons-heading">Ảnh từ Wikimedia Commons</h2><p>Mở trang nguồn hoặc giấy phép để xem hồ sơ pháp lý đầy đủ.</p></div>
    <?php foreach ($sources as $source): ?>
        <article class="source-entry">
            <div><strong><?= $source['file'] ?></strong><small>Tác giả: <?= $source['author'] ?></small></div>
            <span><?= $source['license'] ?></span>
            <nav aria-label="Liên kết nguồn cho <?= $source['file'] ?>">
                <a href="<?= $source['sourcePage'] ?>" target="_blank" rel="noopener noreferrer">Trang nguồn</a>
                <a href="<?= $source['licenseUrl'] ?>" target="_blank" rel="noopener noreferrer">Giấy phép</a>
            </nav>
        </article>
    <?php endforeach; ?>
</section>

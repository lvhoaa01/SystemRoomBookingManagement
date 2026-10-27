<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string|null $currentUserName */
/** @var array<string, list<string>> $flashes */
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <meta name="color-scheme" content="light">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/app.css">
    <script src="<?= $baseUrl ?>/assets/js/properties.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Bỏ qua đến nội dung chính</a>
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="brand" href="<?= $baseUrl ?>/" aria-label="Stay Booking - Trang chủ">
                Stay<span>Booking</span>
            </a>
            <nav class="site-nav" aria-label="Điều hướng tài khoản">
                <?php if ($currentUserName === null): ?>
                    <a class="nav-link" href="<?= $baseUrl ?>/auth/login">Đăng nhập</a>
                    <a class="button button--header" href="<?= $baseUrl ?>/auth/register">Đăng ký</a>
                <?php else: ?>
                    <span class="user-greeting">Xin chào, <?= $currentUserName ?></span>
                    <form method="post" action="<?= $baseUrl ?>/auth/logout">
                        <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                        <button type="submit" class="button button--header">Đăng xuất</button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php if ($flashes !== []): ?>
        <div class="container flash-region" aria-live="polite">
            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert--<?= $type === 'success' ? 'success' : 'info' ?>"><?= $message ?></div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <main id="main-content" class="container page-content"><?= $content ?></main>

    <footer class="site-footer">
        <div class="container">StayBooking · Dự án đặt phòng lưu trú tại TP.HCM</div>
    </footer>
</body>
</html>


<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string|null $currentUserName */
/** @var string|null $currentUserRole */
/** @var array<string, list<string>> $flashes */
$currentUserRole = $currentUserRole ?? null;
$workspaceUrl = $currentUserRole === 'ADMIN'
    ? $baseUrl . '/admin/properties/review'
    : $baseUrl . '/partner/properties';
$workspaceLabel = $currentUserRole === 'ADMIN' ? 'Trung tâm kiểm duyệt' : 'Quản lý chỗ nghỉ';
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
            <div class="brand-group">
                <a class="brand" href="<?= $baseUrl ?>/" aria-label="StayBooking - Trang chủ">
                    <span class="brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 19V5a2 2 0 0 1 2-2h7a4 4 0 0 1 4 4v12M4 10h13M7 7h3M3 19h16M7 14h6"/></svg>
                    </span>
                    Stay<span>Booking</span>
                </a>
                <?php if ($currentUserRole !== null): ?>
                    <span class="workspace-label"><?= $currentUserRole === 'ADMIN' ? 'Admin' : 'Partner Hub' ?></span>
                <?php endif; ?>
            </div>
            <nav class="site-nav" aria-label="Điều hướng tài khoản">
                <?php if ($currentUserName === null): ?>
                    <a class="nav-link" href="<?= $baseUrl ?>/auth/login">Đăng nhập</a>
                    <a class="button button--header" href="<?= $baseUrl ?>/auth/register">Đăng ký</a>
                <?php else: ?>
                    <a class="nav-link nav-link--workspace" href="<?= $workspaceUrl ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 12 4l9 7v9H3zM9 20v-6h6v6"/></svg>
                        <?= $workspaceLabel ?>
                    </a>
                    <span class="user-chip" title="<?= $currentUserName ?>">
                        <span class="user-chip__avatar" aria-hidden="true"><?= mb_strtoupper(mb_substr($currentUserName, 0, 1)) ?></span>
                        <span class="user-greeting"><?= $currentUserName ?></span>
                    </span>
                    <form method="post" action="<?= $baseUrl ?>/auth/logout">
                        <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                        <button type="submit" class="button button--header button--icon-text">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg>
                            <span>Đăng xuất</span>
                        </button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php if ($currentUserRole !== null): ?>
        <div class="workspace-bar">
            <div class="container workspace-bar__inner">
                <a href="<?= $workspaceUrl ?>" aria-current="page"><?= $workspaceLabel ?></a>
                <span>Vận hành rõ ràng, trải nghiệm khách nhất quán</span>
            </div>
        </div>
    <?php endif; ?>

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
        <div class="container site-footer__inner"><span>StayBooking · Nền tảng đặt phòng tại TP.HCM</span><span>Đồng hành cùng đối tác lưu trú</span></div>
    </footer>
</body>
</html>


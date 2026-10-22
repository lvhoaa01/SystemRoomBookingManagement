<?php

declare(strict_types=1);

/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string $identifier */
/** @var array<string, list<string>> $errors */
?>
<section class="auth-shell">
    <div class="auth-intro">
        <span class="eyebrow">Một tài khoản, mọi hành trình</span>
        <h1>Đăng nhập để quản lý chuyến đi dễ dàng hơn</h1>
        <p>Lưu lựa chọn, theo dõi đặt phòng và tiếp tục hành trình của bạn trên mọi thiết bị.</p>
        <ul class="benefit-list">
            <li>Xem lại các đặt phòng trong một nơi</li>
            <li>Thông tin giá và chính sách minh bạch</li>
            <li>Quản lý tài khoản bằng phiên đăng nhập an toàn</li>
        </ul>
    </div>

    <div class="auth-card">
        <div class="auth-card__heading">
            <span class="eyebrow eyebrow--blue">Chào mừng trở lại</span>
            <h2>Đăng nhập</h2>
            <p>Nhập email hoặc mã người dùng của bạn.</p>
        </div>

        <?php if (($errors['credentials'] ?? []) !== []): ?>
            <div class="alert alert--error" role="alert">
                <?= implode(' ', $errors['credentials']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= $baseUrl ?>/auth/login" class="form-stack">
            <input type="hidden" name="_token" value="<?= $csrfToken ?>">

            <div class="field">
                <label for="identifier">Email hoặc mã người dùng</label>
                <input
                    id="identifier"
                    name="identifier"
                    type="text"
                    value="<?= $identifier ?>"
                    autocomplete="username"
                    maxlength="190"
                    required
                    autofocus
                >
            </div>

            <div class="field">
                <label for="password">Mật khẩu</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    maxlength="72"
                    required
                >
            </div>

            <button type="submit" class="button button--primary button--block">Đăng nhập</button>
        </form>

        <p class="auth-switch">Chưa có tài khoản? <a href="<?= $baseUrl ?>/auth/register">Đăng ký miễn phí</a></p>
    </div>
</section>

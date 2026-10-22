<?php

declare(strict_types=1);

/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var array<string, list<string>> $errors */
/** @var array<string, string> $old */
?>
<section class="auth-shell">
    <div class="auth-intro">
        <span class="eyebrow">Bắt đầu hành trình</span>
        <h1>Tìm nơi lưu trú phù hợp cho từng chuyến đi</h1>
        <p>Tạo tài khoản để chuẩn bị cho trải nghiệm tìm kiếm và đặt phòng xuyên suốt.</p>
        <ul class="benefit-list">
            <li>Tìm kiếm chỗ nghỉ tại Thành phố Hồ Chí Minh</li>
            <li>So sánh lựa chọn theo tổng giá rõ ràng</li>
            <li>Quản lý lịch sử đặt phòng an toàn</li>
        </ul>
    </div>

    <div class="auth-card">
        <div class="auth-card__heading">
            <span class="eyebrow eyebrow--blue">Tài khoản khách hàng</span>
            <h2>Đăng ký</h2>
            <p>Chỉ mất một phút để tạo tài khoản.</p>
        </div>

        <form method="post" action="<?= $baseUrl ?>/auth/register" class="form-stack">
            <input type="hidden" name="_token" value="<?= $csrfToken ?>">

            <div class="field">
                <label for="fullName">Họ và tên</label>
                <input
                    id="fullName"
                    name="fullName"
                    type="text"
                    value="<?= $old['fullName'] ?? '' ?>"
                    autocomplete="name"
                    maxlength="150"
                    required
                    autofocus
                    aria-describedby="fullName-error"
                >
                <?php if (($errors['fullName'] ?? []) !== []): ?>
                    <small id="fullName-error" class="field-error"><?= implode(' ', $errors['fullName']) ?></small>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= $old['email'] ?? '' ?>"
                    autocomplete="email"
                    maxlength="190"
                    required
                    aria-describedby="email-error"
                >
                <?php if (($errors['email'] ?? []) !== []): ?>
                    <small id="email-error" class="field-error"><?= implode(' ', $errors['email']) ?></small>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="phone">Số điện thoại <span class="optional">(không bắt buộc)</span></label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    value="<?= $old['phone'] ?? '' ?>"
                    autocomplete="tel"
                    maxlength="20"
                    aria-describedby="phone-error"
                >
                <?php if (($errors['phone'] ?? []) !== []): ?>
                    <small id="phone-error" class="field-error"><?= implode(' ', $errors['phone']) ?></small>
                <?php endif; ?>
            </div>

            <div class="field-grid">
                <div class="field">
                    <label for="password">Mật khẩu</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        minlength="8"
                        maxlength="72"
                        required
                        aria-describedby="password-hint password-error"
                    >
                    <small id="password-hint" class="field-hint">8–72 ký tự, gồm chữ và số.</small>
                    <?php if (($errors['password'] ?? []) !== []): ?>
                        <small id="password-error" class="field-error"><?= implode(' ', $errors['password']) ?></small>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="passwordConfirmation">Nhập lại mật khẩu</label>
                    <input
                        id="passwordConfirmation"
                        name="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        maxlength="72"
                        required
                        aria-describedby="confirmation-error"
                    >
                    <?php if (($errors['passwordConfirmation'] ?? []) !== []): ?>
                        <small id="confirmation-error" class="field-error"><?= implode(' ', $errors['passwordConfirmation']) ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="button button--primary button--block">Tạo tài khoản</button>
        </form>

        <p class="auth-switch">Đã có tài khoản? <a href="<?= $baseUrl ?>/auth/login">Đăng nhập</a></p>
    </div>
</section>

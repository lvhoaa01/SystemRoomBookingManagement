<?php

declare(strict_types=1);

/** @var string|null $currentUserName */
?>
<section class="hero">
    <div class="hero__content">
        <span class="eyebrow">Lưu trú tại Thành phố Hồ Chí Minh</span>
        <h1>Tìm chỗ nghỉ phù hợp cho hành trình của bạn</h1>
        <p>So sánh lựa chọn rõ ràng, xem tổng giá minh bạch và quản lý chuyến đi tại một nơi.</p>
    </div>
</section>

<section class="home-grid" aria-label="Thông tin nền tảng">
    <article class="feature-card">
        <span class="feature-card__number">01</span>
        <h2>Tìm kiếm minh bạch</h2>
        <p>Thông tin phòng, chính sách và tổng giá sẽ được trình bày theo cùng một cấu trúc.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__number">02</span>
        <h2>Đặt phòng an toàn</h2>
        <p>Mọi thao tác quan trọng được xác thực tại máy chủ và bảo vệ bằng phiên đăng nhập.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__number">03</span>
        <h2>Quản lý thuận tiện</h2>
        <p>Theo dõi đặt phòng, thay đổi hợp lệ và lịch sử chuyến đi trong tài khoản của bạn.</p>
    </article>
</section>

<?php if ($currentUserName === null): ?>
    <section class="callout">
        <div>
            <span class="eyebrow eyebrow--blue">Sẵn sàng khám phá?</span>
            <h2>Tạo tài khoản để chuẩn bị cho bước đặt phòng</h2>
        </div>
        <a class="button button--primary" href="<?= $baseUrl ?>/auth/register">Đăng ký miễn phí</a>
    </section>
<?php endif; ?>

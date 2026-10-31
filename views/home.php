<?php

declare(strict_types=1);

/** @var string|null $currentUserName */
/** @var string $baseUrl */
/** @var array<string, mixed> $searchForm */
?>
<section class="hero home-hero">
    <div class="hero__content">
        <span class="eyebrow">StayBooking · Thành phố Hồ Chí Minh</span>
        <h1>Một thành phố.<br>Hàng trăm trải nghiệm.</h1>
        <p>Tìm phòng còn bán theo đúng ngày và nhóm khách, so sánh tổng giá minh bạch trước khi lựa chọn.</p>
    </div>
    <div class="home-hero__visual" aria-hidden="true"><span>HCM</span><small>VIETNAM</small></div>
</section>

<section id="search" class="home-search-shell" aria-label="Tìm chỗ nghỉ">
    <?php require __DIR__ . '/search/_form.php'; ?>
    <p class="search-trust"><span>✓</span> Không cần đăng nhập để tìm kiếm &nbsp;·&nbsp; Giá gồm thuế/phí &nbsp;·&nbsp; Kiểm tra tồn theo từng đêm</p>
</section>

<section class="home-grid" aria-label="Thông tin nền tảng">
    <article class="feature-card">
        <span class="feature-card__number">01</span>
        <h2>Đúng nhu cầu thật</h2>
        <p>Mỗi nhóm khách được giữ nguyên; hệ thống không tự bỏ trẻ em hay đổi cách phân phòng.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__number">02</span>
        <h2>Giá nhất quán</h2>
        <p>Search và trang chi tiết dùng chung một bộ tính giá, chính sách và cách làm tròn.</p>
    </article>
    <article class="feature-card">
        <span class="feature-card__number">03</span>
        <h2>Tồn phòng hiện hành</h2>
        <p>Mỗi lần đổi ngày hoặc cách thanh toán đều gọi lại dữ liệu hiện tại từ máy chủ.</p>
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

<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static function (string $key, mixed $default = '') use ($property, $e): string {
    if (!is_array($property)) {
        return $e($default);
    }
    return $e($property[$key] ?? $default);
};
$editing = is_array($property) && isset($property['id']);
$action = $editing ? $baseUrl . '/partner/properties/' . (int) $property['id'] . '/update' : $baseUrl . '/partner/properties';
$imagesJson = is_array($property) ? ($property['imagesJson'] ?? '[]') : '[]';
?>
<nav class="breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/partner/properties">Cơ sở lưu trú</a><span>/</span><span aria-current="page"><?= $editing ? 'Chỉnh sửa' : 'Thêm mới' ?></span></nav>

<section class="form-page-heading">
    <div><span class="eyebrow eyebrow--blue">Hồ sơ chỗ nghỉ</span><h1><?= $editing ? 'Chỉnh sửa cơ sở lưu trú' : 'Tạo cơ sở lưu trú mới' ?></h1><p>Thông tin chính xác và hình ảnh chất lượng giúp khách tự tin đặt phòng hơn.</p></div>
    <?php if ($editing): ?><span class="draft-indicator"><i></i>Tự động giữ ở trạng thái bản nháp khi có thay đổi</span><?php endif; ?>
</section>

<?php foreach (($errors['form'] ?? []) as $message): ?><div class="alert alert--error"><?= $e($message) ?></div><?php endforeach; ?>

<form class="property-form-layout" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <aside class="form-steps" aria-label="Các phần của biểu mẫu">
        <a href="#basic-info"><span>1</span><strong>Thông tin cơ bản</strong><small>Tên, loại hình và địa chỉ</small></a>
        <a href="#guest-info"><span>2</span><strong>Trải nghiệm khách</strong><small>Mô tả và hạng sao</small></a>
        <a href="#operations-form"><span>3</span><strong>Vận hành & phí</strong><small>Giờ hoạt động, thuế, phí</small></a>
        <a href="#property-media"><span>4</span><strong>Hình ảnh</strong><small>Ảnh đại diện chỗ nghỉ</small></a>
    </aside>

    <div class="form-sections">
        <section id="basic-info" class="panel form-section-card">
            <div class="form-section-card__heading"><span>1</span><div><h2>Thông tin cơ bản</h2><p>Đây là thông tin đầu tiên khách nhìn thấy trong kết quả tìm kiếm.</p></div></div>
            <div class="form-stack">
                <label class="field"><span>Tên cơ sở lưu trú</span><input name="name" required maxlength="190" value="<?= $value('name') ?>" placeholder="Ví dụ: StayBooking Central Hotel"><small>Tên thương hiệu chính thức, không thêm giá hoặc ưu đãi.</small></label>
                <div class="field-grid">
                    <label class="field"><span>Loại hình chỗ nghỉ</span><select name="type" required>
                        <?php foreach (['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $value('type', 'HOTEL') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="field"><span>Hạng sao</span><select name="starRating"><option value="">Chưa xếp hạng</option><?php foreach ([1, 2, 3, 4, 5] as $star): ?><option value="<?= $star ?>" <?= $value('starRating') === (string) $star ? 'selected' : '' ?>><?= $star ?> sao</option><?php endforeach; ?></select></label>
                </div>
                <label class="field"><span>Địa chỉ tại TP.HCM</span><input name="address" required maxlength="500" value="<?= $value('address') ?>" placeholder="Số nhà, tên đường, phường/xã, quận/huyện"><small>Nhập địa chỉ đầy đủ để quá trình xác minh diễn ra thuận lợi.</small></label>
            </div>
        </section>

        <section id="guest-info" class="panel form-section-card">
            <div class="form-section-card__heading"><span>2</span><div><h2>Giới thiệu trải nghiệm</h2><p>Viết ngắn gọn về vị trí, không gian và điều khiến chỗ nghỉ nổi bật.</p></div></div>
            <label class="field"><span>Mô tả cơ sở</span><textarea name="description" required rows="7" maxlength="3000" data-character-field><?= $value('description') ?></textarea><small><span data-character-count>0</span>/3000 ký tự · Nên viết ít nhất 120 ký tự.</small></label>
        </section>

        <section id="operations-form" class="panel form-section-card">
            <div class="form-section-card__heading"><span>3</span><div><h2>Vận hành và phí</h2><p>Thiết lập khung giờ lưu trú và cách hiển thị các khoản phí.</p></div></div>
            <div class="field-grid">
                <label class="field"><span>Giờ nhận phòng</span><input type="time" name="checkInTime" value="<?= substr($value('checkInTime', '14:00'), 0, 5) ?>"></label>
                <label class="field"><span>Giờ trả phòng</span><input type="time" name="checkOutTime" value="<?= substr($value('checkOutTime', '12:00'), 0, 5) ?>"></label>
            </div>
            <div class="fee-grid">
                <div class="fee-card"><label class="field"><span>Thuế (%)</span><input type="number" min="0" max="100" step="0.01" name="taxRate" value="<?= $value('taxRate', '0') ?>"></label><label class="switch-line"><input type="checkbox" name="taxIncluded" value="1" <?= $value('taxIncluded') === '1' ? 'checked' : '' ?>><span></span>Đã bao gồm trong giá</label></div>
                <div class="fee-card"><label class="field"><span>Phí dịch vụ (%)</span><input type="number" min="0" max="100" step="0.01" name="serviceFeeRate" value="<?= $value('serviceFeeRate', '0') ?>"></label><label class="switch-line"><input type="checkbox" name="serviceFeeIncluded" value="1" <?= $value('serviceFeeIncluded') === '1' ? 'checked' : '' ?>><span></span>Đã bao gồm trong giá</label></div>
            </div>
            <input type="hidden" name="timezone" value="Asia/Ho_Chi_Minh"><input type="hidden" name="reconciliationStatus" value="PARTNER_DECLARED">
        </section>

        <section id="property-media" class="panel form-section-card">
            <div class="form-section-card__heading"><span>4</span><div><h2>Hình ảnh chỗ nghỉ</h2><p>Ảnh đầu tiên là ảnh bìa. Nên dùng ảnh ngang, sáng rõ và đúng thực tế.</p></div></div>
            <textarea class="visually-hidden" id="images-json" name="imagesJson" aria-label="Dữ liệu hình ảnh"><?= $e($imagesJson) ?></textarea>
            <div class="image-gallery" data-image-gallery data-base-url="<?= $e($baseUrl) ?>"></div>
            <?php if ($editing): ?>
                <div class="upload-box upload-box--modern" data-image-uploader data-url="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/images" data-token="<?= $e($csrfToken) ?>">
                    <span class="upload-box__icon" aria-hidden="true">↥</span>
                    <div><strong>Tải ảnh chỗ nghỉ</strong><p>JPEG, PNG hoặc WebP · Tối đa 5 MB</p></div>
                    <label class="button button--secondary file-button">Chọn ảnh<input type="file" accept="image/jpeg,image/png,image/webp" data-image-file></label>
                    <label class="field upload-alt"><span>Mô tả ảnh</span><input value="Ảnh cơ sở lưu trú" maxlength="255" data-image-alt></label>
                    <button class="button button--primary" type="button" data-image-submit>Tải lên</button>
                    <p class="form-help upload-status" data-image-status aria-live="polite"></p>
                </div>
            <?php else: ?>
                <div class="info-banner"><span aria-hidden="true">i</span><p>Hãy tạo bản nháp trước. Bạn sẽ có thể tải và sắp xếp ảnh ngay sau đó.</p></div>
            <?php endif; ?>
        </section>

        <div class="sticky-form-actions"><a class="button button--secondary" href="<?= $e($baseUrl) ?>/partner/properties">Hủy</a><button class="button button--primary" type="submit"><?= $editing ? 'Lưu thay đổi' : 'Tạo bản nháp' ?></button></div>
    </div>
</form>

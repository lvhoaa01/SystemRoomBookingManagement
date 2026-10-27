<?php

declare(strict_types=1);

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static function (string $key, mixed $default = '') use ($property, $e): string {
    if (!is_array($property)) return $e($default);
    return $e($property[$key] ?? $default);
};
$editing = is_array($property) && isset($property['id']);
$action = $editing ? $baseUrl . '/partner/properties/' . (int) $property['id'] . '/update' : $baseUrl . '/partner/properties';
$imagesJson = is_array($property) ? ($property['imagesJson'] ?? '[]') : '[]';
?>
<nav class="resource-breadcrumb" aria-label="Đường dẫn"><a href="<?= $e($baseUrl) ?>/partner/properties">Cơ sở lưu trú</a><?php if ($editing): ?><svg class="app-icon"><use href="#icon-chevron"/></svg><a href="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>"><?= $value('name') ?></a><?php endif; ?><svg class="app-icon"><use href="#icon-chevron"/></svg><span aria-current="page"><?= $editing ? 'Chỉnh sửa' : 'Thêm mới' ?></span></nav>

<header class="edit-header"><h1><?= $editing ? 'Chỉnh sửa cơ sở' : 'Thêm cơ sở lưu trú' ?></h1><p><?= $editing ? 'Cập nhật thông tin chi tiết của cơ sở lưu trú.' : 'Nhập thông tin cơ bản để tạo hồ sơ chỗ nghỉ mới.' ?></p></header>

<?php foreach (($errors['form'] ?? []) as $message): ?><div class="alert alert--error edit-alert"><?= $e($message) ?></div><?php endforeach; ?>

<form class="edit-form" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <div class="edit-surface">
        <section class="edit-section" id="basic-info">
            <div class="edit-section__heading"><h2>Thông tin cơ bản</h2><p>Thông tin chính được hiển thị với khách hàng.</p></div>
            <div class="form-stack">
                <div class="field-grid"><label class="field"><span>Tên cơ sở lưu trú <i>*</i></span><input name="name" required maxlength="190" value="<?= $value('name') ?>"></label><label class="field"><span>Hạng sao</span><select name="starRating"><option value="">Chưa xếp hạng</option><?php for ($star = 0.5; $star <= 5; $star += 0.5): $starValue = rtrim(rtrim(number_format($star, 1, '.', ''), '0'), '.'); ?><option value="<?= $starValue ?>" <?= (float) $value('starRating', '0') === $star ? 'selected' : '' ?>><?= $starValue ?> sao</option><?php endfor; ?></select></label></div>
                <label class="field"><span>Loại hình <i>*</i></span><select name="type" required><?php foreach (['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'] as $code => $label): ?><option value="<?= $code ?>" <?= $value('type', 'HOTEL') === $code ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Mô tả ngắn <i>*</i></span><textarea name="description" required rows="5" maxlength="3000" data-character-field><?= $value('description') ?></textarea><small><span data-character-count>0</span>/3000 ký tự</small></label>
            </div>
        </section>

        <section class="edit-section" id="address-info">
            <div class="edit-section__heading"><h2>Địa chỉ</h2><p>Nhập địa chỉ đầy đủ tại Thành phố Hồ Chí Minh.</p></div>
            <label class="field"><span>Địa chỉ chi tiết <i>*</i></span><input name="address" required maxlength="500" value="<?= $value('address') ?>" placeholder="Số nhà, tên đường, phường/xã, quận/huyện"></label>
        </section>

        <section class="edit-section" id="operations-form">
            <div class="edit-section__heading"><h2>Vận hành</h2><p>Khung giờ lưu trú và các khoản phí áp dụng.</p></div>
            <div class="field-grid"><label class="field"><span>Giờ nhận phòng <i>*</i></span><input type="time" name="checkInTime" value="<?= substr($value('checkInTime', '14:00'), 0, 5) ?>"></label><label class="field"><span>Giờ trả phòng <i>*</i></span><input type="time" name="checkOutTime" value="<?= substr($value('checkOutTime', '12:00'), 0, 5) ?>"></label></div>
            <div class="field-grid edit-fees"><div><label class="field"><span>Thuế (%)</span><input type="number" min="0" max="100" step="0.01" name="taxRate" value="<?= $value('taxRate', '0') ?>"></label><label class="check-row-simple"><input type="checkbox" name="taxIncluded" value="1" <?= $value('taxIncluded') === '1' ? 'checked' : '' ?>>Đã bao gồm trong giá</label></div><div><label class="field"><span>Phí dịch vụ (%)</span><input type="number" min="0" max="100" step="0.01" name="serviceFeeRate" value="<?= $value('serviceFeeRate', '0') ?>"></label><label class="check-row-simple"><input type="checkbox" name="serviceFeeIncluded" value="1" <?= $value('serviceFeeIncluded') === '1' ? 'checked' : '' ?>>Đã bao gồm trong giá</label></div></div>
            <input type="hidden" name="timezone" value="Asia/Ho_Chi_Minh"><input type="hidden" name="reconciliationStatus" value="PARTNER_DECLARED">
        </section>

        <section class="edit-section" id="property-media">
            <div class="edit-section__heading"><h2>Hình ảnh</h2><p>Ảnh đầu tiên được dùng làm ảnh bìa của chỗ nghỉ.</p></div>
            <textarea class="visually-hidden" id="images-json" name="imagesJson" aria-label="Dữ liệu hình ảnh"><?= $e($imagesJson) ?></textarea>
            <div class="image-gallery image-gallery--clean" data-image-gallery data-base-url="<?= $e($baseUrl) ?>"></div>
            <?php if ($editing): ?>
                <div class="clean-uploader" data-image-uploader data-url="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/images" data-token="<?= $e($csrfToken) ?>">
                    <div class="clean-uploader__intro"><svg class="app-icon"><use href="#icon-upload"/></svg><span><strong>Tải ảnh mới</strong><small>JPEG, PNG hoặc WebP · Tối đa 5 MB</small></span></div>
                    <label class="button button--secondary button--small file-button">Chọn ảnh<input type="file" accept="image/jpeg,image/png,image/webp" data-image-file></label>
                    <label class="field"><span>Mô tả ảnh</span><input value="Ảnh cơ sở lưu trú" maxlength="255" data-image-alt></label>
                    <button class="button button--primary button--small" type="button" data-image-submit>Tải lên</button>
                    <p class="form-help" data-image-status aria-live="polite"></p>
                </div>
            <?php else: ?>
                <div class="form-note"><svg class="app-icon"><use href="#icon-image"/></svg><span>Hãy tạo bản nháp trước. Bạn có thể tải ảnh ngay sau khi hồ sơ được tạo.</span></div>
            <?php endif; ?>
        </section>
    </div>

    <div class="edit-actions"><a class="button button--secondary" href="<?= $e($baseUrl) ?><?= $editing ? '/partner/properties/' . (int) $property['id'] : '/partner/properties' ?>">Hủy</a><button class="button button--primary" type="submit"><?= $editing ? 'Lưu thay đổi' : 'Tạo bản nháp' ?></button></div>
</form>

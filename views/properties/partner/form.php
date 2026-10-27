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
$action = $editing
    ? $baseUrl . '/partner/properties/' . (int) $property['id'] . '/update'
    : $baseUrl . '/partner/properties';
$imagesJson = is_array($property) ? ($property['imagesJson'] ?? '[]') : '[]';
?>
<div class="content-narrow">
    <a class="back-link" href="<?= $e($baseUrl) ?>/partner/properties">← Danh sách cơ sở</a>
    <section class="panel">
        <div class="panel__heading"><span class="eyebrow eyebrow--blue">Hồ sơ cơ sở</span><h1><?= $editing ? 'Chỉnh sửa cơ sở lưu trú' : 'Tạo bản nháp mới' ?></h1></div>
        <?php foreach (($errors['form'] ?? []) as $message): ?><div class="alert alert--error"><?= $e($message) ?></div><?php endforeach; ?>
        <form class="form-stack" method="post" action="<?= $e($action) ?>">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <div class="field-grid">
                <label class="field"><span>Tên cơ sở</span><input name="name" required maxlength="190" value="<?= $value('name') ?>"></label>
                <label class="field"><span>Loại hình</span><select name="type" required>
                    <?php foreach (['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'] as $code => $label): ?>
                        <option value="<?= $code ?>" <?= $value('type', 'HOTEL') === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></label>
            </div>
            <label class="field"><span>Địa chỉ tại TP.HCM</span><input name="address" required maxlength="500" value="<?= $value('address') ?>"></label>
            <label class="field"><span>Mô tả</span><textarea name="description" required rows="5"><?= $value('description') ?></textarea></label>
            <div class="field-grid field-grid--three">
                <label class="field"><span>Thuế (%)</span><input type="number" min="0" max="100" step="0.01" name="taxRate" value="<?= $value('taxRate', '0') ?>"></label>
                <label class="field"><span>Phí dịch vụ (%)</span><input type="number" min="0" max="100" step="0.01" name="serviceFeeRate" value="<?= $value('serviceFeeRate', '0') ?>"></label>
                <label class="field"><span>Hạng sao</span><input type="number" min="0" max="5" step="0.5" name="starRating" value="<?= $value('starRating') ?>"></label>
            </div>
            <div class="check-row"><label><input type="checkbox" name="taxIncluded" value="1" <?= $value('taxIncluded') === '1' ? 'checked' : '' ?>> Thuế đã gồm trong giá</label><label><input type="checkbox" name="serviceFeeIncluded" value="1" <?= $value('serviceFeeIncluded') === '1' ? 'checked' : '' ?>> Phí dịch vụ đã gồm trong giá</label></div>
            <div class="field-grid">
                <label class="field"><span>Giờ nhận phòng</span><input type="time" name="checkInTime" value="<?= substr($value('checkInTime', '14:00'), 0, 5) ?>"></label>
                <label class="field"><span>Giờ trả phòng</span><input type="time" name="checkOutTime" value="<?= substr($value('checkOutTime', '12:00'), 0, 5) ?>"></label>
            </div>
            <input type="hidden" name="timezone" value="Asia/Ho_Chi_Minh">
            <input type="hidden" name="reconciliationStatus" value="PARTNER_DECLARED">
            <label class="field"><span>Danh sách ảnh JSON</span><textarea id="images-json" name="imagesJson" rows="4"><?= $e($imagesJson) ?></textarea><small>Ảnh đầu tiên phải có <code>"cover": true</code>. Có thể tạo nháp trước rồi tải ảnh ở lần chỉnh sửa.</small></label>
            <?php if ($editing): ?>
                <div class="upload-box" data-image-uploader data-url="<?= $e($baseUrl) ?>/partner/properties/<?= (int) $property['id'] ?>/images" data-token="<?= $e($csrfToken) ?>">
                    <label class="field"><span>Tải ảnh mới</span><input type="file" accept="image/jpeg,image/png,image/webp" data-image-file></label>
                    <label class="field"><span>Mô tả ảnh</span><input value="Ảnh cơ sở lưu trú" maxlength="255" data-image-alt></label>
                    <button class="button button--secondary" type="button" data-image-submit>Tải ảnh và thêm vào danh sách</button>
                    <p class="form-help" data-image-status aria-live="polite"></p>
                </div>
            <?php endif; ?>
            <button class="button button--primary" type="submit"><?= $editing ? 'Lưu thay đổi' : 'Tạo bản nháp' ?></button>
        </form>
    </section>
</div>

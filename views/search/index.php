<?php

declare(strict_types=1);

/** @var array<string, mixed> $search */
/** @var array<string, mixed> $query */
/** @var string $baseUrl */
$criteria = $search['criteria'];
$pagination = $search['pagination'];
$searchForm = ['destination' => $criteria['destination'], 'checkIn' => $criteria['stayPeriod']['checkIn'], 'checkOut' => $criteria['stayPeriod']['checkOut'], 'parties' => $criteria['parties']];
$typeLabels = ['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'];
$imageUrl = static function (array $property) use ($baseUrl): string {
    $cover = $property['images'][0]['path'] ?? '/assets/images/accommodation/shared/room-placeholder.png';
    foreach ($property['images'] as $image) { if (($image['cover'] ?? false) === true) { $cover = $image['path']; break; } }
    return $baseUrl . '/' . ltrim((string) $cover, '/');
};
?>
<header class="search-page-header"><p class="eyebrow eyebrow--blue">Khám phá TP.HCM</p><h1>Tìm chỗ nghỉ phù hợp với bạn</h1><p>Giá và tồn được kiểm tra cho đủ <?= $criteria['stayPeriod']['checkIn'] ?> → <?= $criteria['stayPeriod']['checkOut'] ?>.</p></header>
<section class="search-bar-panel"><?php require __DIR__ . '/_form.php'; ?></section>

<div class="search-page-layout">
    <aside class="search-filters">
        <form method="get" action="<?= $baseUrl ?>/search">
            <input type="hidden" name="destination" value="<?= $criteria['destination'] ?>"><input type="hidden" name="checkIn" value="<?= $criteria['stayPeriod']['checkIn'] ?>"><input type="hidden" name="checkOut" value="<?= $criteria['stayPeriod']['checkOut'] ?>"><input type="hidden" name="rooms" value='<?= json_encode($criteria['parties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>'>
            <div class="filter-heading"><h2>Bộ lọc</h2><a href="<?= $baseUrl ?>/search?<?= http_build_query(['destination' => $criteria['destination'], 'checkIn' => $criteria['stayPeriod']['checkIn'], 'checkOut' => $criteria['stayPeriod']['checkOut'], 'rooms' => json_encode($criteria['parties'])]) ?>">Xóa</a></div>
            <fieldset><legend>Khoảng tổng giá</legend><div class="filter-price"><input type="number" name="minPrice" min="0" step="50000" value="<?= $criteria['filters']['minPrice'] ?? '' ?>" placeholder="Từ"><input type="number" name="maxPrice" min="0" step="50000" value="<?= $criteria['filters']['maxPrice'] ?? '' ?>" placeholder="Đến"></div></fieldset>
            <fieldset><legend>Cách thanh toán</legend><label class="filter-choice"><input type="radio" name="paymentTiming" value="" <?= !isset($criteria['filters']['paymentTiming']) ? 'checked' : '' ?>><span>Tất cả</span></label><label class="filter-choice"><input type="radio" name="paymentTiming" value="PAY_AT_PROPERTY" <?= ($criteria['filters']['paymentTiming'] ?? '') === 'PAY_AT_PROPERTY' ? 'checked' : '' ?>><span>Trả tại cơ sở</span></label><label class="filter-choice"><input type="radio" name="paymentTiming" value="PAY_ONLINE" <?= ($criteria['filters']['paymentTiming'] ?? '') === 'PAY_ONLINE' ? 'checked' : '' ?>><span>Trả trực tuyến</span></label></fieldset>
            <fieldset><legend>Loại chỗ nghỉ</legend><?php foreach ($typeLabels as $value => $label): ?><label class="filter-choice"><input type="checkbox" name="propertyTypes[]" value="<?= $value ?>" <?= in_array($value, $criteria['filters']['propertyTypes'] ?? [], true) ? 'checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?></fieldset>
            <fieldset><legend>Tiện ích</legend><label class="filter-choice"><input type="checkbox" name="freeCancellation" value="1" <?= ($criteria['filters']['freeCancellation'] ?? false) ? 'checked' : '' ?>><span>Có hủy miễn phí</span></label><label class="filter-choice"><input type="checkbox" name="mealTypes[]" value="BREAKFAST_INCLUDED" <?= in_array('BREAKFAST_INCLUDED', $criteria['filters']['mealTypes'] ?? [], true) ? 'checked' : '' ?>><span>Bao gồm bữa sáng</span></label></fieldset>
            <button class="button button--primary button--block" type="submit">Áp dụng bộ lọc</button>
        </form>
    </aside>

    <section class="search-list" aria-label="Kết quả tìm kiếm">
        <div class="search-list__toolbar"><div><strong><?= $pagination['total'] ?> chỗ nghỉ</strong><span> có phân bổ phù hợp</span></div><form method="get" action="<?= $baseUrl ?>/search"><?php foreach ($query as $key => $value): ?><?php if ($key !== 'sort' && $key !== 'page'): ?><?php if (is_array($value)): ?><?php foreach ($value as $item): ?><input type="hidden" name="<?= $key ?>[]" value="<?= $item ?>"><?php endforeach; ?><?php else: ?><input type="hidden" name="<?= $key ?>" value="<?= $value ?>"><?php endif; ?><?php endif; ?><?php endforeach; ?><label>Sắp xếp <select name="sort" onchange="this.form.submit()"><option value="RECOMMENDED" <?= $criteria['sort'] === 'RECOMMENDED' ? 'selected' : '' ?>>Phù hợp nhất</option><option value="PRICE_ASC" <?= $criteria['sort'] === 'PRICE_ASC' ? 'selected' : '' ?>>Giá thấp trước</option><option value="PRICE_DESC" <?= $criteria['sort'] === 'PRICE_DESC' ? 'selected' : '' ?>>Giá cao trước</option><option value="REVIEW_SCORE" <?= $criteria['sort'] === 'REVIEW_SCORE' ? 'selected' : '' ?>>Điểm đánh giá</option></select></label></form></div>

        <?php foreach ($search['results'] as $result): ?>
            <?php $property = $result['property']; $allocation = $result['representative']; $detailQuery = $query; unset($detailQuery['page']); $detailQuery['paymentTiming'] = $allocation['paymentTiming']; $detailQuery['selectionFingerprint'] = $allocation['selectionFingerprint']; $detailQuery['selected'] = implode(',', array_map(static fn (array $room): string => $room['roomOrder'] . ':' . $room['roomTypeId'] . ':' . $room['productId'], $allocation['rooms'])); ?>
            <article class="stay-card">
                <a class="stay-card__media" href="<?= $baseUrl ?>/properties/<?= $property['slug'] ?>?<?= http_build_query($detailQuery) ?>"><img src="<?= $imageUrl($property) ?>" alt="Ảnh <?= $property['name'] ?>"><span><?= $typeLabels[$property['type']] ?? $property['type'] ?></span></a>
                <div class="stay-card__body">
                    <div class="stay-card__heading"><div><div class="stay-card__stars"><?= $property['starRating'] === null ? 'Lưu trú đã xác minh' : str_repeat('★', (int) $property['starRating']) ?></div><h2><a href="<?= $baseUrl ?>/properties/<?= $property['slug'] ?>?<?= http_build_query($detailQuery) ?>"><?= $property['name'] ?></a></h2><p><svg class="app-icon"><use href="#icon-pin"/></svg><?= $property['address'] ?></p></div><div class="review-score"><span><strong><?= $property['reviewScore'] ?></strong>/10</span><small><?= number_format($property['reviewCount']) ?> đánh giá</small></div></div>
                    <div class="stay-card__facilities"><?php foreach (array_slice($property['facilities'], 0, 4) as $facility): ?><span><?= $facility['name'] ?></span><?php endforeach; ?></div>
                    <div class="stay-card__offer"><div><strong><?= count($allocation['rooms']) ?> phòng cho nhóm của bạn</strong><span><?= $result['cancellationLabel'] ?></span><?php if ($result['minimumAvailable'] <= 2): ?><em>Chỉ còn <?= $result['minimumAvailable'] ?> phòng theo tồn hiện tại</em><?php endif; ?></div><div class="stay-card__price"><span><?= $criteria['stayPeriod']['checkIn'] ?> · <?= count($allocation['rooms']) ?> phòng</span><strong><?= number_format((float) $allocation['grandTotal']['amount'], 0, ',', '.') ?> ₫</strong><small>Gồm <?= number_format((float) $result['taxFeeTotal'], 0, ',', '.') ?> ₫ thuế và phí</small><b><?= $allocation['paymentTiming'] === 'PAY_AT_PROPERTY' ? 'Trả tại cơ sở' : 'Trả trực tuyến' ?></b><a class="button button--primary" href="<?= $baseUrl ?>/properties/<?= $property['slug'] ?>?<?= http_build_query($detailQuery) ?>">Xem phòng <span aria-hidden="true">→</span></a></div></div>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($search['results'] === []): ?><div class="search-empty"><span><svg class="app-icon"><use href="#icon-search"/></svg></span><h2>Chưa có lựa chọn phù hợp</h2><p>Hãy thử ngày khác, bớt bộ lọc hoặc điều chỉnh phân bổ khách.</p></div><?php endif; ?>
        <?php if ($pagination['totalPages'] > 1): ?><nav class="pagination" aria-label="Phân trang"><?php for ($pageNumber = 1; $pageNumber <= $pagination['totalPages']; $pageNumber++): ?><?php $pageQuery = [...$query, 'page' => $pageNumber]; ?><a class="<?= $pageNumber === $pagination['page'] ? 'is-active' : '' ?>" href="<?= $baseUrl ?>/search?<?= http_build_query($pageQuery) ?>"><?= $pageNumber ?></a><?php endfor; ?></nav><?php endif; ?>
    </section>
</div>

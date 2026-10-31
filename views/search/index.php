<?php

declare(strict_types=1);

/** @var array<string, mixed> $search */
/** @var string $baseUrl */
$criteria = $search['criteria'];
?>
<section class="search-summary">
    <p class="eyebrow eyebrow--blue">Kết quả tại TP.HCM</p>
    <h1><?= $search['pagination']['total'] ?> cơ sở có lựa chọn phù hợp</h1>
    <p><?= $criteria['stayPeriod']['checkIn'] ?> → <?= $criteria['stayPeriod']['checkOut'] ?> · <?= count($criteria['parties']) ?> phòng</p>
</section>

<section class="search-results" aria-label="Danh sách cơ sở lưu trú">
    <?php foreach ($search['results'] as $result): ?>
        <?php $property = $result['property']; $allocation = $result['representative']; ?>
        <article class="search-result-card">
            <h2><?= $property['name'] ?></h2>
            <p><?= $property['address'] ?></p>
            <p><?= $property['reviewScore'] ?>/10 · <?= $property['reviewCount'] ?> đánh giá</p>
            <p><?= $result['cancellationLabel'] ?> · <?= $allocation['paymentTiming'] === 'PAY_AT_PROPERTY' ? 'Trả tại cơ sở' : 'Trả trực tuyến' ?></p>
            <strong><?= number_format((float) $allocation['grandTotal']['amount'], 0, ',', '.') ?> ₫</strong>
            <a class="button button--primary" href="<?= $baseUrl ?>/properties/<?= $property['slug'] ?>?<?= http_build_query([
                'destination' => $criteria['destination'],
                'checkIn' => $criteria['stayPeriod']['checkIn'],
                'checkOut' => $criteria['stayPeriod']['checkOut'],
                'rooms' => json_encode($criteria['parties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'paymentTiming' => $allocation['paymentTiming'],
                'selectionFingerprint' => $allocation['selectionFingerprint'],
                'selected' => implode(',', array_map(static fn (array $room): string => $room['roomOrder'] . ':' . $room['roomTypeId'] . ':' . $room['productId'], $allocation['rooms'])),
            ]) ?>">Xem phòng</a>
        </article>
    <?php endforeach; ?>
    <?php if ($search['results'] === []): ?><p>Không tìm thấy lựa chọn phù hợp. Hãy thử ngày hoặc phân bổ khách khác.</p><?php endif; ?>
</section>

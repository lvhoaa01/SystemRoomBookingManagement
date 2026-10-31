<?php

declare(strict_types=1);

/** @var array<string, mixed> $availability */
$property = $availability['property'];
$allocation = $availability['allocation'];
?>
<article class="public-property-detail">
    <p class="eyebrow eyebrow--blue"><?= $property['type'] ?></p>
    <h1><?= $property['name'] ?></h1>
    <p><?= $property['address'] ?></p>
    <p><?= $property['description'] ?></p>

    <?php if ($availability['selectionChanged']): ?>
        <div class="alert alert--info"><?= $availability['changeReason'] ?></div>
    <?php endif; ?>

    <section>
        <h2>Phòng phù hợp</h2>
        <?php if ($allocation === null): ?>
            <p>Timing này hiện không còn tổ hợp phù hợp.</p>
        <?php else: ?>
            <p><?= $availability['paymentTiming'] === 'PAY_AT_PROPERTY' ? 'Trả tại cơ sở' : 'Trả trực tuyến' ?></p>
            <?php foreach ($allocation['rooms'] as $room): ?>
                <article>
                    <h3>Phòng <?= $room['roomOrder'] ?></h3>
                    <p><?= $room['party']['adults'] ?> người lớn · <?= count($room['party']['childAges']) ?> trẻ em</p>
                    <strong><?= number_format((float) $room['priceBreakdown']['grandTotal']['amount'], 0, ',', '.') ?> ₫</strong>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</article>

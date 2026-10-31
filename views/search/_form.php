<?php

declare(strict_types=1);

/** @var array<string, mixed> $searchForm */
/** @var string $baseUrl */
$formParties = $searchForm['parties'] ?? [['adults' => 2, 'childAges' => []]];
?>
<form class="booking-search" method="get" action="<?= $baseUrl ?>/search" data-search-form>
    <div class="booking-search__field booking-search__field--destination">
        <span class="booking-search__icon" aria-hidden="true"><svg class="app-icon"><use href="#icon-pin"/></svg></span>
        <label for="search-destination">Bạn muốn ở đâu?</label>
        <input id="search-destination" name="destination" value="<?= $searchForm['destination'] ?? 'Thành phố Hồ Chí Minh' ?>" maxlength="120" required>
    </div>
    <div class="booking-search__field">
        <span class="booking-search__icon" aria-hidden="true"><svg class="app-icon"><use href="#icon-clock"/></svg></span>
        <label for="search-check-in">Nhận phòng</label>
        <input id="search-check-in" type="date" name="checkIn" value="<?= $searchForm['checkIn'] ?>" required>
    </div>
    <div class="booking-search__field">
        <span class="booking-search__icon" aria-hidden="true"><svg class="app-icon"><use href="#icon-clock"/></svg></span>
        <label for="search-check-out">Trả phòng</label>
        <input id="search-check-out" type="date" name="checkOut" value="<?= $searchForm['checkOut'] ?>" required>
    </div>
    <div class="booking-search__guests" data-room-list>
        <?php foreach ($formParties as $index => $party): ?>
            <div class="booking-search__room" data-room-row>
                <span class="booking-search__room-label">Phòng <b data-room-number><?= $index + 1 ?></b></span>
                <label>Người lớn<input type="number" name="roomAdults[]" min="1" max="10" value="<?= $party['adults'] ?>" required></label>
                <label>Tuổi trẻ em<input name="roomChildAges[]" value="<?= implode(',', $party['childAges']) ?>" placeholder="VD: 7,10"></label>
                <button type="button" class="room-remove" data-remove-room aria-label="Xóa phòng">×</button>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="booking-search__actions">
        <button type="button" class="booking-search__add" data-add-room>+ Thêm phòng</button>
        <button class="button button--search" type="submit"><svg class="app-icon"><use href="#icon-search"/></svg>Tìm chỗ nghỉ</button>
    </div>
</form>

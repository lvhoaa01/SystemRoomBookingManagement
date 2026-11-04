<?php

declare(strict_types=1);

use App\Modules\Payments\Domain\Invoice;

/** @var Invoice $invoice */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (mixed $value): string => number_format((float) $value, 0, ',', '.') . ' ₫';
$date = static fn (mixed $value): string => date('d/m/Y', strtotime((string) $value));
$snapshot = $invoice->snapshot;
$property = $snapshot['property'];
$booker = $snapshot['booker'];
$stay = $snapshot['stay'];
$backPath = $audience === 'partner' ? '/partner/bookings/' : '/account/bookings/';
?>
<nav class="resource-breadcrumb invoice-no-print"><a href="<?= $baseUrl . $backPath . rawurlencode($invoice->bookingCode) ?>">Booking <?= $escape($invoice->bookingCode) ?></a><svg class="app-icon"><use href="#icon-chevron"/></svg><span><?= $escape($invoice->number) ?></span></nav>

<article class="invoice-sheet">
    <header class="invoice-header">
        <div><span class="booking-eyebrow">StayBooking · Hóa đơn lưu trú</span><h1><?= $escape($invoice->number) ?></h1><p>Phát hành <?= $invoice->issuedAt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i') ?></p></div>
        <div class="invoice-status"><span>ISSUED</span><strong>Đã thanh toán</strong></div>
    </header>

    <section class="invoice-parties">
        <div><span>Đơn vị cung cấp</span><h2><?= $escape($property['name']) ?></h2><p><?= $escape($property['address']) ?></p></div>
        <div><span>Khách hàng</span><h2><?= $escape($booker['name']) ?></h2><p><?= $escape($booker['email']) ?><br><?= $escape($booker['phone']) ?></p></div>
    </section>

    <section class="invoice-stay"><div><span>Mã booking</span><strong><?= $escape($invoice->bookingCode) ?></strong></div><div><span>Nhận phòng</span><strong><?= $date($stay['checkIn']) ?></strong></div><div><span>Trả phòng</span><strong><?= $date($stay['checkOut']) ?></strong></div><div><span>Hình thức</span><strong><?= ($snapshot['paymentTiming'] ?? '') === 'PAY_ONLINE' ? 'Thanh toán trực tuyến' : 'Thanh toán tại cơ sở' ?></strong></div></section>

    <div class="invoice-table-wrap"><table class="invoice-table"><thead><tr><th>#</th><th>Nội dung</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody><?php foreach ($invoice->lines as $line): ?><tr><td><?= (int) $line['position'] ?></td><td><strong><?= $escape($line['description']) ?></strong><small>Phòng <?= (int) ($line['snapshot']['roomOrder'] ?? $line['position']) ?></small></td><td><?= (int) $line['quantity'] ?></td><td><?= $money($line['unitPrice']) ?></td><td><strong><?= $money($line['lineTotal']) ?></strong></td></tr><?php endforeach; ?></tbody></table></div>

    <footer class="invoice-total"><div><p>Chứng từ được tạo từ snapshot booking sau khi lưu trú hoàn tất.</p><small>Đây là biên nhận/hóa đơn nghiệp vụ của hệ thống, không thay thế hóa đơn điện tử có mã cơ quan thuế.</small></div><dl><div><dt>Tổng cộng</dt><dd><?= $money($invoice->total->decimal()) ?></dd></div><div><dt>Đã thanh toán</dt><dd><?= $money($invoice->paid->decimal()) ?></dd></div><div class="invoice-balance"><dt>Còn lại</dt><dd>0 ₫</dd></div></dl></footer>
</article>

<div class="invoice-actions invoice-no-print"><a class="button button--secondary" href="<?= $baseUrl . $backPath . rawurlencode($invoice->bookingCode) ?>">Quay lại booking</a><button class="button button--primary" type="button" data-print-invoice>In / Lưu PDF</button></div>

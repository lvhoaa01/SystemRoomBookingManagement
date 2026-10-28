<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Application;

use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class CancellationCalculator
{
    /**
     * @param list<array{
     *     total: Money|string|int,
     *     nights: list<array{stayDate: string, total: Money|string|int}>,
     *     cancellationSnapshot: array<string, mixed>
     * }> $items
     */
    public function calculate(
        Money $bookingTotal,
        array $items,
        string $checkIn,
        string $checkInTime,
        string $timezone,
        DateTimeImmutable $cancelledAt,
    ): Money {
        if ($bookingTotal->amount() < 0 || $items === [] || !array_is_list($items)) {
            throw new InvalidArgumentException('Snapshot booking dùng để tính phí hủy không hợp lệ.');
        }

        $localCheckIn = $this->localCheckIn($checkIn, $checkInTime, $timezone);
        $localCancellation = $cancelledAt->setTimezone($localCheckIn->getTimezone());
        $fee = Money::vnd(0);

        foreach ($items as $item) {
            $fee = $fee->add($this->itemFee($item, $localCheckIn, $localCancellation));
        }

        return $fee->compareTo($bookingTotal) > 0 ? $bookingTotal : $fee;
    }

    /** @param array<string, mixed> $item */
    private function itemFee(
        array $item,
        DateTimeImmutable $localCheckIn,
        DateTimeImmutable $localCancellation,
    ): Money {
        $itemTotal = $this->money($item['total'] ?? null, 'tổng hạng mục');
        $snapshot = $item['cancellationSnapshot'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('Snapshot chính sách hủy của hạng mục không hợp lệ.');
        }

        $policyType = strtoupper(trim((string) ($snapshot['policyType'] ?? '')));
        if (!in_array($policyType, ['FLEXIBLE', 'NON_REFUNDABLE'], true)) {
            throw new InvalidArgumentException('Loại chính sách trong snapshot không hợp lệ.');
        }

        if ($policyType === 'FLEXIBLE') {
            $freeHours = $snapshot['freeCancellationHours'] ?? null;
            if ($freeHours !== null) {
                if (filter_var($freeHours, FILTER_VALIDATE_INT) === false || (int) $freeHours < 0) {
                    throw new InvalidArgumentException('Số giờ hủy miễn phí trong snapshot không hợp lệ.');
                }
                $deadline = $localCheckIn->modify('-' . (int) $freeHours . ' hours');
                if ($localCancellation < $deadline) {
                    return Money::vnd(0);
                }
            }
        }

        $penaltyType = strtoupper(trim((string) ($snapshot['penaltyType'] ?? '')));
        $penaltyValue = trim((string) ($snapshot['penaltyValue'] ?? ''));

        return match ($penaltyType) {
            'NONE' => $this->noneFee($penaltyValue),
            'PERCENT' => $this->percentFee($itemTotal, $penaltyValue),
            'FIRST_NIGHT' => $this->firstNightFee($item, $penaltyValue),
            'FULL_STAY' => $this->fullStayFee($itemTotal, $penaltyValue),
            default => throw new InvalidArgumentException('Loại phạt trong snapshot không hợp lệ.'),
        };
    }

    private function noneFee(string $penaltyValue): Money
    {
        if ($penaltyValue !== '0' && $penaltyValue !== '0.00') {
            throw new InvalidArgumentException('Phạt NONE phải có giá trị chuẩn hóa bằng 0.');
        }
        return Money::vnd(0);
    }

    private function percentFee(Money $itemTotal, string $penaltyValue): Money
    {
        $rate = $this->percentage($penaltyValue, 'Tỷ lệ phạt hủy');
        return $itemTotal->percentage($rate);
    }

    /** @param array<string, mixed> $item */
    private function firstNightFee(array $item, string $penaltyValue): Money
    {
        if ($penaltyValue !== '1' && $penaltyValue !== '1.00') {
            throw new InvalidArgumentException('Phạt FIRST_NIGHT phải có giá trị chuẩn hóa bằng 1.');
        }
        $nights = $item['nights'] ?? null;
        if (!is_array($nights) || $nights === [] || !array_is_list($nights)) {
            throw new InvalidArgumentException('Snapshot đêm của hạng mục không hợp lệ.');
        }
        usort($nights, static fn (array $left, array $right): int =>
            ((string) ($left['stayDate'] ?? '')) <=> ((string) ($right['stayDate'] ?? ''))
        );
        return $this->money($nights[0]['total'] ?? null, 'thành tiền đêm đầu');
    }

    private function fullStayFee(Money $itemTotal, string $penaltyValue): Money
    {
        if ($penaltyValue !== '100' && $penaltyValue !== '100.00') {
            throw new InvalidArgumentException('Phạt FULL_STAY phải có giá trị chuẩn hóa bằng 100.');
        }
        return $itemTotal;
    }

    private function localCheckIn(string $date, string $time, string $timezone): DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('Múi giờ cơ sở trong snapshot không hợp lệ.');
        }
        $value = $date . ' ' . $time;
        $checkIn = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($checkIn === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $checkIn->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Ngày hoặc giờ nhận phòng trong snapshot không hợp lệ.');
        }
        return $checkIn;
    }

    private function money(mixed $value, string $label): Money
    {
        if ($value instanceof Money) {
            $money = $value;
        } elseif (is_string($value) || is_int($value)) {
            $money = Money::vnd($value);
        } else {
            throw new InvalidArgumentException(ucfirst($label) . ' trong snapshot không hợp lệ.');
        }
        if ($money->amount() < 0) {
            throw new InvalidArgumentException(ucfirst($label) . ' không được âm.');
        }
        return $money;
    }

    private function percentage(string $value, string $label): string
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException($label . ' trong snapshot không hợp lệ.');
        }
        $hundredths = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($hundredths > 10_000) {
            throw new InvalidArgumentException($label . ' không được vượt quá 100.');
        }
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use InvalidArgumentException;

final readonly class ReviewRequest
{
    /** @param array<string, int|null> $categories */
    public function __construct(public int $overall, public array $categories, public ?string $comment)
    {
        if ($overall < 1 || $overall > 10) {
            throw new InvalidArgumentException('Điểm tổng phải từ 1 đến 10.');
        }
        foreach ($categories as $score) {
            if ($score !== null && ($score < 1 || $score > 10)) {
                throw new InvalidArgumentException('Điểm thành phần phải từ 1 đến 10 hoặc để trống.');
            }
        }
        if ($comment !== null && mb_strlen($comment) > 3000) {
            throw new InvalidArgumentException('Bình luận tối đa 3000 ký tự.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $fields = ['cleanliness', 'location', 'staff', 'comfort', 'facilities', 'value'];
        $categories = [];
        foreach ($fields as $field) {
            $value = $input[$field] ?? null;
            $categories[$field] = $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_INT);
            if ($categories[$field] === false) {
                throw new InvalidArgumentException('Điểm thành phần không hợp lệ.');
            }
        }
        $overall = filter_var($input['overall'] ?? null, FILTER_VALIDATE_INT);
        if ($overall === false) {
            throw new InvalidArgumentException('Điểm tổng không hợp lệ.');
        }
        $comment = trim(is_string($input['comment'] ?? null) ? $input['comment'] : '');
        return new self($overall, $categories, $comment === '' ? null : $comment);
    }
}

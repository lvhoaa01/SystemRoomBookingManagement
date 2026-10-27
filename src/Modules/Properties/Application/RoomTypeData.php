<?php

declare(strict_types=1);

namespace App\Modules\Properties\Application;

use App\Modules\Properties\Domain\BedConfiguration;
use App\Modules\Properties\Domain\ImageCollection;
use InvalidArgumentException;

final readonly class RoomTypeData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public int $maxGuests,
        public int $maxAdults,
        public int $maxChildren,
        public BedConfiguration $beds,
        public ?string $areaM2,
        public bool $smokingAllowed,
        public ImageCollection $images,
        public string $status,
    ) {
        if (preg_match('/^[A-Z0-9_-]{2,24}$/', $this->code) !== 1) {
            throw new InvalidArgumentException('Mã loại phòng phải có 2-24 ký tự A-Z, 0-9, gạch dưới hoặc gạch ngang.');
        }
        if (mb_strlen($this->name) < 2 || mb_strlen($this->name) > 150) {
            throw new InvalidArgumentException('Tên loại phòng phải có từ 2 đến 150 ký tự.');
        }
        if ($this->description !== null && mb_strlen($this->description) > 10000) {
            throw new InvalidArgumentException('Mô tả loại phòng không được vượt quá 10000 ký tự.');
        }
        if ($this->maxGuests < 1 || $this->maxAdults < 1 || $this->maxAdults > $this->maxGuests
            || $this->maxChildren < 0 || $this->maxChildren > $this->maxGuests) {
            throw new InvalidArgumentException('Sức chứa loại phòng không hợp lệ.');
        }
        if ($this->areaM2 !== null && (!is_numeric($this->areaM2) || (float) $this->areaM2 <= 0)) {
            throw new InvalidArgumentException('Diện tích phòng phải lớn hơn 0.');
        }
        if (!in_array($this->status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new InvalidArgumentException('Trạng thái loại phòng không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $description = trim((string) ($input['description'] ?? ''));
        $area = trim((string) ($input['areaM2'] ?? ''));
        return new self(
            strtoupper(trim((string) ($input['code'] ?? ''))),
            trim((string) ($input['name'] ?? '')),
            $description === '' ? null : $description,
            filter_var($input['maxGuests'] ?? null, FILTER_VALIDATE_INT) ?: 0,
            filter_var($input['maxAdults'] ?? null, FILTER_VALIDATE_INT) ?: 0,
            filter_var($input['maxChildren'] ?? 0, FILTER_VALIDATE_INT) === false
                ? -1 : (int) ($input['maxChildren'] ?? 0),
            BedConfiguration::fromJson((string) ($input['bedsJson'] ?? '[]')),
            $area === '' ? null : $area,
            in_array(strtolower((string) ($input['smokingAllowed'] ?? '')), ['1', 'true', 'on', 'yes'], true),
            ImageCollection::fromJson((string) ($input['imagesJson'] ?? '[]')),
            strtoupper(trim((string) ($input['status'] ?? 'ACTIVE'))),
        );
    }

    /** @return array<string, mixed> */
    public function persistenceData(): array
    {
        return [
            'code' => $this->code, 'name' => $this->name, 'description' => $this->description,
            'maxGuests' => $this->maxGuests, 'maxAdults' => $this->maxAdults,
            'maxChildren' => $this->maxChildren, 'bedsJson' => $this->beds->toJson(),
            'areaM2' => $this->areaM2, 'smokingAllowed' => $this->smokingAllowed ? 1 : 0,
            'imagesJson' => $this->images->toJson(), 'status' => $this->status,
        ];
    }
}

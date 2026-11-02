<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Infrastructure\AuditLogRepository;
use InvalidArgumentException;
use JsonException;

final readonly class AuditLogService
{
    public function __construct(private AuditLogRepository $audits)
    {
    }

    public function page(int $page, ?string $action, ?string $entityType, ?string $query): AdminPage
    {
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('Số trang audit không hợp lệ.');
        }
        $action = $this->optionalPattern($action, '/^[A-Z][A-Z0-9_]{2,79}$/', 'Action audit');
        $entityType = $this->optionalPattern($entityType, '/^[A-Za-z][A-Za-z0-9_]{0,79}$/', 'Loại đối tượng audit');
        $query = trim((string) $query);
        if (mb_strlen($query) > 80) {
            throw new InvalidArgumentException('Từ khóa audit không được vượt quá 80 ký tự.');
        }
        $result = $this->audits->paginate($page, 25, $action, $entityType, $query === '' ? null : $query);
        $items = array_map(fn (array $row): array => $this->present($row), $result['items']);
        return new AdminPage($items, $page, 25, $result['total']);
    }

    /** @return array{actions: list<string>, entityTypes: list<string>} */
    public function filterOptions(): array
    {
        return $this->audits->filterOptions();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['actorId'] = $row['actorId'] === null ? null : (int) $row['actorId'];
        $row['before'] = $this->mask($this->decode($row['beforeJson'] ?? null));
        $row['after'] = $this->mask($this->decode($row['afterJson'] ?? null));
        $row['clientIp'] = $this->maskIp($row['clientIp'] === null ? null : (string) $row['clientIp']);
        unset($row['beforeJson'], $row['afterJson']);
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $decoded = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['_error' => 'Audit JSON không thể hiển thị.'];
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function mask(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $childKey => $child) {
                $masked[$childKey] = $this->mask($child, (string) $childKey);
            }
            return $masked;
        }
        if (!is_scalar($value) || $value === null) {
            return $value;
        }
        $normalized = strtolower($key);
        if (str_contains($normalized, 'email')) {
            return $this->maskEmail((string) $value);
        }
        if (str_contains($normalized, 'phone') || str_contains($normalized, 'dienthoai')) {
            return $this->maskPhone((string) $value);
        }
        if (in_array($normalized, ['bookername', 'fullname', 'guestname', 'tennguoidat'], true)) {
            return '[ĐÃ CHE]';
        }
        return $value;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '[ĐÃ CHE]';
        }
        return mb_substr($parts[0], 0, 1) . '***@' . $parts[1];
    }

    private function maskPhone(string $phone): string
    {
        $tail = substr(preg_replace('/\D+/', '', $phone) ?? '', -3);
        return $tail === '' ? '[ĐÃ CHE]' : '*******' . $tail;
    }

    private function maskIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, max(1, (int) strrpos($ip, ':'))) . ':xxxx';
        }
        return '[ĐÃ CHE]';
    }

    private function optionalPattern(?string $value, string $pattern, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($label . ' không hợp lệ.');
        }
        return $value;
    }
}

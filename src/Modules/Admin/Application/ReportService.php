<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Infrastructure\ReportRepository;
use InvalidArgumentException;

final readonly class ReportService
{
    public function __construct(private ReportRepository $reports)
    {
    }

    /** @return array<string, mixed> */
    public function partner(ReportCriteria $criteria, int $organizationId): array
    {
        if ($organizationId < 1 || !$this->reports->organizationExists($organizationId)) {
            throw new InvalidArgumentException('Tổ chức báo cáo không hợp lệ.');
        }
        if ($criteria->organizationId !== null && $criteria->organizationId !== $organizationId) {
            throw new InvalidArgumentException('Partner không được đổi phạm vi tổ chức báo cáo.');
        }
        $this->assertProperty($criteria->propertyId, $organizationId);
        return $this->reports->snapshot($criteria, $organizationId);
    }

    /** @return array<string, mixed> */
    public function admin(ReportCriteria $criteria): array
    {
        if ($criteria->organizationId !== null && !$this->reports->organizationExists($criteria->organizationId)) {
            throw new InvalidArgumentException('Tổ chức báo cáo không tồn tại.');
        }
        $this->assertProperty($criteria->propertyId, $criteria->organizationId);
        return [
            ...$this->reports->snapshot($criteria, null),
            'operations' => $this->reports->adminOperationalSummary($criteria),
        ];
    }

    /** @return array{organizations: list<array<string,mixed>>, properties: list<array<string,mixed>>} */
    public function filterOptions(?int $organizationId): array
    {
        return $this->reports->filterOptions($organizationId);
    }

    public function paymentIssues(int $page): AdminPage
    {
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('Số trang lỗi payment không hợp lệ.');
        }
        $result = $this->reports->paymentIssues($page, 25);
        return new AdminPage($result['items'], $page, 25, $result['total']);
    }

    private function assertProperty(?int $propertyId, ?int $organizationId): void
    {
        if ($propertyId !== null && !$this->reports->propertyInScope($propertyId, $organizationId)) {
            throw new InvalidArgumentException('Cơ sở không thuộc phạm vi báo cáo.');
        }
    }
}

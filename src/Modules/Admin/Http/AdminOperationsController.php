<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Admin\Application\AdminPage;
use App\Modules\Admin\Application\AuditLogService;
use App\Modules\Admin\Application\ReportCriteria;
use App\Modules\Admin\Application\ReportService;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Clock\BusinessTime;
use App\Shared\Clock\ClockInterface;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class AdminOperationsController
{
    public function __construct(
        private AuditLogService $audits,
        private ReportService $reports,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function audits(Request $request): Response
    {
        try {
            $page = $this->audits->page($this->page($request), $this->query($request, 'action'),
                $this->query($request, 'entityType'), $this->query($request, 'q'));
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/admin/audits'), 303);
        }
        return $this->view('admin/audits', [
            'title' => 'Nhật ký hệ thống', 'activeNavigation' => 'audits',
            'pageData' => $this->pageData($page), 'filterOptions' => $this->audits->filterOptions(),
            'actionFilter' => $this->query($request, 'action'),
            'entityTypeFilter' => $this->query($request, 'entityType'),
            'searchQuery' => $this->query($request, 'q'),
        ]);
    }

    public function paymentIssues(Request $request): Response
    {
        try {
            $page = $this->reports->paymentIssues($this->page($request));
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/admin/payment-issues'), 303);
        }
        return $this->view('admin/payment-issues', [
            'title' => 'Lỗi thanh toán và hoàn tiền', 'activeNavigation' => 'payment-issues',
            'pageData' => $this->pageData($page),
        ]);
    }

    public function adminReports(Request $request): Response
    {
        $criteria = $this->criteria($request);
        try {
            $report = $this->reports->admin($criteria);
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/admin/reports'), 303);
        }
        return $this->view('admin/reports', [
            'title' => 'Báo cáo toàn hệ thống', 'activeNavigation' => 'reports',
            'report' => $report, 'filterOptions' => $this->reports->filterOptions($criteria->organizationId),
            'isAdminReport' => true,
        ]);
    }

    public function partnerReports(Request $request): Response
    {
        $organization = $this->identity->organization()
            ?? throw new DomainException('Phiên tổ chức đối tác không hợp lệ.');
        $criteria = $this->criteria($request);
        try {
            $report = $this->reports->partner($criteria, $organization->id);
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/partner/reports'), 303);
        }
        return $this->view('admin/reports', [
            'title' => 'Báo cáo tổ chức', 'activeNavigation' => 'reports',
            'report' => $report, 'filterOptions' => $this->reports->filterOptions($organization->id),
            'isAdminReport' => false,
        ]);
    }

    private function criteria(Request $request): ReportCriteria
    {
        try {
            return ReportCriteria::fromInput($request->query, BusinessTime::inTimezone($this->clock->now(), 'Asia/Ho_Chi_Minh'));
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return ReportCriteria::fromInput([], BusinessTime::inTimezone($this->clock->now(), 'Asia/Ho_Chi_Minh'));
        }
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data): Response
    {
        $user = $this->user();
        return Response::html($this->views->render($template, [
            ...$data, 'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(), 'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName, 'currentUserRole' => $user->role,
        ], 'layouts/app'))->withHeader('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function pageData(AdminPage $page): array
    {
        return ['items' => $page->items, 'page' => $page->page, 'total' => $page->total,
            'pageCount' => $page->pageCount(), 'perPage' => $page->perPage];
    }

    private function user(): CurrentUser
    {
        return $this->identity->currentUser() ?? throw new DomainException('Phiên người dùng không hợp lệ.');
    }

    private function page(Request $request): int
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT);
        return is_int($page) ? $page : 0;
    }

    private function query(Request $request, string $key): string
    {
        return is_string($request->query[$key] ?? null) ? trim($request->query[$key]) : '';
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}

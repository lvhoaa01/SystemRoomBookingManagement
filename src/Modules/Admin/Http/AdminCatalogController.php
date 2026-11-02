<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Admin\Application\AdminCatalogService;
use App\Modules\Admin\Application\AdminPage;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class AdminCatalogController
{
    public function __construct(
        private AdminCatalogService $catalog,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function accounts(Request $request): Response
    {
        try {
            $page = $this->catalog->accounts(
                $this->page($request),
                $this->query($request, 'role'),
                $this->query($request, 'status'),
                $this->query($request, 'q'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/admin/accounts'), 303);
        }
        return $this->view('admin/accounts', [
            'title' => 'Quản lý tài khoản', 'activeNavigation' => 'accounts',
            'pageData' => $this->pageData($page),
            'roleFilter' => $this->query($request, 'role'),
            'statusFilter' => $this->query($request, 'status'),
            'searchQuery' => $this->query($request, 'q'),
        ]);
    }

    public function accountStatus(Request $request): Response
    {
        try {
            $this->catalog->changeAccountStatus(
                (int) $request->route('userId', '0'),
                is_string($request->body['status'] ?? null) ? $request->body['status'] : '',
                $this->userId(), $request->requestId, $request->clientIp,
            );
            $this->flash->add('success', 'Đã cập nhật trạng thái tài khoản.');
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/admin/accounts'), 303);
    }

    public function facilities(Request $request): Response
    {
        try {
            $facilities = $this->catalog->facilities(
                $this->query($request, 'scope'), $this->query($request, 'status'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/admin/facilities'), 303);
        }
        return $this->view('admin/facilities', [
            'title' => 'Danh mục tiện nghi', 'activeNavigation' => 'facilities',
            'facilities' => $facilities,
            'scopeFilter' => $this->query($request, 'scope'),
            'statusFilter' => $this->query($request, 'status'),
        ]);
    }

    public function createFacility(Request $request): Response
    {
        try {
            $this->catalog->createFacility($request->body, $this->userId(), $request->requestId, $request->clientIp);
            $this->flash->add('success', 'Đã tạo tiện nghi.');
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/admin/facilities'), 303);
    }

    public function updateFacility(Request $request): Response
    {
        try {
            $this->catalog->updateFacility((int) $request->route('facilityId', '0'), $request->body,
                $this->userId(), $request->requestId, $request->clientIp);
            $this->flash->add('success', 'Đã cập nhật tiện nghi.');
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/admin/facilities'), 303);
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data): Response
    {
        $user = $this->identity->currentUser() ?? throw new DomainException('Phiên admin không hợp lệ.');
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

    private function userId(): int
    {
        return ($this->identity->currentUser() ?? throw new DomainException('Phiên admin không hợp lệ.'))->id;
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

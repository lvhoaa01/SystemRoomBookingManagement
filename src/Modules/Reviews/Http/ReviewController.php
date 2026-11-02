<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Reviews\Application\ReviewRequest;
use App\Modules\Reviews\Application\ReviewService;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class ReviewController
{
    public function __construct(
        private ReviewService $reviews,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function create(Request $request): Response
    {
        $code = (string) $request->route('code', '');
        try {
            $this->reviews->create($code, $this->userId(), ReviewRequest::fromInput($request->body),
                $request->requestId, $request->clientIp);
            $this->flash->add('success', 'Cảm ơn bạn. Đánh giá đã được đăng.');
        } catch (BookingException|InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/account/bookings/' . rawurlencode($code)), 303);
    }

    public function adminIndex(Request $request): Response
    {
        $user = $this->identity->currentUser() ?? throw new DomainException('Phiên admin không hợp lệ.');
        return Response::html($this->views->render('reviews/admin-index', [
            'title' => 'Kiểm duyệt đánh giá', 'reviews' => $this->reviews->adminList(),
            'activeNavigation' => 'reviews', 'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(), 'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName, 'currentUserRole' => $user->role,
        ], 'layouts/app'))->withHeader('Cache-Control', 'no-store, private');
    }

    public function adminModerate(Request $request): Response
    {
        try {
            $this->reviews->moderate((int) $request->route('reviewId', '0'),
                is_string($request->body['status'] ?? null) ? $request->body['status'] : '',
                $this->userId(), $request->requestId, $request->clientIp);
            $this->flash->add('success', 'Đã cập nhật trạng thái đánh giá.');
        } catch (BookingException|InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }
        return Response::redirect($this->url('/admin/reviews'), 303);
    }

    private function userId(): int
    {
        return ($this->identity->currentUser() ?? throw new DomainException('Phiên người dùng không hợp lệ.'))->id;
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}

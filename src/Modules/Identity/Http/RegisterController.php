<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Application\RegisterCustomerService;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;

final readonly class RegisterController
{
    public function __construct(
        private RegisterCustomerService $registration,
        private IdentitySession $identitySession,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function show(Request $request): Response
    {
        if ($this->identitySession->currentUser() !== null) {
            return Response::redirect($this->url('/'), 303);
        }

        return $this->form([], [], $this->flash->consumeAll());
    }

    public function store(Request $request): Response
    {
        $fullName = $this->input($request, 'fullName');
        $email = $this->input($request, 'email');
        $phone = $this->input($request, 'phone');
        $result = $this->registration->register(
            $fullName,
            $email,
            $phone === '' ? null : $phone,
            $this->input($request, 'password'),
            $this->input($request, 'passwordConfirmation'),
        );

        if (!$result->succeeded()) {
            return $this->form($result->validation->errors(), [
                'fullName' => $fullName,
                'email' => $email,
                'phone' => $phone,
            ], [], 422);
        }

        $this->flash->add('success', 'Tạo tài khoản thành công. Bạn có thể đăng nhập ngay.');
        return Response::redirect($this->url('/auth/login'), 303);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, string> $old
     * @param array<string, list<string>> $flashes
     */
    private function form(array $errors, array $old, array $flashes, int $status = 200): Response
    {
        return Response::html($this->views->render('auth/register', [
            'title' => 'Đăng ký tài khoản',
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'errors' => $errors,
            'old' => $old,
            'flashes' => $flashes,
            'currentUserName' => null,
        ], 'layouts/app'), $status);
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}

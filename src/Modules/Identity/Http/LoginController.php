<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;

final readonly class LoginController
{
    public function __construct(
        private LoginService $login,
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

        return $this->form([], '', $this->flash->consumeAll());
    }

    public function store(Request $request): Response
    {
        $identifier = $this->input($request, 'identifier');
        $result = $this->login->login($identifier, $this->input($request, 'password'), $request->clientIp);
        if (!$result->succeeded()) {
            return $this->form($result->validation->errors(), $identifier, [], 422);
        }

        $this->flash->add('success', 'Đăng nhập thành công.');
        return Response::redirect($this->url('/'), 303);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, list<string>> $flashes
     */
    private function form(array $errors, string $identifier, array $flashes, int $status = 200): Response
    {
        return Response::html($this->views->render('auth/login', [
            'title' => 'Đăng nhập',
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'errors' => $errors,
            'identifier' => $identifier,
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

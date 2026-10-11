<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppConfig;
use RuntimeException;

final class SessionBootstrap
{
    private const CSRF_KEY = '_csrf_token';
    private const LAST_ACTIVITY_KEY = '_last_activity_at';

    public function __construct(private readonly AppConfig $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->ensureSecurityState();
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException("Không thể khởi tạo session sau khi gửi output tại {$file}:{$line}.");
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->config->sessionLifetimeSeconds);
        session_name($this->config->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->config->usesSecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Không thể khởi tạo session.');
        }

        $this->ensureSecurityState();
    }

    public function regenerateForAuthentication(): void
    {
        $this->requireActiveSession();
        session_regenerate_id(true);
        $this->rotateCsrfToken();
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = $this->rotateCsrfToken();
        }

        return $token;
    }

    public function rotateCsrfToken(): string
    {
        return $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    private function ensureSecurityState(): void
    {
        $now = time();
        $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
        if (is_int($lastActivity) && $now - $lastActivity > $this->config->sessionLifetimeSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
        $this->csrfToken();
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session chưa được khởi tạo.');
        }
    }
}

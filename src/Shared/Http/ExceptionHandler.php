<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Database\TransactionManager;
use App\Shared\Logging\Logger;
use InvalidArgumentException;
use PDOException;
use Throwable;

final readonly class ExceptionHandler
{
    public function __construct(
        private AppConfig $config,
        private Logger $logger,
    ) {
    }

    public function handle(Throwable $exception, Request $request): Response
    {
        $mapped = $this->map($exception);
        $sessionUser = $_SESSION['_current_user'] ?? null;
        $actorId = is_array($sessionUser) && is_int($sessionUser['id'] ?? null)
            ? $sessionUser['id']
            : null;
        $this->logger->log($mapped['status'] >= 500 ? 'error' : 'warning', 'HTTP_REQUEST_FAILED', [
            'requestId' => $request->requestId,
            'actorId' => $actorId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $mapped['status'],
            'errorCode' => $mapped['code'],
            'exceptionClass' => $exception::class,
        ]);

        $headers = ['X-Request-ID' => $request->requestId, ...$mapped['headers']];
        if ($request->expectsJson()) {
            return Response::json([
                'error' => [
                    'code' => $mapped['code'],
                    'message' => $mapped['message'],
                    'requestId' => $request->requestId,
                    'details' => $mapped['details'],
                ],
            ], $mapped['status'], $headers);
        }

        $title = $mapped['status'] >= 500 ? 'Hệ thống tạm thời gặp sự cố' : 'Không thể xử lý yêu cầu';
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $baseUrl = rtrim($this->config->url, '/');
        $retryUrl = $request->method === 'GET' ? $baseUrl . $request->path : $baseUrl . '/';
        $body = '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $escape($title) . '</title><link rel="stylesheet" href="'
            . $escape($baseUrl . '/assets/css/app.css') . '"></head><body class="error-page">'
            . '<main class="error-state"><span>' . $mapped['status'] . '</span><h1>' . $escape($title)
            . '</h1><p>' . $escape($mapped['message']) . '</p><div><a class="button button--primary" href="'
            . $escape($retryUrl) . '">Thử lại</a><a class="button button--secondary" href="' . $escape($baseUrl . '/')
            . '">Về trang chủ</a></div><small>Mã yêu cầu: ' . $escape($request->requestId)
            . ' · ' . $escape($mapped['code']) . '</small></main></body></html>';

        return Response::html($body, $mapped['status'], $headers);
    }

    /**
     * @return array{status: int, code: string, message: string, details: array<string, mixed>, headers: array<string, string>}
     */
    private function map(Throwable $exception): array
    {
        if ($exception instanceof HttpException) {
            return [
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
                'headers' => $exception->headers,
            ];
        }

        if ($exception instanceof InvalidArgumentException) {
            return [
                'status' => 400,
                'code' => 'VALIDATION_ERROR',
                'message' => 'Dữ liệu gửi lên không hợp lệ.',
                'details' => [],
                'headers' => [],
            ];
        }

        if ($exception instanceof PDOException && TransactionManager::isRetryable($exception)) {
            return [
                'status' => 503,
                'code' => 'TEMPORARILY_UNAVAILABLE',
                'message' => 'Hệ thống đang bận. Vui lòng thử lại.',
                'details' => [],
                'headers' => ['Retry-After' => '1'],
            ];
        }

        return [
            'status' => 500,
            'code' => 'INTERNAL_ERROR',
            'message' => 'Ứng dụng chưa thể xử lý yêu cầu.',
            'details' => [],
            'headers' => [],
        ];
    }
}

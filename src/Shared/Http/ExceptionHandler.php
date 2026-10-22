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
        $this->logger->log($mapped['status'] >= 500 ? 'error' : 'warning', 'HTTP_REQUEST_FAILED', [
            'requestId' => $request->requestId,
            'actorId' => $_SESSION['_current_user']['id'] ?? null,
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
        $body = '<!doctype html><html lang="vi"><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1><p>' . htmlspecialchars($mapped['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p><small>Mã yêu cầu: '
            . htmlspecialchars($request->requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</small></html>';

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

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Http\AuthenticationRequiredException;
use App\Shared\Http\ForbiddenHttpException;

final readonly class AuthMiddleware implements Middleware
{
    /** @param list<string> $allowedRoles */
    public function __construct(
        private array $allowedRoles = [],
        private bool $requireActiveOrganization = false,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            throw new AuthenticationRequiredException();
        }
        if (($_SESSION['account_status'] ?? null) !== 'HOAT_DONG') {
            throw new ForbiddenHttpException('ACCOUNT_DISABLED', 'Tài khoản hiện không hoạt động.');
        }

        $role = $_SESSION['role'] ?? null;
        if ($this->allowedRoles !== [] && (!is_string($role) || !in_array($role, $this->allowedRoles, true))) {
            throw new ForbiddenHttpException();
        }

        if ($this->requireActiveOrganization) {
            $organizationId = $_SESSION['organization_id'] ?? null;
            if ($role !== 'PARTNER' || $organizationId === null) {
                throw new ForbiddenHttpException('TENANT_SCOPE_DENIED', 'Không xác định được phạm vi tổ chức.');
            }
            if (($_SESSION['organization_status'] ?? null) !== 'HOAT_DONG') {
                throw new ForbiddenHttpException('TENANT_SCOPE_DENIED', 'Tổ chức hiện không hoạt động.');
            }
        }

        return $next->handle($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\AuthenticationRequiredException;
use App\Shared\Http\ForbiddenHttpException;
use InvalidArgumentException;

final readonly class RoleMiddleware implements Middleware
{
    /** @param non-empty-list<string> $allowedRoles */
    public function __construct(
        private IdentitySession $identitySession,
        private array $allowedRoles,
    ) {
        if ($this->allowedRoles === []) {
            throw new InvalidArgumentException('RoleMiddleware phải có ít nhất một vai trò.');
        }
        foreach ($this->allowedRoles as $role) {
            if (!in_array($role, UserAccount::roles(), true)) {
                throw new InvalidArgumentException("Vai trò middleware không hợp lệ: {$role}");
            }
        }
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $user = $this->identitySession->currentUser();
        if ($user === null) {
            throw new AuthenticationRequiredException();
        }
        if (!in_array($user->role, $this->allowedRoles, true)) {
            throw new ForbiddenHttpException();
        }

        return $next->handle($request);
    }
}

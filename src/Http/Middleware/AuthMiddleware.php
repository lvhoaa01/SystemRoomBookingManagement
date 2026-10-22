<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\AuthenticationRequiredException;
use App\Shared\Http\ForbiddenHttpException;

final readonly class AuthMiddleware implements Middleware
{
    public function __construct(private IdentitySession $identitySession)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $user = $this->identitySession->currentUser();
        if ($user === null) {
            throw new AuthenticationRequiredException();
        }
        if (!$user->isActive()) {
            throw new ForbiddenHttpException('ACCOUNT_DISABLED', 'Tài khoản hiện không hoạt động.');
        }

        return $next->handle($request);
    }
}

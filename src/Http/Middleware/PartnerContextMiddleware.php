<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\AuthenticationRequiredException;
use App\Shared\Http\ForbiddenHttpException;

final readonly class PartnerContextMiddleware implements Middleware
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

        $organization = $this->identitySession->organization();
        if (!$user->hasRole(UserAccount::ROLE_PARTNER) || $organization === null || !$organization->isActive()) {
            throw new ForbiddenHttpException(
                'TENANT_SCOPE_DENIED',
                'Không xác định được phạm vi tổ chức đang hoạt động.',
            );
        }

        return $next->handle($request);
    }
}

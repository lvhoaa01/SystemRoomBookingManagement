<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Middleware\CallableRequestHandler;
use App\Http\Middleware\PartnerContextMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Shared\Http\ForbiddenHttpException;
use DateTimeZone;
use Tests\Support\TestCase;

final class IdentityMiddlewareTest extends TestCase
{
    public function test_role_middleware_allows_only_configured_roles(): void
    {
        $this->putUserInSession(UserAccount::ROLE_CUSTOMER);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));

        $allowed = new RoleMiddleware($this->identitySession(), [UserAccount::ROLE_CUSTOMER]);
        self::assertSame(200, $allowed->process(new Request('GET', '/account'), $next)->status());

        $this->expectException(ForbiddenHttpException::class);
        (new RoleMiddleware($this->identitySession(), [UserAccount::ROLE_PARTNER]))
            ->process(new Request('GET', '/partner'), $next);
    }

    public function test_partner_context_requires_active_server_side_membership(): void
    {
        $this->putUserInSession(UserAccount::ROLE_PARTNER);
        $_SESSION['_organization_context'] = [
            'id' => 5,
            'code' => 'ORG005',
            'name' => 'Tổ chức 5',
            'status' => 'HOAT_DONG',
            'isOwner' => false,
        ];
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));

        $response = (new PartnerContextMiddleware($this->identitySession()))
            ->process(new Request('GET', '/partner'), $next);

        self::assertSame(200, $response->status());
    }

    public function test_partner_context_rejects_partner_without_membership(): void
    {
        $this->putUserInSession(UserAccount::ROLE_PARTNER);
        $next = new CallableRequestHandler(static fn (): Response => Response::json(['ok' => true]));

        $this->expectException(ForbiddenHttpException::class);
        (new PartnerContextMiddleware($this->identitySession()))
            ->process(new Request('GET', '/partner'), $next);
    }

    private function putUserInSession(string $role): void
    {
        $_SESSION['_current_user'] = [
            'id' => 10,
            'code' => 'USER010',
            'fullName' => 'Người dùng',
            'email' => 'user@example.test',
            'role' => $role,
            'status' => 'HOAT_DONG',
        ];
    }

    private function identitySession(): IdentitySession
    {
        return new IdentitySession(new SessionBootstrap(new AppConfig(
            'test',
            true,
            'http://localhost',
            new DateTimeZone('UTC'),
        )));
    }
}

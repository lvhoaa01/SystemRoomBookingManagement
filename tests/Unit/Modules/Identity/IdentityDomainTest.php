<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Domain\OrganizationContext;
use App\Modules\Identity\Domain\UserAccount;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class IdentityDomainTest extends TestCase
{
    public function test_user_account_verifies_password_without_exposing_hash_to_current_user(): void
    {
        $account = new UserAccount(
            10,
            'KH0010',
            'Nguyễn An',
            'an@example.test',
            null,
            password_hash('Secret123', PASSWORD_DEFAULT),
            UserAccount::ROLE_CUSTOMER,
            UserAccount::STATUS_ACTIVE,
        );

        self::assertTrue($account->verifyPassword('Secret123'));
        self::assertFalse($account->verifyPassword('wrong'));
        self::assertTrue($account->isActive());
        self::assertEquals(
            new CurrentUser(10, 'KH0010', 'Nguyễn An', 'an@example.test', 'CUSTOMER', 'HOAT_DONG'),
            $account->toCurrentUser(),
        );
    }

    public function test_current_user_checks_role_and_organization_checks_active_status(): void
    {
        $user = new CurrentUser(3, 'DT003', 'Đối tác', 'partner@example.test', 'PARTNER', 'HOAT_DONG');
        $organization = new OrganizationContext(8, 'ORG008', 'Khách sạn A', 'HOAT_DONG', true);

        self::assertTrue($user->hasRole(UserAccount::ROLE_PARTNER));
        self::assertTrue($user->isActive());
        self::assertTrue($organization->isActive());
        self::assertTrue($organization->isOwner);
    }

    public function test_identity_value_objects_reject_invalid_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurrentUser(0, '', '', 'invalid-email', 'UNKNOWN', 'UNKNOWN');
    }
}

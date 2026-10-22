<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\UserAccount;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;

final readonly class LoginService
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$ZKkf4rY0gKG214CpUi56zeM.n0Csmk4wVOl3n68Nzi6YjunvYhCO6';

    public function __construct(
        private UserRepository $users,
        private PartnerMembershipRepository $memberships,
        private IdentitySession $identitySession,
    ) {
    }

    public function login(string $identifier, string $password): LoginResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || strlen($identifier) > 190 || $password === '' || strlen($password) > 72) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return LoginResult::failed();
        }

        $account = $this->users->findByEmailOrCode($identifier);
        if ($account === null) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return LoginResult::failed();
        }

        $passwordMatches = $account->verifyPassword($password);
        if (!$passwordMatches || !$account->isActive()) {
            return LoginResult::failed();
        }

        $organization = $account->role === UserAccount::ROLE_PARTNER
            ? $this->memberships->findOrganizationForUser($account->id)
            : null;
        $user = $account->toCurrentUser();
        $this->identitySession->signIn($user, $organization);

        return LoginResult::success($user, $organization);
    }
}

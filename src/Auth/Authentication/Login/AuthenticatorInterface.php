<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Login;

use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

interface AuthenticatorInterface
{
    public function login(LoginRequest $request): LoginResult;

    public function logout(PrincipalInterface $principal, ?string $sessionId = null): void;
}

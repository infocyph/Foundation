<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;

final readonly class AuthRuntimeRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->singleton(CurrentPrincipalContext::class, fn() => new CurrentPrincipalContext());

        $this->singleton(Authenticator::class, fn() => new Authenticator(
            accounts: $this->accountProvider(),
            accountStore: $this->accountStore(),
            passwords: $this->passwordVerifier(),
            sessions: $this->service(SessionManager::class),
            ids: $this->idGenerator(),
            audit: $this->auditStore(),
            lockouts: $this->service(LockoutManager::class),
            clock: $this->clock(),
        ));

        $this->singleton(AuthServices::class, fn() => new AuthServices($this->app));

        $this->singleton(AuthActions::class, fn() => new AuthActions(
            services: $this->service(AuthServices::class),
        ));
        $this->alias('foundation.auth.actions', AuthActions::class);

        $this->singleton(AuthManager::class, fn() => new AuthManager(
            services: $this->service(AuthServices::class),
            config: $this->app->config(),
            drivers: $this->service(AuthDriverResolver::class)->summary(),
        ));
    }
}

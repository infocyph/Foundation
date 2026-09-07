<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalState;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Psr\Container\ContainerInterface;

final readonly class AuthRuntimeRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->recipe(
            CurrentPrincipalState::class,
            CurrentPrincipalState::class,
            lifetime: LifetimeEnum::Scoped,
        );
        $this->recipe(CurrentPrincipalContext::class, CurrentPrincipalContext::class, [
            $this->ref(ContainerInterface::class),
        ]);
        $this->recipe(Authenticator::class, Authenticator::class, [
            $this->ref(AccountProviderInterface::class),
            $this->ref(AccountStoreInterface::class),
            $this->ref(PasswordVerifierInterface::class),
            $this->ref(SessionManager::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(AuditEventStoreInterface::class),
            $this->ref(LockoutManager::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(AuthServices::class, AuthServices::class, [
            $this->ref(ContainerInterface::class),
            $this->ref(ConfigRepository::class),
        ]);
        $this->recipe(AuthActions::class, AuthActions::class, [
            $this->ref(AuthServices::class),
        ]);
        $this->alias('foundation.auth.actions', AuthActions::class);
        $this->staticRecipe(
            AuthManager::class,
            AuthRuntimeGraphFactory::class,
            'manager',
            [
                $this->ref(AuthServices::class),
                $this->ref(ConfigRepository::class),
                $this->ref(AuthDriverResolver::class),
            ],
        );
    }
}

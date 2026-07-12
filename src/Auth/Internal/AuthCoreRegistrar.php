<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Support\AcceptAllPasswordPolicy;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Identifiers\IdentifierManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthCoreRegistrar
{
    public function __construct(
        private Container $container,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        $this->container->bind(ClockInterface::class, new SystemClock(), LifetimeEnum::Singleton);
        $this->container->bind(AuthDriverResolver::class, $drivers, LifetimeEnum::Singleton);
        $this->container->bind(AuthIdGeneratorInterface::class, function (): UidAuthIdGenerator {
            $ids = $this->container->has(IdentifierManager::class)
                ? $this->container->get(IdentifierManager::class)
                : null;

            return $ids instanceof IdentifierManager
                ? new UidAuthIdGenerator($ids)
                : new UidAuthIdGenerator();
        }, LifetimeEnum::Singleton);
        $this->container->bind(PasswordPolicyInterface::class, new AcceptAllPasswordPolicy(), LifetimeEnum::Singleton);
    }
}

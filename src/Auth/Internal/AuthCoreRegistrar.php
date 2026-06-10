<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordPolicyInterface;
use Infocyph\AuthLayer\Support\AcceptAllPasswordPolicy;
use Infocyph\AuthLayer\Support\SystemClock;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Uid\UidAuthIdGenerator;
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
        $this->container->bind(AuthIdGeneratorInterface::class, new UidAuthIdGenerator(), LifetimeEnum::Singleton);
        $this->container->bind(PasswordPolicyInterface::class, new AcceptAllPasswordPolicy(), LifetimeEnum::Singleton);
    }
}

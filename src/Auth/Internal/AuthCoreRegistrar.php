<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthIdDriver;
use Infocyph\Foundation\Auth\Support\AcceptAllPasswordPolicy;
use Infocyph\Foundation\Auth\Support\RandomAuthIdGenerator;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthCoreRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        $this->container->bind(ClockInterface::class, new SystemClock(), LifetimeEnum::Singleton);
        $this->container->bind(AuthDriverResolver::class, $drivers, LifetimeEnum::Singleton);
        $this->container->factory(AuthIdGeneratorInterface::class, function () use ($drivers): AuthIdGeneratorInterface {
            if ($drivers->ids() !== AuthIdDriver::UID) {
                return new RandomAuthIdGenerator();
            }

            $ids = $this->app->ids();

            return new UidAuthIdGenerator($ids);
        })->singleton();
        $this->container->bind(PasswordPolicyInterface::class, new AcceptAllPasswordPolicy(), LifetimeEnum::Singleton);
    }
}

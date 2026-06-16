<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordHasher;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordVerifier;
use Infocyph\Foundation\Auth\Support\NativePasswordHasher;
use Infocyph\Foundation\Auth\Support\NativePasswordVerifier;
use Infocyph\Epicrypt\Password\PasswordHasher as EpicryptPasswordEngine;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthPasswordRegistrar
{
    public function __construct(
        private Container $container,
        private EpicryptConfigResolver $epicrypt,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->passwords() === AuthPasswordDriver::EPICRYPT) {
            $options = $this->epicrypt->passwordOptions();

            $this->container->bind(PasswordHasherInterface::class, fn() => new EpicryptPasswordHasher(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ), LifetimeEnum::Singleton);

            $this->container->bind(PasswordVerifierInterface::class, fn() => new EpicryptPasswordVerifier(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ), LifetimeEnum::Singleton);

            return;
        }

        $this->container->bind(PasswordHasherInterface::class, fn() => new NativePasswordHasher(), LifetimeEnum::Singleton);
        $this->container->bind(PasswordVerifierInterface::class, fn() => new NativePasswordVerifier(
            $this->container->get(PasswordHasherInterface::class),
        ), LifetimeEnum::Singleton);
    }
}

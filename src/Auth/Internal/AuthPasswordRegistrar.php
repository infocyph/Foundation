<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Password\PasswordHasher as EpicryptPasswordEngine;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordHasher;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordVerifier;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Support\NativePasswordHasher;
use Infocyph\Foundation\Auth\Support\NativePasswordVerifier;

final readonly class AuthPasswordRegistrar extends AbstractAuthRegistrar
{
    public function __construct(
        Application $app,
        \Infocyph\InterMix\DI\Container $container,
        private EpicryptConfigResolver $epicrypt,
    ) {
        parent::__construct($app, $container);
    }

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->passwords() === AuthPasswordDriver::EPICRYPT) {
            $options = $this->epicrypt->passwordOptions();

            $this->singleton(PasswordHasherInterface::class, fn() => new EpicryptPasswordHasher(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ));

            $this->singleton(PasswordVerifierInterface::class, fn() => new EpicryptPasswordVerifier(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ));

            return;
        }

        $this->singleton(PasswordHasherInterface::class, fn() => new NativePasswordHasher());
        $this->singleton(PasswordVerifierInterface::class, fn() => new NativePasswordVerifier(
            $this->app->make(PasswordHasherInterface::class),
        ));
    }
}

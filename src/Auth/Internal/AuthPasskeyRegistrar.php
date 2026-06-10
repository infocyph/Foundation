<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\AuthLayer\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Support\DisabledPasskeyService;
use Infocyph\Foundation\Auth\Support\InMemoryPasskeyService;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthPasskeyRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->passkey() === AuthPasskeyDriver::DISABLED) {
            $this->container->bind(PasskeyServiceInterface::class, fn() => new DisabledPasskeyService(), LifetimeEnum::Singleton);

            return;
        }

        if ($drivers->passkey() !== AuthPasskeyDriver::MEMORY) {
            throw new ConfigurationException(sprintf(
                'Auth passkey driver "%s" is not implemented yet.',
                $drivers->passkey()->value,
            ));
        }

        $this->container->bind(PasskeyServiceInterface::class, fn() => new InMemoryPasskeyService(
            $this->container->get(PasskeyCredentialStoreInterface::class),
            $this->container->get(ClockInterface::class),
            (int) $this->app->config()->get('auth.passkey_challenge_ttl', 300),
        ), LifetimeEnum::Singleton);
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnChallengeStore;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnCredentialMapper;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPasskeyService;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPublicKeyOptionsFactory;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnRuntime;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
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

        if ($drivers->passkey() === AuthPasskeyDriver::WEBAUTHN) {
            $this->container->bind(WebAuthnConfigResolver::class, fn() => new WebAuthnConfigResolver(
                $this->app->config(),
            ), LifetimeEnum::Singleton);
            $this->container->bind(WebAuthnChallengeStore::class, fn() => new WebAuthnChallengeStore(
                $this->container->get(TtlStoreInterface::class),
            ), LifetimeEnum::Singleton);
            $this->container->bind(WebAuthnRuntime::class, fn() => new WebAuthnRuntime(
                $this->container->get(WebAuthnConfigResolver::class)->resolve(),
            ), LifetimeEnum::Singleton);
            $this->container->bind(WebAuthnCredentialMapper::class, fn() => new WebAuthnCredentialMapper(
                $this->container->get(AuthIdGeneratorInterface::class),
                $this->container->get(ClockInterface::class),
                $this->container->get(WebAuthnRuntime::class),
            ), LifetimeEnum::Singleton);
            $this->container->bind(WebAuthnPublicKeyOptionsFactory::class, fn() => new WebAuthnPublicKeyOptionsFactory(
                $this->container->get(WebAuthnConfigResolver::class)->resolve(),
                $this->container->get(WebAuthnRuntime::class),
            ), LifetimeEnum::Singleton);
            $this->container->bind(PasskeyServiceInterface::class, fn() => new WebAuthnPasskeyService(
                config: $this->container->get(WebAuthnConfigResolver::class)->resolve(),
                challenges: $this->container->get(WebAuthnChallengeStore::class),
                credentials: $this->container->get(PasskeyCredentialStoreInterface::class),
                ids: $this->container->get(AuthIdGeneratorInterface::class),
                clock: $this->container->get(ClockInterface::class),
                options: $this->container->get(WebAuthnPublicKeyOptionsFactory::class),
                mapper: $this->container->get(WebAuthnCredentialMapper::class),
                runtime: $this->container->get(WebAuthnRuntime::class),
            ), LifetimeEnum::Singleton);

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

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnChallengeStore;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnCredentialMapper;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPasskeyService;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPublicKeyOptionsFactory;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnRuntime;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Support\DisabledPasskeyService;
use Infocyph\Foundation\Auth\Support\InMemoryPasskeyService;

final readonly class AuthPasskeyRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        $driver = $drivers->passkey();

        if ($driver === AuthPasskeyDriver::DISABLED) {
            $this->singleton(PasskeyServiceInterface::class, fn() => new DisabledPasskeyService());

            return;
        }

        if ($driver === AuthPasskeyDriver::WEBAUTHN) {
            $this->singleton(WebAuthnConfigResolver::class, fn() => new WebAuthnConfigResolver(
                $this->app->config(),
            ));
            $this->singleton(WebAuthnChallengeStore::class, fn() => new WebAuthnChallengeStore(
                $this->app->make(TtlStoreInterface::class),
            ));
            $this->singleton(WebAuthnRuntime::class, fn() => new WebAuthnRuntime(
                $this->app->make(WebAuthnConfigResolver::class)->resolve(),
            ));
            $this->singleton(WebAuthnCredentialMapper::class, fn() => new WebAuthnCredentialMapper(
                $this->app->make(AuthIdGeneratorInterface::class),
                $this->app->make(ClockInterface::class),
                $this->app->make(WebAuthnRuntime::class),
            ));
            $this->singleton(WebAuthnPublicKeyOptionsFactory::class, fn() => new WebAuthnPublicKeyOptionsFactory(
                $this->app->make(WebAuthnConfigResolver::class)->resolve(),
                $this->app->make(WebAuthnRuntime::class),
            ));
            $this->singleton(PasskeyServiceInterface::class, fn() => new WebAuthnPasskeyService(
                config: $this->app->make(WebAuthnConfigResolver::class)->resolve(),
                challenges: $this->app->make(WebAuthnChallengeStore::class),
                credentials: $this->app->make(PasskeyCredentialStoreInterface::class),
                ids: $this->app->make(AuthIdGeneratorInterface::class),
                clock: $this->app->make(ClockInterface::class),
                options: $this->app->make(WebAuthnPublicKeyOptionsFactory::class),
                mapper: $this->app->make(WebAuthnCredentialMapper::class),
                runtime: $this->app->make(WebAuthnRuntime::class),
            ));

            return;
        }

        $this->singleton(PasskeyServiceInterface::class, fn() => new InMemoryPasskeyService(
            $this->app->make(PasskeyCredentialStoreInterface::class),
            $this->app->make(ClockInterface::class),
            $this->intConfig('auth.passkey_challenge_ttl', 300),
        ));
    }
}

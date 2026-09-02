<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\NoneWebAuthnAttestationPolicy;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnAttestationPolicyInterface;
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
use Infocyph\Foundation\Config\ConfigRepository;
use Webauthn\PublicKeyCredential;

final readonly class AuthPasskeyRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        $driver = $drivers->passkey();

        if ($driver === AuthPasskeyDriver::DISABLED) {
            $this->recipe(PasskeyServiceInterface::class, DisabledPasskeyService::class);
            return;
        }

        if ($driver === AuthPasskeyDriver::WEBAUTHN) {
            $this->requirePackage(PublicKeyCredential::class, 'web-auth/webauthn-lib', 'passkeys');
            $this->recipe(WebAuthnConfigResolver::class, WebAuthnConfigResolver::class, [
                $this->ref(ConfigRepository::class),
            ]);
            $this->recipe(WebAuthnChallengeStore::class, WebAuthnChallengeStore::class, [
                $this->ref(TtlStoreInterface::class),
            ]);

            // Resolved WebAuthn library objects and host attestation policy remain adapter dynamic islands.
            $this->singleton(WebAuthnRuntime::class, fn() => new WebAuthnRuntime(
                $this->service(WebAuthnConfigResolver::class)->resolve(),
                $this->attestationPolicy(),
            ));
            $this->recipe(WebAuthnCredentialMapper::class, WebAuthnCredentialMapper::class, [
                $this->ref(AuthIdGeneratorInterface::class),
                $this->ref(ClockInterface::class),
                $this->ref(WebAuthnRuntime::class),
            ]);
            $this->singleton(WebAuthnPublicKeyOptionsFactory::class, fn() => new WebAuthnPublicKeyOptionsFactory(
                $this->service(WebAuthnConfigResolver::class)->resolve(),
                $this->service(WebAuthnRuntime::class),
            ));
            $this->singleton(PasskeyServiceInterface::class, fn() => new WebAuthnPasskeyService(
                config: $this->service(WebAuthnConfigResolver::class)->resolve(),
                challenges: $this->service(WebAuthnChallengeStore::class),
                credentials: $this->service(PasskeyCredentialStoreInterface::class),
                ids: $this->service(AuthIdGeneratorInterface::class),
                clock: $this->service(ClockInterface::class),
                options: $this->service(WebAuthnPublicKeyOptionsFactory::class),
                mapper: $this->service(WebAuthnCredentialMapper::class),
                runtime: $this->service(WebAuthnRuntime::class),
            ));
            return;
        }

        $this->recipe(PasskeyServiceInterface::class, InMemoryPasskeyService::class, [
            $this->ref(PasskeyCredentialStoreInterface::class),
            $this->ref(ClockInterface::class),
            $this->intConfig('auth.passkey_challenge_ttl', 300),
        ]);
    }

    private function attestationPolicy(): WebAuthnAttestationPolicyInterface
    {
        if ($this->hasExplicitBinding(WebAuthnAttestationPolicyInterface::class)) {
            $policy = $this->service(WebAuthnAttestationPolicyInterface::class);
            if ($policy instanceof WebAuthnAttestationPolicyInterface) {
                return $policy;
            }
        }

        return new NoneWebAuthnAttestationPolicy();
    }
}

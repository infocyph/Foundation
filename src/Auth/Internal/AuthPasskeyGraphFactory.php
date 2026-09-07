<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnAttestationPolicyInterface;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnChallengeStore;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnCredentialMapper;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPasskeyService;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnPublicKeyOptionsFactory;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnRuntime;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;

final class AuthPasskeyGraphFactory
{
    public static function options(
        WebAuthnConfigResolver $config,
        WebAuthnRuntime $runtime,
    ): WebAuthnPublicKeyOptionsFactory {
        return new WebAuthnPublicKeyOptionsFactory($config->resolve(), $runtime);
    }

    public static function runtime(
        WebAuthnConfigResolver $config,
        WebAuthnAttestationPolicyInterface $attestation,
    ): WebAuthnRuntime {
        return new WebAuthnRuntime($config->resolve(), $attestation);
    }

    public static function service(
        WebAuthnConfigResolver $config,
        WebAuthnChallengeStore $challenges,
        PasskeyCredentialStoreInterface $credentials,
        AuthIdGeneratorInterface $ids,
        ClockInterface $clock,
        WebAuthnPublicKeyOptionsFactory $options,
        WebAuthnCredentialMapper $mapper,
        WebAuthnRuntime $runtime,
    ): PasskeyServiceInterface {
        return new WebAuthnPasskeyService(
            config: $config->resolve(),
            challenges: $challenges,
            credentials: $credentials,
            ids: $ids,
            clock: $clock,
            options: $options,
            mapper: $mapper,
            runtime: $runtime,
        );
    }
}

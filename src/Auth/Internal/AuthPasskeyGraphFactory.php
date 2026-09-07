<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpPasskeyService;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\OTP\Passkey;

final class AuthPasskeyGraphFactory
{
    public static function passkey(
        WebAuthnConfigResolver $config,
        CacheLayerFactory $cache,
        ?string $storeName,
    ): Passkey {
        $resolved = $config->resolve();
        $store = $cache->make($storeName);

        if (!$store instanceof AuthenticationStateCacheInterface) {
            throw new \LogicException(
                'OTP Passkey ceremony state requires a CacheLayer AuthenticationStateCacheInterface store.',
            );
        }

        return new Passkey(
            cache: $store,
            rpId: (string) $resolved->rpId,
            allowedOrigins: [(string) $resolved->origin],
            ttlSeconds: $resolved->challengeTtl,
        );
    }

    public static function service(
        Passkey $passkey,
        PasskeyCredentialStoreInterface $credentials,
        AccountProviderInterface $accounts,
        AuthIdGeneratorInterface $ids,
        ClockInterface $clock,
    ): PasskeyServiceInterface {
        return new OtpPasskeyService(
            passkey: $passkey,
            credentials: $credentials,
            accounts: $accounts,
            ids: $ids,
            clock: $clock,
        );
    }
}

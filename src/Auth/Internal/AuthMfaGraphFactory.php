<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpChallengeFactorService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\OTP\Contracts\RecoveryCodeStoreInterface;
use Infocyph\OTP\RecoveryCodes;

final class AuthMfaGraphFactory
{
    public static function challengeFactors(
        CacheLayerFactory $cache,
        ?string $storeName,
    ): OtpChallengeFactorService {
        $store = $cache->make($storeName);
        if (!$store instanceof AuthenticationStateCacheInterface) {
            throw new \LogicException(
                'OTP authentication state requires a CacheLayer AuthenticationStateCacheInterface store.',
            );
        }

        return new OtpChallengeFactorService($store);
    }

    public static function recoveryCodes(
        RecoveryCodeStoreInterface $store,
        ConfigRepository $config,
    ): RecoveryCodes {
        return new RecoveryCodes(
            $store,
            new AuthMfaKeyResolver($config)->recoveryHmacKey(),
        );
    }

    public static function recoveryCodeStore(MfaFactorStoreInterface $factors): RecoveryCodeStoreInterface
    {
        if (!$factors instanceof MfaFactorCompareAndSwapStoreInterface) {
            throw new \LogicException(
                'OTP recovery codes require an MFA factor store with atomic compare-and-swap support.',
            );
        }

        return new OtpRecoveryCodeStore($factors);
    }

    public static function secretProtector(ConfigRepository $config): MfaSecretProtector
    {
        $keys = new AuthMfaKeyResolver($config);

        return new MfaSecretProtector(
            $keys->mfaSecretKeyRing(),
            $keys->allowLegacyPlaintext(),
        );
    }

    public static function verifier(
        MfaFactorStoreInterface $factors,
        OtpChallengeFactorService $challengeFactors,
        int $window,
        int $ocraReplayTtl,
    ): OtpMfaVerifier {
        if (!$factors instanceof MfaFactorCompareAndSwapStoreInterface) {
            throw new \LogicException(
                'OTP MFA requires an MFA factor store with atomic compare-and-swap support.',
            );
        }

        return new OtpMfaVerifier(
            factors: $factors,
            challengeFactors: $challengeFactors,
            window: $window,
            ocraReplayTtl: $ocraReplayTtl,
        );
    }
}

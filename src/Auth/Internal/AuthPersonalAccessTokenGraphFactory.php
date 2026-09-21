<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenPolicy;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenWildcardPolicy;
use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptAsymmetricSigningKeyResolver;

final class AuthPersonalAccessTokenGraphFactory
{
    public static function keys(
        EpicryptAsymmetricSigningKeyResolver $resolver,
        string $issuer,
    ): AsymmetricSigningKeySet {
        return $resolver->resolve(
            'auth.personal_access_tokens.signing',
            $issuer,
            KeyPurpose::API_PERSONAL_TOKEN_SIGNING,
        );
    }

    public static function policy(
        string $audience,
        int $defaultLifetime,
        int $maximumLifetime,
        string $wildcardPolicy,
        ?int $lastUsedInterval,
    ): PersonalAccessTokenPolicy {
        $wildcard = PersonalAccessTokenWildcardPolicy::tryFrom($wildcardPolicy)
            ?? throw new \InvalidArgumentException('Personal-access-token wildcard policy is invalid.');

        return new PersonalAccessTokenPolicy(
            audience: $audience,
            defaultLifetimeSeconds: $defaultLifetime,
            maximumLifetimeSeconds: $maximumLifetime,
            wildcardPolicy: $wildcard,
            lastUsedWriteIntervalSeconds: $lastUsedInterval,
        );
    }
}

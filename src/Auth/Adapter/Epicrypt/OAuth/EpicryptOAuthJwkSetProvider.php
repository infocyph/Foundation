<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

final readonly class EpicryptOAuthJwkSetProvider implements JwkSetProviderInterface
{
    public function __construct(private OAuthSigningKeySet $keys) {}

    public function jwks(): array
    {
        return new Jwks()->exportFromKeyRing(
            $this->keys->publicKeys,
            $this->keys->algorithm,
            $this->keys->issuer,
        );
    }
}

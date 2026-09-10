<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

final readonly class EpicryptOAuthJwkSetProvider implements JwkSetProviderInterface
{
    public function __construct(private OAuthSigningKeySet $keys) {}

    public function jwks(): array
    {
        return $this->keys->epicrypt->jwks();
    }
}

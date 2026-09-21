<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

final readonly class EpicryptOAuthJwkSetProvider implements JwkSetProviderInterface
{
    public function __construct(
        private OAuthSigningKeySet $keys,
        private ?AsymmetricSigningKeySet $openIdKeys = null,
    ) {}

    public function jwks(): array
    {
        $keys = $this->keys->epicrypt->jwks()['keys'];
        if ($this->openIdKeys instanceof AsymmetricSigningKeySet) {
            $keys = [...$keys, ...$this->openIdKeys->jwks()['keys']];
        }

        $seen = [];
        foreach ($keys as $key) {
            $id = $key['kid'] ?? null;
            if (!is_string($id) || isset($seen[$id])) {
                throw new \LogicException('Published OAuth/OIDC JWKS key ids must be unique.');
            }
            $seen[$id] = true;
        }

        return ['keys' => $keys];
    }
}

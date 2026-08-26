<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;

final readonly class OAuthSigningKeySet
{
    public function __construct(
        public string $issuer,
        public string $activeKeyId,
        #[\SensitiveParameter]
        public string $privateKey,
        public KeyRing $publicKeys,
        public AsymmetricJwtAlgorithm $algorithm,
    ) {}
}

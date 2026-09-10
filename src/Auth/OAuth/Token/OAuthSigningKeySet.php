<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;

final readonly class OAuthSigningKeySet
{
    public AsymmetricSigningKeySet $epicrypt;

    public function __construct(
        public string $issuer,
        public string $activeKeyId,
        #[\SensitiveParameter]
        public string $privateKey,
        public KeyRing $publicKeys,
        public AsymmetricJwtAlgorithm $algorithm,
    ) {
        $this->epicrypt = new AsymmetricSigningKeySet(
            issuer: $this->issuer,
            activeKeyId: $this->activeKeyId,
            privateKey: $this->privateKey,
            publicKeys: $this->publicKeys,
            algorithm: $this->algorithm,
            purpose: KeyPurpose::OAUTH_ACCESS_TOKEN_SIGNING,
        );
    }
}

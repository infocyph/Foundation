<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenRevocation;

interface OAuthAccessRevocationStoreInterface
{
    public function isRevoked(string $tokenId, int $now): bool;

    public function revoke(OAuthAccessTokenRevocation $revocation): void;
}

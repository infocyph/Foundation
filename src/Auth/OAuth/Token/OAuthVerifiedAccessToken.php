<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;

final readonly class OAuthVerifiedAccessToken
{
    public function __construct(
        public OAuthAccessTokenClaims $claims,
        public OAuthClient $client,
        public OAuthAuthorization $authorization,
        public ?AccountInterface $account,
    ) {}

    public function servicePrincipal(): bool
    {
        return $this->account === null && $this->authorization->accountId === null;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;

final readonly class OAuthAccessTokenValidator
{
    public function __construct(
        private OAuthAccessTokenServiceInterface $tokens,
        private OAuthClientManager $clients,
        private OAuthAuthorizationStoreInterface $authorizations,
        private OAuthAccessRevocationStoreInterface $revocations,
        private OAuthScopeResolver $scopes,
        private AccountProviderInterface $accounts,
        private ClockInterface $clock,
    ) {}

    public function verify(#[\SensitiveParameter] string $token, string $expectedAudience): OAuthVerifiedAccessToken
    {
        $claims = $this->tokens->verify($token, $expectedAudience);
        $now = $this->clock->now();
        if ($this->revocations->isRevoked($claims->tokenId, $now)) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        $client = $this->clients->enabled($claims->clientId);
        if (!$client instanceof OAuthClient) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }
        if ($claims->authorizationId === null) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        $authorization = $this->authorizations->find($claims->authorizationId);
        if (!$authorization instanceof OAuthAuthorization || !$authorization->activeAt($now)) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }
        if (!hash_equals($authorization->clientId, $client->clientId)) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        try {
            $this->scopes->resolve($client, $claims->scopes, $claims->audiences);
        } catch (\InvalidArgumentException) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }
        if (!$this->subsetOf($claims->scopes, $authorization->scopes)
            || !$this->subsetOf($claims->audiences, $authorization->audiences)) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        if ($authorization->accountId === null) {
            if (!hash_equals($claims->subject, 'client:' . $client->clientId)) {
                throw new OAuthTokenException('OAuth access token is inactive.');
            }

            return new OAuthVerifiedAccessToken($claims, $client, $authorization, null);
        }

        if (!hash_equals($claims->subject, $authorization->accountId)) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }
        $account = $this->accounts->findById($authorization->accountId);
        if ($account === null || $account->status() !== AccountStatus::ACTIVE) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        return new OAuthVerifiedAccessToken($claims, $client, $authorization, $account);
    }

    /**
     * @param list<string> $candidate
     * @param list<string> $allowed
     */
    private function subsetOf(array $candidate, array $allowed): bool
    {
        $allowedSet = array_fill_keys($allowed, true);

        return array_all($candidate, fn($value) => isset($allowedSet[$value]));
    }
}

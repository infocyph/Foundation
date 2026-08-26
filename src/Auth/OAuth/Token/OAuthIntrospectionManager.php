<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;

final readonly class OAuthIntrospectionManager
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthAccessTokenValidator $accessTokens,
        private OAuthRefreshTokenStoreInterface $refreshTokens,
        private OAuthAuthorizationStoreInterface $authorizations,
        private OAuthScopeResolver $scopes,
        private AccountProviderInterface $accounts,
        private ClockInterface $clock,
        private OpaqueToken $opaqueTokens,
    ) {}

    public function introspect(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
    ): OAuthIntrospectionResult {
        $client = $this->clients->authenticate(
            $authentication->clientId,
            $authentication->secret,
            null,
            $authentication->method,
        );
        if (!$client instanceof OAuthClient || !$client->confidential()) {
            throw OAuthProtocolException::invalidClient();
        }
        if ($token === '' || strlen($token) > 8192) {
            return OAuthIntrospectionResult::inactive();
        }

        $access = $this->introspectAccess($token, $client);
        if ($access->active) {
            return $access;
        }

        return $this->introspectRefresh($token, $client);
    }

    private function introspectAccess(#[\SensitiveParameter] string $token, OAuthClient $caller): OAuthIntrospectionResult
    {
        foreach ($caller->audiences as $audience) {
            try {
                $verified = $this->accessTokens->verify($token, $audience);
            } catch (\Throwable) {
                continue;
            }
            if (!hash_equals($verified->claims->clientId, $caller->clientId)) {
                return OAuthIntrospectionResult::inactive();
            }

            return new OAuthIntrospectionResult(
                active: true,
                clientId: $verified->claims->clientId,
                subject: $verified->claims->subject,
                audiences: $verified->claims->audiences,
                scopes: $verified->claims->scopes,
                expiresAt: $verified->claims->expiresAt,
                issuedAt: $verified->claims->issuedAt,
                tokenId: $verified->claims->tokenId,
                tokenType: 'Bearer',
            );
        }

        return OAuthIntrospectionResult::inactive();
    }

    private function introspectRefresh(#[\SensitiveParameter] string $token, OAuthClient $caller): OAuthIntrospectionResult
    {
        try {
            $record = $this->refreshTokens->findByHash($this->opaqueTokens->hash($token));
        } catch (\Throwable) {
            return OAuthIntrospectionResult::inactive();
        }
        $now = $this->clock->now();
        if (
            !$record instanceof OAuthRefreshTokenRecord
            || !hash_equals($record->clientId, $caller->clientId)
            || $record->rotatedAt !== null
            || $record->revokedAt !== null
            || $record->expiresAt <= $now
            || $record->accountId === null
        ) {
            return OAuthIntrospectionResult::inactive();
        }

        $authorization = $this->authorizations->find($record->authorizationId);
        if (!$authorization instanceof OAuthAuthorization
            || !$authorization->activeAt($now)
            || !hash_equals($authorization->clientId, $caller->clientId)
            || !hash_equals((string) $authorization->accountId, $record->accountId)) {
            return OAuthIntrospectionResult::inactive();
        }

        $account = $this->accounts->findById($record->accountId);
        if ($account === null || $account->status() !== AccountStatus::ACTIVE) {
            return OAuthIntrospectionResult::inactive();
        }
        try {
            $this->scopes->resolve($caller, $record->scopes, $record->audiences);
        } catch (\InvalidArgumentException) {
            return OAuthIntrospectionResult::inactive();
        }

        return new OAuthIntrospectionResult(
            active: true,
            clientId: $record->clientId,
            subject: $record->accountId,
            audiences: $record->audiences,
            scopes: $record->scopes,
            expiresAt: $record->expiresAt,
            issuedAt: $record->issuedAt,
            tokenType: 'refresh_token',
        );
    }
}

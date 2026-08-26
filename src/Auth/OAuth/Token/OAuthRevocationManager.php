<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;

final readonly class OAuthRevocationManager
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthAccessTokenServiceInterface $accessTokens,
        private OAuthAccessRevocationStoreInterface $revocations,
        private OAuthRefreshTokenCoordinator $refreshTokens,
    ) {}

    public function revoke(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $client = $this->clients->authenticate(
            $authentication->clientId,
            $authentication->secret,
            null,
            $authentication->method,
        );
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::invalidClient();
        }

        if ($token === '' || strlen($token) > 8192) {
            return;
        }

        if ($tokenTypeHint === 'refresh_token') {
            $this->refreshTokens->revoke($token, $client->clientId);
            return;
        }

        if ($this->revokeAccessToken($token, $client)) {
            return;
        }

        $this->refreshTokens->revoke($token, $client->clientId);
    }

    private function revokeAccessToken(#[\SensitiveParameter] string $token, OAuthClient $client): bool
    {
        foreach ($client->audiences as $audience) {
            try {
                $claims = $this->accessTokens->verify($token, $audience);
            } catch (\Throwable) {
                continue;
            }
            if (!hash_equals($claims->clientId, $client->clientId)) {
                return true;
            }

            $this->revocations->revoke(new OAuthAccessTokenRevocation(
                tokenId: $claims->tokenId,
                clientId: $claims->clientId,
                authorizationId: $claims->authorizationId,
                expiresAt: $claims->expiresAt,
                revokedAt: time(),
                reason: 'client_revocation',
            ));

            return true;
        }

        return false;
    }
}

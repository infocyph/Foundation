<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenRevocation;

final readonly class DBLayerOAuthAccessRevocationStore extends DBLayerStore implements OAuthAccessRevocationStoreInterface
{
    public function isRevoked(string $tokenId, int $now): bool
    {
        return $this->exists(
            sprintf(
                'SELECT token_id FROM %s WHERE token_id = ? AND expires_at > ?',
                $this->table('oauthAccessRevocations'),
            ),
            [$tokenId, $now],
        );
    }

    public function revoke(OAuthAccessTokenRevocation $revocation): void
    {
        $this->upsertRecord('oauthAccessRevocations', 'token_id', [
            'token_id' => $revocation->tokenId,
            'client_id' => $revocation->clientId,
            'authorization_id' => $revocation->authorizationId,
            'expires_at' => $revocation->expiresAt,
            'revoked_at' => $revocation->revokedAt,
            'reason' => $revocation->reason,
            'metadata' => DBLayerJson::encode($revocation->metadata),
        ]);
    }
}

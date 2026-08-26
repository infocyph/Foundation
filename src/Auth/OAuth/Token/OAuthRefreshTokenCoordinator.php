<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class OAuthRefreshTokenCoordinator
{
    public function __construct(
        private OAuthRefreshTokenStoreInterface $refreshTokens,
        private OAuthAuthorizationStoreInterface $authorizations,
        private OAuthClientManager $clients,
        private OAuthScopeResolver $scopes,
        private AccountProviderInterface $accounts,
        private ClockInterface $clock,
        private OpaqueToken $tokens,
        private int $ttlSeconds = 1209600,
        private ?OAuthAuditRecorder $audit = null,
    ) {
        if ($this->ttlSeconds < 1) {
            throw new \InvalidArgumentException('OAuth refresh-token TTL must be positive.');
        }
    }

    public function issue(OAuthAuthorization $authorization, ?string $deviceId = null): OAuthRefreshTokenIssue
    {
        $now = $this->clock->now();
        $client = $this->validClient($authorization->clientId);
        $this->assertAuthorization($authorization, $client, $now);
        if (!$client->allowsGrant(OAuthGrantType::RefreshToken)) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        $plain = $this->tokens->issue(64);
        $record = $this->newRecord(
            tokenHash: $this->tokens->hash($plain),
            familyId: bin2hex(random_bytes(16)),
            authorization: $authorization,
            deviceId: $deviceId,
            scopes: $authorization->scopes,
            audiences: $authorization->audiences,
            now: $now,
        );
        $this->refreshTokens->save($record);

        return new OAuthRefreshTokenIssue($plain, $record);
    }

    public function revoke(#[\SensitiveParameter] string $token, string $clientId): void
    {
        try {
            $record = $this->refreshTokens->findByHash($this->tokens->hash($token));
        } catch (\Throwable) {
            return;
        }
        if (!$record instanceof OAuthRefreshTokenRecord || !hash_equals($record->clientId, $clientId)) {
            return;
        }

        $this->refreshTokens->revokeFamily($record->familyId, $this->clock->now());
        $this->audit?->record(
            AuthEventType::OAUTH_REFRESH_TOKEN_REVOKED,
            $record->accountId,
            [
                'client_id' => $record->clientId,
                'authorization_id' => $record->authorizationId,
                'result' => 'revoked',
            ],
        );
    }

    /** @param list<string> $requestedScopes */
    public function rotate(
        #[\SensitiveParameter]
        string $token,
        string $clientId,
        array $requestedScopes = [],
    ): OAuthRefreshTokenIssue {
        $tokenHash = $this->hash($token);
        $current = $this->refreshTokens->findByHash($tokenHash);
        if (!$current instanceof OAuthRefreshTokenRecord || !hash_equals($current->clientId, $clientId)) {
            throw OAuthProtocolException::invalidGrant();
        }

        $now = $this->clock->now();
        if ($current->rotatedAt !== null) {
            $this->refreshTokens->revokeFamily($current->familyId, $now);
            $this->recordReuse($current);

            throw OAuthProtocolException::invalidGrant();
        }
        if ($current->revokedAt !== null || $current->expiresAt <= $now) {
            throw OAuthProtocolException::invalidGrant();
        }

        $client = $this->validClient($clientId);
        if (!$client->allowsGrant(OAuthGrantType::RefreshToken)) {
            throw OAuthProtocolException::invalidGrant();
        }
        $authorization = $this->authorizations->find($current->authorizationId);
        if (!$authorization instanceof OAuthAuthorization) {
            throw OAuthProtocolException::invalidGrant();
        }
        $this->assertAuthorization($authorization, $client, $now);

        try {
            $scopes = $this->scopes->narrow($current->scopes, $requestedScopes);
            $selection = $this->scopes->resolve($client, $scopes, $current->audiences);
        } catch (\InvalidArgumentException) {
            throw OAuthProtocolException::invalidGrant();
        }

        $plain = $this->tokens->issue(64);
        $replacement = $this->newRecord(
            tokenHash: $this->tokens->hash($plain),
            familyId: $current->familyId,
            authorization: $authorization,
            deviceId: $current->deviceId,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
            now: $now,
        );
        $result = $this->refreshTokens->rotate($tokenHash, $replacement, $now);
        if ($result->status === OAuthRefreshRotationStatus::Reused) {
            $this->refreshTokens->revokeFamily($current->familyId, $now);
            $this->recordReuse($current);

            throw OAuthProtocolException::invalidGrant();
        }
        if (!$result->succeeded()) {
            throw OAuthProtocolException::invalidGrant();
        }

        $this->audit?->record(
            AuthEventType::OAUTH_REFRESH_TOKEN_ROTATED,
            $current->accountId,
            [
                'client_id' => $current->clientId,
                'authorization_id' => $current->authorizationId,
                'result' => $result->status->value,
                'scopes' => $replacement->scopes,
                'audiences' => $replacement->audiences,
            ],
        );

        return new OAuthRefreshTokenIssue($plain, $replacement);
    }

    private function assertAuthorization(OAuthAuthorization $authorization, OAuthClient $client, int $now): void
    {
        if (!$authorization->activeAt($now) || !hash_equals($authorization->clientId, $client->clientId)) {
            throw OAuthProtocolException::invalidGrant();
        }
        if ($authorization->accountId === null) {
            throw OAuthProtocolException::invalidGrant();
        }

        $account = $this->accounts->findById($authorization->accountId);
        if ($account === null || $account->status() !== AccountStatus::ACTIVE) {
            throw OAuthProtocolException::invalidGrant();
        }
    }

    private function hash(#[\SensitiveParameter] string $token): string
    {
        try {
            return $this->tokens->hash($token);
        } catch (\Throwable) {
            throw OAuthProtocolException::invalidGrant();
        }
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     */
    private function newRecord(
        string $tokenHash,
        string $familyId,
        OAuthAuthorization $authorization,
        ?string $deviceId,
        array $scopes,
        array $audiences,
        int $now,
    ): OAuthRefreshTokenRecord {
        return new OAuthRefreshTokenRecord(
            id: bin2hex(random_bytes(16)),
            tokenHash: $tokenHash,
            familyId: $familyId,
            clientId: $authorization->clientId,
            accountId: $authorization->accountId,
            deviceId: $deviceId,
            authorizationId: $authorization->id,
            scopes: $scopes,
            audiences: $audiences,
            issuedAt: $now,
            expiresAt: $now + $this->ttlSeconds,
        );
    }

    private function recordReuse(OAuthRefreshTokenRecord $record): void
    {
        $this->audit?->record(
            AuthEventType::OAUTH_REFRESH_TOKEN_REUSE,
            $record->accountId,
            [
                'client_id' => $record->clientId,
                'authorization_id' => $record->authorizationId,
                'result' => 'reused',
            ],
            AuthEventSeverity::WARNING,
        );
    }

    private function validClient(string $clientId): OAuthClient
    {
        $client = $this->clients->enabled($clientId);
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::invalidGrant();
        }

        return $client;
    }
}

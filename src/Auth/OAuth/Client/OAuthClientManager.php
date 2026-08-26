<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Client;

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class OAuthClientManager
{
    private const int MAX_AUDIENCES = 16;

    private const int MAX_REDIRECT_URIS = 20;

    private const int MAX_SCOPES = 64;

    public function __construct(
        private OAuthClientStoreInterface $clients,
        private PasswordHasherInterface $hasher,
        private PasswordVerifierInterface $verifier,
        private ClockInterface $clock,
        private OpaqueToken $tokens,
        private bool $production,
    ) {}

    public function authenticate(
        string $clientId,
        #[\SensitiveParameter]
        ?string $secret,
        ?OAuthGrantType $grant,
        OAuthClientAuthenticationMethod $method,
    ): ?OAuthClient {
        $client = $this->enabled($clientId);
        if (!$client instanceof OAuthClient || ($grant !== null && !$client->allowsGrant($grant))) {
            return null;
        }

        if ($client->public()) {
            return $method === OAuthClientAuthenticationMethod::None && ($secret === null || $secret === '')
                ? $client
                : null;
        }

        return $this->verifyConfidentialClient($client, $secret, $method);
    }

    public function enabled(string $clientId): ?OAuthClient
    {
        $client = $this->clients->find($clientId);

        return $client instanceof OAuthClient && $client->enabled && $client->disabledAt === null
            ? $client
            : null;
    }

    public function redirectUriAllowed(string $clientId, string $redirectUri): bool
    {
        return in_array($redirectUri, $this->clients->redirectUris($clientId), true);
    }

    /** @return list<string> */
    public function redirectUris(string $clientId): array
    {
        return $this->clients->redirectUris($clientId);
    }

    /**
     * @param list<OAuthGrantType> $grants
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function register(
        OAuthClientType $type,
        array $grants,
        array $redirectUris,
        array $scopes,
        array $audiences,
        array $metadata = [],
    ): OAuthClientRegistration {
        $grants = $this->validateGrants($type, $grants);
        $redirectUris = $this->validateRedirectUris($redirectUris, $metadata);
        $scopes = $this->validateScopes($scopes);
        $audiences = $this->validateAudiences($audiences);
        $this->validateMetadata($metadata);

        if (in_array(OAuthGrantType::AuthorizationCode, $grants, true) && $redirectUris === []) {
            throw new \InvalidArgumentException('Authorization-code clients require at least one redirect URI.');
        }

        $now = $this->clock->now();
        $clientId = 'oc_' . $this->tokens->issue(48);
        $secret = $type === OAuthClientType::Confidential ? $this->tokens->issue(64) : null;
        $client = new OAuthClient(
            id: bin2hex(random_bytes(16)),
            clientId: $clientId,
            type: $type,
            authenticationMethod: $type === OAuthClientType::Confidential
                ? OAuthClientAuthenticationMethod::ClientSecretBasic
                : OAuthClientAuthenticationMethod::None,
            secretHash: $secret === null ? null : $this->hasher->hash($secret, ['purpose' => 'oauth_client_secret']),
            grants: $grants,
            audiences: $audiences,
            enabled: true,
            createdAt: $now,
            updatedAt: $now,
            metadata: $metadata,
        );

        $this->clients->register($client, $redirectUris, $scopes);

        return new OAuthClientRegistration($client, $secret);
    }

    public function rotateSecret(string $clientId): string
    {
        $client = $this->enabled($clientId);
        if (!$client instanceof OAuthClient || !$client->confidential()) {
            throw new \InvalidArgumentException('OAuth client does not support a client secret.');
        }

        $secret = $this->tokens->issue(64);
        $this->clients->save($this->withSecretHash(
            $client,
            $this->hasher->hash($secret, ['purpose' => 'oauth_client_secret']),
        ));

        return $secret;
    }

    /** @return list<string> */
    public function scopes(string $clientId): array
    {
        return $this->clients->scopes($clientId);
    }

    public function setEnabled(string $clientId, bool $enabled): bool
    {
        $client = $this->clients->find($clientId);
        if (!$client instanceof OAuthClient) {
            return false;
        }

        $now = $this->clock->now();
        $this->clients->save(new OAuthClient(
            id: $client->id,
            clientId: $client->clientId,
            type: $client->type,
            authenticationMethod: $client->authenticationMethod,
            secretHash: $client->secretHash,
            grants: $client->grants,
            audiences: $client->audiences,
            enabled: $enabled,
            createdAt: $client->createdAt,
            updatedAt: $now,
            disabledAt: $enabled ? null : $now,
            metadata: $client->metadata,
        ));

        return true;
    }

    /** @param list<string> $audiences @return list<string> */
    private function validateAudiences(array $audiences): array
    {
        if ($audiences === [] || count($audiences) > self::MAX_AUDIENCES || !array_is_list($audiences)) {
            throw new \InvalidArgumentException('OAuth clients require a bounded audience list.');
        }

        $normalized = [];
        foreach ($audiences as $audience) {
            if (!is_string($audience) || $audience === '' || strlen($audience) > 2048 || isset($normalized[$audience])) {
                throw new \InvalidArgumentException('OAuth client audience policy is invalid.');
            }
            $normalized[$audience] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<OAuthGrantType> $grants @return list<OAuthGrantType> */
    private function validateGrants(OAuthClientType $type, array $grants): array
    {
        if ($grants === [] || !array_is_list($grants)) {
            throw new \InvalidArgumentException('OAuth clients require at least one grant.');
        }

        $seen = [];
        foreach ($grants as $grant) {
            if (!$grant instanceof OAuthGrantType || isset($seen[$grant->value])) {
                throw new \InvalidArgumentException('OAuth client grant policy is invalid.');
            }
            $seen[$grant->value] = true;
        }

        if ($type === OAuthClientType::Public && isset($seen[OAuthGrantType::ClientCredentials->value])) {
            throw new \InvalidArgumentException('Public OAuth clients cannot use client credentials.');
        }
        if (isset($seen[OAuthGrantType::RefreshToken->value]) && !isset($seen[OAuthGrantType::AuthorizationCode->value])) {
            throw new \InvalidArgumentException('Refresh-token clients must also allow authorization code.');
        }

        return $grants;
    }

    /** @param array<string, mixed> $metadata */
    private function validateMetadata(array $metadata): void
    {
        if (count($metadata) > 32) {
            throw new \InvalidArgumentException('OAuth client metadata exceeds the supported field count.');
        }

        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 8192) {
            throw new \InvalidArgumentException('OAuth client metadata exceeds the supported size.');
        }
    }

    /** @param list<string> $redirectUris @param array<string, mixed> $metadata @return list<string> */
    private function validateRedirectUris(array $redirectUris, array $metadata): array
    {
        if (count($redirectUris) > self::MAX_REDIRECT_URIS || !array_is_list($redirectUris)) {
            throw new \InvalidArgumentException('OAuth redirect URI policy exceeds the supported limit.');
        }

        $normalized = [];
        foreach ($redirectUris as $uri) {
            if (!$this->validRedirectUri($uri, $metadata) || isset($normalized[$uri])) {
                throw new \InvalidArgumentException('OAuth redirect URI policy is invalid.');
            }
            $normalized[$uri] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<string> $scopes @return list<string> */
    private function validateScopes(array $scopes): array
    {
        if (count($scopes) > self::MAX_SCOPES || !array_is_list($scopes)) {
            throw new \InvalidArgumentException('OAuth scope policy exceeds the supported limit.');
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{0,190}\z/D', $scope) !== 1 || isset($normalized[$scope])) {
                throw new \InvalidArgumentException('OAuth scope policy is invalid.');
            }
            $normalized[$scope] = true;
        }

        return array_keys($normalized);
    }

    /** @param array<string, mixed> $metadata */
    private function validRedirectUri(mixed $uri, array $metadata): bool
    {
        if (!is_string($uri) || $uri === '' || strlen($uri) > 2048 || str_contains($uri, '*')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['fragment'], $parts['user'], $parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return false;
        }
        if (!$this->production) {
            return in_array($scheme, ['http', 'https'], true);
        }
        if ($scheme === 'https') {
            return true;
        }

        return ($metadata['native_client'] ?? false) === true
            && $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function verifyConfidentialClient(
        OAuthClient $client,
        #[\SensitiveParameter]
        ?string $secret,
        OAuthClientAuthenticationMethod $method,
    ): ?OAuthClient {
        if ($method !== OAuthClientAuthenticationMethod::ClientSecretBasic || !is_string($secret) || $secret === '' || $client->secretHash === null) {
            return null;
        }

        $result = $this->verifier->verify($secret, $client->secretHash);
        if (!$result->verified) {
            return null;
        }
        if ($result->needsRehash && is_string($result->rehash) && $result->rehash !== '') {
            $this->clients->save($this->withSecretHash($client, $result->rehash));
        }

        return $client;
    }

    private function withSecretHash(OAuthClient $client, string $hash): OAuthClient
    {
        return new OAuthClient(
            id: $client->id,
            clientId: $client->clientId,
            type: $client->type,
            authenticationMethod: $client->authenticationMethod,
            secretHash: $hash,
            grants: $client->grants,
            audiences: $client->audiences,
            enabled: $client->enabled,
            createdAt: $client->createdAt,
            updatedAt: $this->clock->now(),
            disabledAt: $client->disabledAt,
            metadata: $client->metadata,
        );
    }
}

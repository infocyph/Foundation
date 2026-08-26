<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class DBLayerOAuthClientStore extends DBLayerStore implements OAuthClientStoreInterface
{
    public function find(string $clientId): ?OAuthClient
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE client_id = ?', $this->table('oauthClients')),
            $this->mapClient(...),
            [$clientId],
        );
    }

    public function redirectUris(string $clientId): array
    {
        $rows = $this->all(
            sprintf('SELECT redirect_uri FROM %s WHERE client_id = ? ORDER BY id ASC', $this->table('oauthRedirectUris')),
            [$clientId],
        );

        return array_values(array_filter(array_map(
            fn(array $row): ?string => $this->stringOrNull($row['redirect_uri'] ?? null),
            $rows,
        )));
    }

    public function register(OAuthClient $client, array $redirectUris, array $scopes): void
    {
        $this->connection()->transaction(function (Connection $connection) use ($client, $redirectUris, $scopes): void {
            $connection->table($this->table('oauthClients'))->insert($this->clientRecord($client));
            $this->insertRedirectUris($connection, $client->clientId, $redirectUris, $client->createdAt);
            $this->insertScopes($connection, $client->clientId, $scopes, $client->createdAt);
        });
    }

    public function replaceRedirectUris(string $clientId, array $redirectUris, int $createdAt): void
    {
        $this->connection()->transaction(function (Connection $connection) use ($clientId, $redirectUris, $createdAt): void {
            $connection->table($this->table('oauthRedirectUris'))->where('client_id', '=', $clientId)->delete();
            $this->insertRedirectUris($connection, $clientId, $redirectUris, $createdAt);
        });
    }

    public function replaceScopes(string $clientId, array $scopes, int $createdAt): void
    {
        $this->connection()->transaction(function (Connection $connection) use ($clientId, $scopes, $createdAt): void {
            $connection->table($this->table('oauthClientScopes'))->where('client_id', '=', $clientId)->delete();
            $this->insertScopes($connection, $clientId, $scopes, $createdAt);
        });
    }

    public function save(OAuthClient $client): void
    {
        $this->upsertRecord('oauthClients', 'client_id', $this->clientRecord($client));
    }

    public function scopes(string $clientId): array
    {
        $rows = $this->all(
            sprintf('SELECT scope FROM %s WHERE client_id = ? ORDER BY scope ASC', $this->table('oauthClientScopes')),
            [$clientId],
        );

        return array_values(array_filter(array_map(
            fn(array $row): ?string => $this->stringOrNull($row['scope'] ?? null),
            $rows,
        )));
    }

    /** @return array<string, mixed> */
    private function clientRecord(OAuthClient $client): array
    {
        return [
            'id' => $client->id,
            'client_id' => $client->clientId,
            'client_type' => $client->type->value,
            'auth_method' => $client->authenticationMethod->value,
            'secret_hash' => $client->secretHash,
            'grants' => DBLayerJson::encodeList(array_map(
                static fn(OAuthGrantType $grant): string => $grant->value,
                $client->grants,
            )),
            'audiences' => DBLayerJson::encodeList($client->audiences),
            'enabled' => $client->enabled,
            'created_at' => $client->createdAt,
            'updated_at' => $client->updatedAt,
            'disabled_at' => $client->disabledAt,
            'metadata' => DBLayerJson::encode($client->metadata),
        ];
    }

    /** @param list<string> $redirectUris */
    private function insertRedirectUris(Connection $connection, string $clientId, array $redirectUris, int $createdAt): void
    {
        foreach ($redirectUris as $uri) {
            $connection->table($this->table('oauthRedirectUris'))->insert([
                'id' => hash('sha256', $clientId . "\0" . $uri),
                'client_id' => $clientId,
                'redirect_uri_hash' => hash('sha256', $uri),
                'redirect_uri' => $uri,
                'created_at' => $createdAt,
            ]);
        }
    }

    /** @param list<string> $scopes */
    private function insertScopes(Connection $connection, string $clientId, array $scopes, int $createdAt): void
    {
        foreach ($scopes as $scope) {
            $connection->table($this->table('oauthClientScopes'))->insert([
                'client_id' => $clientId,
                'scope' => $scope,
                'created_at' => $createdAt,
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapClient(array $row): OAuthClient
    {
        $type = OAuthClientType::tryFrom($this->string($row['client_type'] ?? ''));
        $method = OAuthClientAuthenticationMethod::tryFrom($this->string($row['auth_method'] ?? ''));
        if (!$type instanceof OAuthClientType || !$method instanceof OAuthClientAuthenticationMethod) {
            throw new \RuntimeException('Stored OAuth client policy is invalid.');
        }

        $grants = [];
        foreach (DBLayerJson::decodeStringList($row['grants'] ?? null) as $value) {
            $grant = OAuthGrantType::tryFrom($value);
            if (!$grant instanceof OAuthGrantType) {
                throw new \RuntimeException('Stored OAuth client grant policy is invalid.');
            }
            $grants[] = $grant;
        }

        return new OAuthClient(
            id: $this->string($row['id'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            type: $type,
            authenticationMethod: $method,
            secretHash: $this->stringOrNull($row['secret_hash'] ?? null),
            grants: $grants,
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            enabled: $this->truthy($row['enabled'] ?? false),
            createdAt: $this->int($row['created_at'] ?? 0),
            updatedAt: $this->int($row['updated_at'] ?? 0),
            disabledAt: $this->intOrNull($row['disabled_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}

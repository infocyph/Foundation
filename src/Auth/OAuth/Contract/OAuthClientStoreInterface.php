<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;

interface OAuthClientStoreInterface
{
    public function find(string $clientId): ?OAuthClient;

    /** @return list<OAuthClient> */
    public function list(int $limit = 100): array;

    /** @return list<string> */
    public function redirectUris(string $clientId): array;

    /** @param list<string> $redirectUris @param list<string> $scopes */
    public function register(OAuthClient $client, array $redirectUris, array $scopes): void;

    /** @param list<string> $redirectUris */
    public function replaceRedirectUris(string $clientId, array $redirectUris, int $createdAt): void;

    /** @param list<string> $scopes */
    public function replaceScopes(string $clientId, array $scopes, int $createdAt): void;

    public function save(OAuthClient $client): void;

    /** @return list<string> */
    public function scopes(string $clientId): array;
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Auth\OAuth\OAuthClient as EpicryptOAuthClient;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationMethod as EpicryptOAuthClientAuthenticationMethod;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientSecret;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientStoreInterface as EpicryptOAuthClientStoreInterface;
use Infocyph\Epicrypt\Auth\OAuth\OAuthClientType as EpicryptOAuthClientType;
use Infocyph\Epicrypt\Auth\OAuth\OAuthGrantType as EpicryptOAuthGrantType;
use Infocyph\Epicrypt\Exception\ConfigurationException;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;

/**
 * Read-only protocol projection of Foundation client state for Epicrypt authorization validation.
 *
 * Client credentials remain Foundation-owned here; this adapter never authenticates them.
 */
final readonly class EpicryptOAuthAuthorizationClientStore implements EpicryptOAuthClientStoreInterface
{
    public function __construct(
        private OAuthClientManager $clients,
    ) {}

    public function find(string $clientId): ?EpicryptOAuthClient
    {
        $client = $this->clients->enabled($clientId);
        if (!$client instanceof OAuthClient) {
            return null;
        }

        try {
            return new EpicryptOAuthClient(
                clientId: $client->clientId,
                type: EpicryptOAuthClientType::from($client->type->value),
                enabled: true,
                redirectUris: $this->clients->redirectUris($client->clientId),
                grantTypes: array_map(
                    static fn($grant): EpicryptOAuthGrantType => EpicryptOAuthGrantType::from($grant->value),
                    $client->grants,
                ),
                scopes: $this->clients->scopes($client->clientId),
                audiences: $client->audiences,
                authenticationMethods: [EpicryptOAuthClientAuthenticationMethod::from($client->authenticationMethod->value)],
                secret: $client->secretHash === null ? null : OAuthClientSecret::fromHash($client->secretHash),
            );
        } catch (ConfigurationException|\ValueError) {
            return null;
        }
    }
}

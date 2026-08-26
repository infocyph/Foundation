<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class AuthorizationRequestValidator
{
    public function __construct(
        private OAuthClientManager $clients,
        private OAuthScopeResolver $scopes,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function redirectContext(array $parameters): AuthorizationRedirectContext
    {
        $clientId = $this->requiredString($parameters, 'client_id', 128, false);
        $client = $this->clients->enabled($clientId);
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        $redirectUri = $this->requiredString($parameters, 'redirect_uri', 2048, false);
        if (!$this->clients->redirectUriAllowed($clientId, $redirectUri)) {
            throw OAuthProtocolException::invalidRequest('The redirect URI is invalid.');
        }

        return new AuthorizationRedirectContext(
            client: $client,
            redirectUri: $redirectUri,
            state: $this->safeState($parameters['state'] ?? null),
        );
    }

    /** @param array<string, mixed> $parameters */
    public function validate(array $parameters): AuthorizationRequest
    {
        $redirect = $this->redirectContext($parameters);
        $client = $redirect->client;

        if (!$client->allowsGrant(OAuthGrantType::AuthorizationCode)) {
            throw OAuthProtocolException::unauthorizedClient(true);
        }
        if ($this->requiredString($parameters, 'response_type', 32, redirectAllowed: true) !== 'code') {
            throw OAuthProtocolException::unsupportedResponseType(true);
        }

        $challenge = $this->requiredString($parameters, 'code_challenge', 128, redirectAllowed: true);
        $method = $this->requiredString($parameters, 'code_challenge_method', 16, redirectAllowed: true);
        if ($method !== 'S256' || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $challenge) !== 1) {
            throw OAuthProtocolException::invalidRequest('PKCE S256 is required.', true);
        }

        try {
            $selection = $this->scopes->resolve(
                $client,
                $this->spaceList($parameters, 'scope', 64, true),
                $this->requestedAudiences($parameters, $client),
            );
        } catch (\InvalidArgumentException) {
            throw OAuthProtocolException::invalidScope(true);
        }

        return new AuthorizationRequest(
            client: $client,
            redirectUri: $redirect->redirectUri,
            codeChallenge: $challenge,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
            requiredPermissions: $selection->permissions,
            state: $this->optionalState($parameters),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function optionalState(array $parameters): ?string
    {
        if (!array_key_exists('state', $parameters)) {
            return null;
        }

        $state = $this->safeState($parameters['state']);
        if ($state === null) {
            throw OAuthProtocolException::invalidRequest('The state parameter is invalid.', true);
        }

        return $state;
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function requestedAudiences(array $parameters, OAuthClient $client): array
    {
        if (!array_key_exists('audience', $parameters)) {
            if (count($client->audiences) === 1) {
                return $client->audiences;
            }

            throw OAuthProtocolException::invalidRequest('An audience is required.', true);
        }

        return $this->spaceList($parameters, 'audience', 16, true);
    }

    /** @param array<string, mixed> $parameters */
    private function requiredString(
        array $parameters,
        string $name,
        int $maximumBytes,
        bool $trim = true,
        bool $redirectAllowed = false,
    ): string {
        $value = $parameters[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes) {
            throw OAuthProtocolException::invalidRequest(redirectAllowed: $redirectAllowed);
        }

        if (!$trim) {
            if (trim($value) !== $value) {
                throw OAuthProtocolException::invalidRequest(redirectAllowed: $redirectAllowed);
            }

            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            throw OAuthProtocolException::invalidRequest(redirectAllowed: $redirectAllowed);
        }

        return $value;
    }

    private function safeState(mixed $state): ?string
    {
        if (!is_string($state) || $state === '' || strlen($state) > 512 || preg_match('/[\x00-\x1F\x7F]/', $state) === 1) {
            return null;
        }

        return $state;
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function spaceList(array $parameters, string $name, int $maximumItems, bool $required): array
    {
        $value = $parameters[$name] ?? null;
        if ($value === null && !$required) {
            return [];
        }
        if (!is_string($value) || $value === '' || strlen($value) > 4096) {
            throw new \InvalidArgumentException(sprintf('OAuth %s parameter is invalid.', $name));
        }

        $items = preg_split('/\x20+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($items) || $items === [] || count($items) > $maximumItems) {
            throw new \InvalidArgumentException(sprintf('OAuth %s parameter is invalid.', $name));
        }

        return array_values($items);
    }
}

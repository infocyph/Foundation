<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class OAuthTokenManager
{
    public function __construct(
        private OAuthClientManager $clients,
        private AuthorizationCodeManager $codes,
        private OAuthAuthorizationStoreInterface $authorizations,
        private OAuthScopeResolver $scopes,
        private OAuthAccessTokenServiceInterface $accessTokens,
        private OAuthRefreshTokenCoordinator $refreshTokens,
        private AccountProviderInterface $accounts,
        private ClockInterface $clock,
        private OAuthSigningKeySet $keys,
        private int $accessTokenTtl = 300,
    ) {
        if ($this->accessTokenTtl < 1) {
            throw new \InvalidArgumentException('OAuth access-token TTL must be positive.');
        }
    }

    /** @param array<string, mixed> $parameters */
    public function exchange(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        $this->rejectCredentialParameters($parameters);
        $grant = OAuthGrantType::tryFrom($this->requiredString($parameters, 'grant_type', 64));
        if (!$grant instanceof OAuthGrantType) {
            throw OAuthProtocolException::unsupportedGrantType();
        }

        return match ($grant) {
            OAuthGrantType::AuthorizationCode => $this->authorizationCode($parameters, $authentication),
            OAuthGrantType::ClientCredentials => $this->clientCredentials($parameters, $authentication),
            OAuthGrantType::RefreshToken => $this->refresh($parameters, $authentication),
        };
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     */
    private function assertUserAuthorization(
        OAuthAuthorization $authorization,
        OAuthClient $client,
        string $accountId,
        array $scopes,
        array $audiences,
        bool $allowNarrowedScopes,
    ): void {
        $now = $this->clock->now();
        $validScopes = $allowNarrowedScopes
            ? $this->subsetOf($scopes, $authorization->scopes)
            : $this->sameSet($authorization->scopes, $scopes);
        if (
            !$authorization->activeAt($now)
            || !hash_equals($authorization->clientId, $client->clientId)
            || !is_string($authorization->accountId)
            || !hash_equals($authorization->accountId, $accountId)
            || !$validScopes
            || !$this->sameSet($authorization->audiences, $audiences)
        ) {
            throw OAuthProtocolException::invalidGrant();
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null || $account->status() !== AccountStatus::ACTIVE) {
            throw OAuthProtocolException::invalidGrant();
        }
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function audiences(array $parameters, OAuthClient $client): array
    {
        if (!array_key_exists('audience', $parameters)) {
            return count($client->audiences) === 1
                ? $client->audiences
                : throw OAuthProtocolException::invalidRequest('An audience is required.');
        }

        return $this->spaceList($parameters, 'audience', 16, true);
    }

    private function authenticate(OAuthClientAuthentication $authentication, OAuthGrantType $grant): OAuthClient
    {
        $client = $this->clients->authenticate(
            $authentication->clientId,
            $authentication->secret,
            $grant,
            $authentication->method,
        );
        if (!$client instanceof OAuthClient) {
            throw OAuthProtocolException::invalidClient();
        }

        return $client;
    }

    /** @param array<string, mixed> $parameters */
    private function authorizationCode(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        $client = $this->authenticate($authentication, OAuthGrantType::AuthorizationCode);
        $this->rejectParameters($parameters, ['scope', 'audience']);
        $code = $this->codes->consume(
            code: $this->requiredString($parameters, 'code', 128, false),
            clientId: $client->clientId,
            redirectUri: $this->requiredString($parameters, 'redirect_uri', 2048, false),
            codeVerifier: $this->requiredString($parameters, 'code_verifier', 128, false),
        );
        $authorization = $this->authorizations->find($code->authorizationId);
        if (!$authorization instanceof OAuthAuthorization) {
            throw OAuthProtocolException::invalidGrant();
        }
        $this->assertUserAuthorization(
            $authorization,
            $client,
            $code->accountId,
            $code->scopes,
            $code->audiences,
            false,
        );

        $refresh = $client->allowsGrant(OAuthGrantType::RefreshToken)
            ? $this->refreshTokens->issue($authorization)
            : null;

        return $this->response(
            authorization: $authorization,
            subject: $code->accountId,
            scopes: $code->scopes,
            audiences: $code->audiences,
            refreshToken: $refresh?->token,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function clientCredentials(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        $client = $this->authenticate($authentication, OAuthGrantType::ClientCredentials);
        if (!$client->confidential()) {
            throw OAuthProtocolException::unauthorizedClient();
        }

        try {
            $selection = $this->scopes->resolve(
                $client,
                $this->spaceList($parameters, 'scope', 64, true),
                $this->audiences($parameters, $client),
            );
        } catch (\InvalidArgumentException) {
            throw OAuthProtocolException::invalidScope();
        }

        $authorization = new OAuthAuthorization(
            id: bin2hex(random_bytes(16)),
            clientId: $client->clientId,
            accountId: null,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
            createdAt: $this->clock->now(),
        );
        $this->authorizations->save($authorization);

        return $this->response(
            authorization: $authorization,
            subject: 'client:' . $client->clientId,
            scopes: $selection->scopes,
            audiences: $selection->audiences,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function refresh(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        $client = $this->authenticate($authentication, OAuthGrantType::RefreshToken);
        $this->rejectParameters($parameters, ['audience']);
        $issue = $this->refreshTokens->rotate(
            token: $this->requiredString($parameters, 'refresh_token', 128, false),
            clientId: $client->clientId,
            requestedScopes: $this->spaceList($parameters, 'scope', 64, false),
        );
        $record = $issue->record;
        $authorization = $this->authorizations->find($record->authorizationId);
        if (!$authorization instanceof OAuthAuthorization || $record->accountId === null) {
            throw OAuthProtocolException::invalidGrant();
        }
        $this->assertUserAuthorization(
            $authorization,
            $client,
            $record->accountId,
            $record->scopes,
            $record->audiences,
            true,
        );

        return $this->response(
            authorization: $authorization,
            subject: $record->accountId,
            scopes: $record->scopes,
            audiences: $record->audiences,
            refreshToken: $issue->token,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function rejectCredentialParameters(array $parameters): void
    {
        foreach (['client_secret', 'client_assertion', 'client_assertion_type'] as $name) {
            if (array_key_exists($name, $parameters)) {
                throw OAuthProtocolException::invalidRequest('Client credentials must use the configured authentication method.');
            }
        }
    }

    /** @param array<string, mixed> $parameters @param list<string> $names */
    private function rejectParameters(array $parameters, array $names): void
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $parameters)) {
                throw OAuthProtocolException::invalidRequest();
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function requiredString(array $parameters, string $name, int $maximumBytes, bool $trim = true): string
    {
        $value = $parameters[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes) {
            throw OAuthProtocolException::invalidRequest();
        }

        if (!$trim) {
            if (trim($value) !== $value) {
                throw OAuthProtocolException::invalidRequest();
            }

            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            throw OAuthProtocolException::invalidRequest();
        }

        return $value;
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     */
    private function response(
        OAuthAuthorization $authorization,
        string $subject,
        array $scopes,
        array $audiences,
        #[\SensitiveParameter]
        ?string $refreshToken = null,
    ): OAuthTokenResponse {
        $now = $this->clock->now();
        $token = $this->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $this->keys->issuer,
            subject: $subject,
            audiences: $audiences,
            expiresAt: $now + $this->accessTokenTtl,
            issuedAt: $now,
            tokenId: rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
            clientId: $authorization->clientId,
            scopes: $scopes,
            authorizationId: $authorization->id,
        ));

        return new OAuthTokenResponse(
            accessToken: $token,
            expiresIn: $this->accessTokenTtl,
            scopes: $scopes,
            refreshToken: $refreshToken,
        );
    }

    /** @param list<string> $left @param list<string> $right */
    private function sameSet(array $left, array $right): bool
    {
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);

        return $left === $right;
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function spaceList(array $parameters, string $name, int $maximumItems, bool $required): array
    {
        if (!array_key_exists($name, $parameters)) {
            return $required ? throw OAuthProtocolException::invalidRequest() : [];
        }
        $value = $parameters[$name];
        if (!is_string($value) || $value === '' || strlen($value) > 4096) {
            throw OAuthProtocolException::invalidRequest();
        }

        $items = preg_split('/\x20+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($items) || $items === [] || count($items) > $maximumItems) {
            throw OAuthProtocolException::invalidRequest();
        }

        return array_values($items);
    }

    /** @param list<string> $candidate @param list<string> $allowed */
    private function subsetOf(array $candidate, array $allowed): bool
    {
        $allowedSet = array_fill_keys($allowed, true);

        return array_all($candidate, fn($value) => isset($allowedSet[$value]));
    }
}

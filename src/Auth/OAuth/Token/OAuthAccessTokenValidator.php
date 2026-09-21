<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRecord;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationStoreInterface as EpicryptAuthorizationStore;
use Infocyph\Epicrypt\Auth\OAuth\OAuthResourceAccessTokenValidator;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;

final readonly class OAuthAccessTokenValidator
{
    public function __construct(
        private OAuthResourceAccessTokenValidator $validator,
        private OAuthClientManager $clients,
        private EpicryptAuthorizationStore $authorizations,
        private AccountProviderInterface $accounts,
    ) {}

    public function verify(#[\SensitiveParameter] string $token, string $expectedAudience): OAuthVerifiedAccessToken
    {
        return $this->verifyResource($token, $expectedAudience, 'GET', $expectedAudience);
    }

    public function verifyResource(
        #[\SensitiveParameter]
        string $token,
        string $expectedAudience,
        string $method,
        string $uri,
        #[\SensitiveParameter]
        ?string $dpopProof = null,
    ): OAuthVerifiedAccessToken {
        $result = $this->validator->validate(
            accessToken: $token,
            audience: $expectedAudience,
            method: $method,
            uri: $uri,
            dpopProof: $dpopProof,
        );
        if (!$result->valid()) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        $claims = $result->accessToken->claims;
        $clientId = self::requiredString($claims, 'client_id');
        $client = $this->clients->enabled($clientId);
        if (!$client instanceof OAuthClient) {
            throw new OAuthTokenException('OAuth access token is inactive.');
        }

        $mapped = new OAuthAccessTokenClaims(
            issuer: self::requiredString($claims, 'iss'),
            subject: self::requiredString($claims, 'sub'),
            audiences: self::audiences($claims['aud'] ?? null),
            expiresAt: self::requiredInt($claims, 'exp'),
            issuedAt: self::requiredInt($claims, 'iat'),
            tokenId: self::requiredString($claims, 'jti'),
            clientId: $clientId,
            scopes: self::scopes($claims['scope'] ?? null),
            authorizationId: self::optionalString($claims['authorization_id'] ?? null),
        );

        $authorization = $this->authorization($mapped);
        $account = null;
        if ($authorization->accountId !== null) {
            $account = $this->accounts->findById($authorization->accountId);
            if ($account === null || $account->status() !== AccountStatus::ACTIVE) {
                throw new OAuthTokenException('OAuth access token is inactive.');
            }
        }

        return new OAuthVerifiedAccessToken($mapped, $client, $authorization, $account);
    }

    /** @return list<string> */
    private static function audiences(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value) || $value === [] || array_any($value, static fn(mixed $item): bool => !is_string($item))) {
            throw new OAuthTokenException('OAuth access token audience claim is invalid.');
        }

        return array_values($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $claims */
    private static function requiredInt(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;

        return is_int($value)
            ? $value
            : throw new OAuthTokenException('OAuth access token state is invalid.');
    }

    /** @param array<string,mixed> $claims */
    private static function requiredString(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new OAuthTokenException('OAuth access token state is invalid.');
    }

    /** @return list<string> */
    private static function scopes(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            return explode(' ', $value);
        }
        if (is_array($value) && array_all($value, 'is_string')) {
            return array_values($value);
        }

        throw new OAuthTokenException('OAuth access token scope claim is invalid.');
    }

    private function authorization(OAuthAccessTokenClaims $claims): OAuthAuthorization
    {
        if ($claims->authorizationId === null) {
            return new OAuthAuthorization(
                id: 'client:' . $claims->tokenId,
                clientId: $claims->clientId,
                accountId: null,
                scopes: $claims->scopes,
                audiences: $claims->audiences,
                createdAt: $claims->issuedAt,
                expiresAt: $claims->expiresAt,
            );
        }

        $record = $this->authorizations->find($claims->authorizationId);
        if (!$record instanceof OAuthAuthorizationRecord) {
            throw new OAuthTokenException('OAuth authorization is inactive.');
        }

        return new OAuthAuthorization(
            id: $record->authorizationId,
            clientId: $record->clientId,
            accountId: $record->subject,
            scopes: $record->scopes,
            audiences: $record->audiences,
            createdAt: $record->authorizedAt,
            expiresAt: $record->expiresAt,
            revokedAt: $record->revokedAt,
        );
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenService;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;

final readonly class OAuth21AccessTokenHarness
{
    public function __construct(private OAuthAccessTokenService $tokens) {}

    public function verify(string $token, string $audience): OAuthAccessTokenClaims
    {
        $result = $this->tokens->validate($token, $audience);
        if (!$result->valid()) {
            throw new OAuthTokenException('OAuth access token verification failed.');
        }
        $claims = $result->claims;

        return new OAuthAccessTokenClaims(
            issuer: self::requiredString($claims, 'iss'),
            subject: self::requiredString($claims, 'sub'),
            audiences: self::audiences($claims['aud'] ?? null),
            expiresAt: self::requiredInt($claims, 'exp'),
            issuedAt: self::requiredInt($claims, 'iat'),
            tokenId: self::requiredString($claims, 'jti'),
            clientId: self::requiredString($claims, 'client_id'),
            scopes: self::scopes($claims['scope'] ?? null),
            authorizationId: self::optionalString($claims['authorization_id'] ?? null),
        );
    }

    /** @return list<string> */
    private static function audiences(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('OAuth fixture access-token audience claim is invalid.');
        }

        $result = [];
        foreach ($value as $audience) {
            if (!is_string($audience) || $audience === '') {
                throw new \RuntimeException('OAuth fixture access-token audience claim is invalid.');
            }
            $result[] = $audience;
        }

        return $result;
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $claims */
    private static function requiredInt(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;

        return is_int($value) ? $value : throw new \RuntimeException('OAuth fixture claim is invalid.');
    }

    /** @param array<string,mixed> $claims */
    private static function requiredString(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new \RuntimeException('OAuth fixture claim is invalid.');
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
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('OAuth fixture access-token scope claim is invalid.');
        }

        $result = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new \RuntimeException('OAuth fixture access-token scope claim is invalid.');
            }
            $result[] = $scope;
        }

        return $result;
    }
}

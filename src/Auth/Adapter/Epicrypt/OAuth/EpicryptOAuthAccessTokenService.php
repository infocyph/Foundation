<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Epicrypt\Token\Jwt\JwtProfile;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessTokenServiceInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

final readonly class EpicryptOAuthAccessTokenService implements OAuthAccessTokenServiceInterface
{
    private AsymmetricJwt $issuer;

    public function __construct(
        private OAuthSigningKeySet $keys,
        private int $maximumLifetimeSeconds = 300,
        private int $leewaySeconds = 30,
    ) {
        if ($this->maximumLifetimeSeconds < 1 || $this->leewaySeconds < 0) {
            throw new \InvalidArgumentException('OAuth access-token timing policy is invalid.');
        }

        $this->issuer = AsymmetricJwt::issuer(
            $this->keys->privateKey,
            'at+jwt',
            $this->keys->activeKeyId,
            $this->keys->algorithm,
        );
    }

    public function issue(OAuthAccessTokenClaims $claims): string
    {
        if (!hash_equals($this->keys->issuer, $claims->issuer)) {
            throw new OAuthTokenException('OAuth access token issuer does not match server policy.');
        }
        if (($claims->expiresAt - $claims->issuedAt) > $this->maximumLifetimeSeconds) {
            throw new OAuthTokenException('OAuth access token lifetime exceeds server policy.');
        }

        $custom = [
            'client_id' => $claims->clientId,
            'token_use' => $claims->tokenUse,
        ];
        if ($claims->scopes !== []) {
            $custom['scope'] = implode(' ', $claims->scopes);
        }
        if ($claims->authorizationId !== null) {
            $custom['authorization_id'] = $claims->authorizationId;
        }

        return $this->issuer->issue(new JwtClaims(
            issuer: $claims->issuer,
            subject: $claims->subject,
            audiences: $claims->audiences,
            expiresAt: $claims->expiresAt,
            notBefore: $claims->issuedAt,
            issuedAt: $claims->issuedAt,
            jwtId: $claims->tokenId,
            custom: $custom,
        ));
    }

    public function verify(string $token, string $expectedAudience): OAuthAccessTokenClaims
    {
        if ($token === '' || $expectedAudience === '') {
            throw new OAuthTokenException('OAuth access token verification failed.');
        }

        $policy = new JwtPolicy(
            expectedIssuer: $this->keys->issuer,
            expectedAudience: $expectedAudience,
            expectedType: 'at+jwt',
            maximumLifetimeSeconds: $this->maximumLifetimeSeconds,
            leewaySeconds: $this->leewaySeconds,
            maximumFutureIssuedAtSeconds: $this->leewaySeconds,
            profile: JwtProfile::OAUTH_ACCESS_TOKEN,
            requiredClaims: ['iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'client_id'],
        );
        $result = AsymmetricJwt::verifier(
            $this->keys->publicKeys,
            $policy,
            $this->keys->algorithm,
        )->verifyResult($token);
        if (!$result->valid) {
            throw new OAuthTokenException('OAuth access token verification failed.');
        }

        $claims = $result->claims;
        if (($claims['token_use'] ?? null) !== OAuthAccessTokenClaims::TOKEN_USE) {
            throw new OAuthTokenException('OAuth access token profile discriminator is invalid.');
        }

        return new OAuthAccessTokenClaims(
            issuer: $this->requiredString($claims, 'iss'),
            subject: $this->requiredString($claims, 'sub'),
            audiences: $this->audiences($claims['aud'] ?? null),
            expiresAt: $this->requiredInt($claims, 'exp'),
            issuedAt: $this->requiredInt($claims, 'iat'),
            tokenId: $this->requiredString($claims, 'jti'),
            clientId: $this->requiredString($claims, 'client_id'),
            scopes: $this->scopes($claims['scope'] ?? null),
            authorizationId: $this->optionalString($claims['authorization_id'] ?? null),
            tokenUse: OAuthAccessTokenClaims::TOKEN_USE,
        );
    }

    /** @return list<string> */
    private function audiences(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value) || $value === []) {
            throw new OAuthTokenException('OAuth access token audience claim is invalid.');
        }

        $audiences = [];
        foreach ($value as $audience) {
            if (!is_string($audience) || $audience === '') {
                throw new OAuthTokenException('OAuth access token audience claim is invalid.');
            }
            $audiences[] = $audience;
        }

        return $audiences;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $claims */
    private function requiredInt(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value)) {
            throw new OAuthTokenException(sprintf('OAuth access token claim "%s" is invalid.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $claims */
    private function requiredString(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new OAuthTokenException(sprintf('OAuth access token claim "%s" is invalid.', $name));
        }

        return $value;
    }

    /** @return list<string> */
    private function scopes(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            throw new OAuthTokenException('OAuth access token scope claim is invalid.');
        }

        $scopes = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($scopes) ? array_values($scopes) : [];
    }
}

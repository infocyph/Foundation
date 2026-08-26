<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAccessRevocationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenRevocation;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('rejects issuer audience token-use algorithm signature and time failures', function (): void {
    $fixture = new OAuth21FlowFixture();
    $audience = 'https://api.example.test';
    $now = time();
    $claims = new OAuthAccessTokenClaims(
        issuer: $fixture->keys->issuer,
        subject: 'account-1',
        audiences: [$audience],
        expiresAt: $now + 120,
        issuedAt: $now,
        tokenId: 'matrix-token',
        clientId: 'matrix-client',
        scopes: ['profile.read'],
        authorizationId: 'matrix-authorization',
    );

    try {
        $wrongIssuer = AsymmetricJwt::issuer(
            $fixture->keys->privateKey,
            'at+jwt',
            $fixture->keys->activeKeyId,
            $fixture->keys->algorithm,
        )->issue(JwtClaims::issue(
            issuer: 'https://other-issuer.example.test',
            subject: 'account-1',
            audiences: [$audience],
            ttlSeconds: 120,
            custom: [
                'client_id' => 'matrix-client',
                'token_use' => OAuthAccessTokenClaims::TOKEN_USE,
                'scope' => 'profile.read',
            ],
        ));
        $wrongAudience = $fixture->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $claims->issuer,
            subject: $claims->subject,
            audiences: ['https://other-api.example.test'],
            expiresAt: $claims->expiresAt,
            issuedAt: $claims->issuedAt,
            tokenId: 'wrong-audience-token',
            clientId: $claims->clientId,
            scopes: $claims->scopes,
            authorizationId: $claims->authorizationId,
        ));
        $wrongUse = $fixture->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $claims->issuer,
            subject: $claims->subject,
            audiences: $claims->audiences,
            expiresAt: $claims->expiresAt,
            issuedAt: $claims->issuedAt,
            tokenId: 'wrong-use-token',
            clientId: $claims->clientId,
            scopes: $claims->scopes,
            authorizationId: $claims->authorizationId,
            tokenUse: 'application_access',
        ));
        $valid = $fixture->accessTokens->issue($claims);
        $wrongAlgorithm = oauth21RejectRewriteHeader($valid, ['alg' => 'ES384']);
        $badSignature = oauth21RejectCorruptSignature($valid);
        $expired = $fixture->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $claims->issuer,
            subject: $claims->subject,
            audiences: $claims->audiences,
            expiresAt: $now - 1,
            issuedAt: $now - 100,
            tokenId: 'expired-token',
            clientId: $claims->clientId,
            scopes: $claims->scopes,
            authorizationId: $claims->authorizationId,
        ));
        $future = $fixture->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $claims->issuer,
            subject: $claims->subject,
            audiences: $claims->audiences,
            expiresAt: $now + 300,
            issuedAt: $now + 120,
            tokenId: 'future-token',
            clientId: $claims->clientId,
            scopes: $claims->scopes,
            authorizationId: $claims->authorizationId,
        ));

        foreach ([$wrongIssuer, $wrongAudience, $wrongUse, $wrongAlgorithm, $badSignature, $expired, $future] as $token) {
            expect(fn() => $fixture->accessTokens->verify($token, $audience))
                ->toThrow(OAuthTokenException::class);
        }
    } finally {
        $fixture->close();
    }
});

it('rejects revoked tokens disabled accounts disabled clients and revoked authorizations', function (): void {
    $fixture = new OAuth21FlowFixture();
    $audience = 'https://api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            ['https://client.example.test/callback'],
            ['profile.read'],
            [$audience],
        );
        $authorization = new OAuthAuthorization(
            id: 'matrix-authorization',
            clientId: $registration->client->clientId,
            accountId: 'account-1',
            scopes: ['profile.read'],
            audiences: [$audience],
            createdAt: $fixture->clock->now(),
        );
        $fixture->authorizationStore->save($authorization);
        $issuedAt = time();
        $token = $fixture->accessTokens->issue(new OAuthAccessTokenClaims(
            issuer: $fixture->keys->issuer,
            subject: 'account-1',
            audiences: [$audience],
            expiresAt: $issuedAt + 120,
            issuedAt: $issuedAt,
            tokenId: 'matrix-state-token',
            clientId: $registration->client->clientId,
            scopes: ['profile.read'],
            authorizationId: $authorization->id,
        ));
        $revocations = new class implements OAuthAccessRevocationStoreInterface {
            public bool $revoked = false;
            public function isRevoked(string $tokenId, int $now): bool { return $this->revoked; }
            public function revoke(OAuthAccessTokenRevocation $revocation): void { $this->revoked = true; }
        };
        $validator = new OAuthAccessTokenValidator(
            $fixture->accessTokens,
            $fixture->clients,
            $fixture->authorizationStore,
            $revocations,
            $fixture->scopes,
            $fixture->accounts,
            $fixture->clock,
        );

        expect($validator->verify($token, $audience)->claims->tokenId)->toBe('matrix-state-token');

        $revocations->revoked = true;
        expect(fn() => $validator->verify($token, $audience))->toThrow(OAuthTokenException::class);
        $revocations->revoked = false;

        $disabledAccounts = new class implements AccountProviderInterface {
            public function findById(string $id): ?AccountInterface
            {
                return $id === 'account-1' ? new class implements AccountInterface {
                    public function id(): string { return 'account-1'; }
                    public function identifier(): string { return 'disabled@example.test'; }
                    public function metadata(): array { return []; }
                    public function passwordHash(): ?string { return null; }
                    public function status(): AccountStatus { return AccountStatus::DISABLED; }
                } : null;
            }
            public function findByIdentifier(string $identifier): ?AccountInterface { return null; }
        };
        $disabledAccountValidator = new OAuthAccessTokenValidator(
            $fixture->accessTokens,
            $fixture->clients,
            $fixture->authorizationStore,
            $revocations,
            $fixture->scopes,
            $disabledAccounts,
            $fixture->clock,
        );
        expect(fn() => $disabledAccountValidator->verify($token, $audience))->toThrow(OAuthTokenException::class);

        $fixture->clients->setEnabled($registration->client->clientId, false);
        expect(fn() => $validator->verify($token, $audience))->toThrow(OAuthTokenException::class);
        $fixture->clients->setEnabled($registration->client->clientId, true);

        $fixture->authorizationStore->revoke($authorization->id, $fixture->clock->now());
        expect(fn() => $validator->verify($token, $audience))->toThrow(OAuthTokenException::class);
    } finally {
        $fixture->close();
    }
});

/** @param array<string, string> $changes */
function oauth21RejectRewriteHeader(string $token, array $changes): string
{
    [$encodedHeader, $payload, $signature] = explode('.', $token, 3);
    $header = json_decode(oauth21RejectBase64UrlDecode($encodedHeader), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($header)) {
        throw new RuntimeException('JWT header must decode to an array.');
    }

    return oauth21RejectBase64UrlEncode(json_encode([...$header, ...$changes], JSON_THROW_ON_ERROR))
        . '.' . $payload . '.' . $signature;
}

function oauth21RejectCorruptSignature(string $token): string
{
    [$header, $payload, $signature] = explode('.', $token, 3);
    $replacement = str_ends_with($signature, 'A') ? 'B' : 'A';

    return $header . '.' . $payload . '.' . substr($signature, 0, -1) . $replacement;
}

function oauth21RejectBase64UrlDecode(string $value): string
{
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

    return is_string($decoded) ? $decoded : throw new RuntimeException('Invalid Base64URL data.');
}

function oauth21RejectBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

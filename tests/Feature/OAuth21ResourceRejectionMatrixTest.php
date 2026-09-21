<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('rejects issuer audience algorithm signature and time failures', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now);
    $audience = 'https://api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['profile.read'],
            [$audience],
        );
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $registration->client->clientId,
            $registration->secret,
        );
        $valid = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'profile.read',
        ], $authentication)->accessToken;

        expect($fixture->accessTokens->verify($valid, $audience)->clientId)
            ->toBe($registration->client->clientId)
            ->and(fn() => $fixture->accessTokens->verify($valid, 'https://other-api.example.test'))
            ->toThrow(OAuthTokenException::class);

        $wrongAlgorithm = oauth21RejectRewriteHeader($valid, ['alg' => 'ES384']);
        $badSignature = oauth21RejectCorruptSignature($valid);
        foreach ([$wrongAlgorithm, $badSignature] as $token) {
            expect(fn() => $fixture->accessTokens->verify($token, $audience))
                ->toThrow(OAuthTokenException::class);
        }

        $clock = new EpicryptClockAdapter($fixture->clock);
        $wrongIssuer = AsymmetricJwt::issuer(
            $fixture->keys->privateKey,
            'at+jwt',
            $fixture->keys->activeKeyId,
            $fixture->keys->algorithm,
            clock: $clock,
        )->issue(new JwtClaims(
            issuer: 'https://other-issuer.example.test',
            subject: $registration->client->clientId,
            audiences: [$audience],
            expiresAt: $now + 120,
            notBefore: $now,
            issuedAt: $now,
            jwtId: 'wrong-issuer-token',
            custom: [
                'client_id' => $registration->client->clientId,
                'scope' => ['profile.read'],
            ],
        ));
        $expired = AsymmetricJwt::issuer(
            $fixture->keys->privateKey,
            'at+jwt',
            $fixture->keys->activeKeyId,
            $fixture->keys->algorithm,
            clock: $clock,
        )->issue(new JwtClaims(
            issuer: $fixture->keys->issuer,
            subject: $registration->client->clientId,
            audiences: [$audience],
            expiresAt: $now - 10,
            notBefore: $now - 120,
            issuedAt: $now - 120,
            jwtId: 'expired-token',
            custom: [
                'client_id' => $registration->client->clientId,
                'scope' => ['profile.read'],
            ],
        ));
        $future = AsymmetricJwt::issuer(
            $fixture->keys->privateKey,
            'at+jwt',
            $fixture->keys->activeKeyId,
            $fixture->keys->algorithm,
            clock: $clock,
        )->issue(new JwtClaims(
            issuer: $fixture->keys->issuer,
            subject: $registration->client->clientId,
            audiences: [$audience],
            expiresAt: $now + 300,
            notBefore: $now + 120,
            issuedAt: $now + 120,
            jwtId: 'future-token',
            custom: [
                'client_id' => $registration->client->clientId,
                'scope' => ['profile.read'],
            ],
        ));

        foreach ([$wrongIssuer, $expired, $future] as $token) {
            expect(fn() => $fixture->accessTokens->verify($token, $audience))
                ->toThrow(OAuthTokenException::class);
        }
    } finally {
        $fixture->close();
    }
});

it('rejects disabled accounts disabled clients and revoked authorizations', function (): void {
    $fixture = new OAuth21FlowFixture();
    $audience = 'https://api.example.test';
    $redirectUri = 'https://client.example.test/callback';
    $verifier = str_repeat('m', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $fixture->consents->grant($principal, $request);
        $issued = $fixture->codes->issue($request, $principal);
        $token = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $issued->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        ))->accessToken;

        expect($fixture->accessValidator->verify($token, $audience)->claims->clientId)
            ->toBe($registration->client->clientId);

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

            public function findByIdentifier(string $identifier): ?AccountInterface
            {
                unset($identifier);

                return null;
            }
        };
        $disabledAccountValidator = new OAuthAccessTokenValidator(
            $fixture->resourceValidator,
            $fixture->clients,
            $fixture->epicryptAuthorizations,
            $disabledAccounts,
        );
        expect(fn() => $disabledAccountValidator->verify($token, $audience))
            ->toThrow(OAuthTokenException::class);

        $fixture->clients->setEnabled($registration->client->clientId, false);
        expect(fn() => $fixture->accessValidator->verify($token, $audience))
            ->toThrow(OAuthTokenException::class);
        $fixture->clients->setEnabled($registration->client->clientId, true);

        $fixture->epicryptAuthorizations->revoke($issued->authorization->id, $fixture->clock->now());
        expect(fn() => $fixture->accessValidator->verify($token, $audience))
            ->toThrow(OAuthTokenException::class);
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

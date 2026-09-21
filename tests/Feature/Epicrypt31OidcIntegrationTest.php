<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\OpenIdIdTokenValidator;
use Infocyph\Epicrypt\Token\Jwt\Support\JwtToken;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdClaimsProvider;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc\FoundationOpenIdSubjectProvider;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdUserInfoProjector;

it('completes OIDC authorization code issuance with Epicrypt ID token validation and UserInfo projection', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now, openId: true);
    $redirectUri = 'https://oidc-client.example.test/callback';
    $audience = 'https://issuer.example.test/oidc/userinfo';
    $verifier = str_repeat('o', 64);
    $nonce = 'nonce-1234567890';
    $state = 'state-1234567890';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
            [$redirectUri],
            ['openid', 'profile', 'email'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'openid profile email',
            'audience' => $audience,
            'nonce' => $nonce,
            'state' => $state,
            'max_age' => '60',
            'acr_values' => 'urn:foundation:loa:2',
        ]);
        expect($request->openId())->toBeTrue()
            ->and($request->openIdNonce)->toBe($nonce)
            ->and($request->openIdMaximumAuthenticationAge)->toBe(60)
            ->and($request->openIdAcrValues)->toBe(['urn:foundation:loa:2']);

        $principal = new Principal(
            'account-1',
            accountId: 'account-1',
            metadata: [
                'auth_time' => $now - 30,
                'acr' => 'urn:foundation:loa:2',
                'amr' => ['pwd', 'otp'],
            ],
        );
        $fixture->consents->grant($principal, $request);
        $issued = $fixture->codes->issue($request, $principal);
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        );
        $tokens = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $issued->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], $authentication);

        $idToken = $tokens->additionalParameters['id_token'] ?? null;
        expect($idToken)->toBeString()->not->toBe('');
        [, , , , $claims] = JwtToken::parse((string) $idToken);
        new OpenIdIdTokenValidator(new EpicryptClockAdapter($fixture->clock))->validate(
            $claims,
            AsymmetricJwtAlgorithm::ES256,
            $registration->client->clientId,
            nonce: $nonce,
            accessToken: $tokens->accessToken,
            authorizationCode: $issued->code,
            state: $state,
            maximumAuthenticationAge: 60,
        );
        expect($claims['sub'] ?? null)->toBe('account-1')
            ->and($claims['auth_time'] ?? null)->toBe($now - 30)
            ->and($claims['acr'] ?? null)->toBe('urn:foundation:loa:2')
            ->and($claims['amr'] ?? null)->toBe(['pwd', 'otp']);

        $userinfo = new OpenIdUserInfoProjector(
            new FoundationOpenIdSubjectProvider(),
            new FoundationOpenIdClaimsProvider($fixture->accounts),
        );
        expect($userinfo->project(
            'account-1',
            $registration->client->clientId,
            ['openid', 'profile', 'email'],
        ))->toMatchArray([
            'sub' => 'account-1',
            'email' => 'account@example.test',
        ]);
    } finally {
        $fixture->close();
    }
});

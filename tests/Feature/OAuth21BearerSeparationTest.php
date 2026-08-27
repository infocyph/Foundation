<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptAccessTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptTokenFactory;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\OAuthBearerTokenPrincipalResolver;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Webrick\Request\Request;

it('keeps OAuth and application bearer token profiles mutually exclusive', function (): void {
    $fixture = new OAuth21FlowFixture();
    $oauthAudience = 'https://oauth-resource.example.test';

    try {
        $oauthToken = oauth21SeparatedOAuthToken($fixture, $oauthAudience);
        $applicationTokens = new EpicryptAccessTokenService(new EpicryptTokenFactory(
            key: str_repeat('application-token-key-', 3),
            clock: $fixture->clock,
            issuer: 'foundation-application',
            audience: 'foundation-application-api',
            maximumLifetimeSeconds: 3600,
        ));
        $applicationToken = $applicationTokens->issue(new AccessTokenClaims(
            subjectId: 'account-1',
            actorId: null,
            issuedAt: $fixture->clock->now(),
            expiresAt: $fixture->clock->now() + 300,
            scopes: ['application.read'],
        ));

        $httpConfig = new ConfigRepository([
            'auth' => [
                'http' => [
                    'bearer_header' => 'Authorization',
                    'bearer_prefix' => 'Bearer ',
                ],
                'oauth' => ['resource_audiences' => [$oauthAudience]],
            ],
        ]);
        $applicationResolver = new BearerTokenPrincipalResolver(
            $httpConfig,
            $applicationTokens,
            $fixture->accounts,
        );
        $oauthResolver = new OAuthBearerTokenPrincipalResolver(
            $httpConfig,
            new OAuthAccessTokenValidator(
                $fixture->accessTokens,
                $fixture->clients,
                $fixture->authorizationStore,
                new DBLayerOAuthAccessRevocationStore($fixture->factory, $fixture->tables),
                $fixture->scopes,
                $fixture->accounts,
                $fixture->clock,
            ),
        );

        $oauthRequest = Request::fake(headers: ['Authorization' => 'Bearer ' . $oauthToken]);
        $applicationRequest = Request::fake(headers: ['Authorization' => 'Bearer ' . $applicationToken]);

        expect($oauthResolver->resolve($oauthRequest)?->metadata()['auth_via'] ?? null)->toBe('oauth_bearer')
            ->and($applicationResolver->resolve($applicationRequest)?->metadata()['auth_via'] ?? null)->toBe('bearer')
            ->and($applicationResolver->resolve($oauthRequest))->toBeNull()
            ->and($oauthResolver->resolve($applicationRequest))->toBeNull();
    } finally {
        $fixture->close();
    }
});

function oauth21SeparatedOAuthToken(OAuth21FlowFixture $fixture, string $audience): string
{
    $redirect = 'https://separation.example.test/callback';
    $verifier = str_repeat('s', 64);
    $registration = $fixture->clients->register(
        OAuthClientType::Public,
        [OAuthGrantType::AuthorizationCode],
        [$redirect],
        ['profile.read'],
        [$audience],
    );
    $request = $fixture->requests->validate([
        'client_id' => $registration->client->clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
        'code_challenge_method' => 'S256',
        'scope' => 'profile.read',
        'audience' => $audience,
    ]);
    $principal = new Principal('account-1', accountId: 'account-1');
    $fixture->consents->grant($principal, $request);
    $code = $fixture->codes->issue($request, $principal);

    return $fixture->tokens->exchange([
        'grant_type' => OAuthGrantType::AuthorizationCode->value,
        'code' => $code->code,
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
    ], new OAuthClientAuthentication(
        OAuthClientAuthenticationMethod::None,
        $registration->client->clientId,
    ))->accessToken;
}

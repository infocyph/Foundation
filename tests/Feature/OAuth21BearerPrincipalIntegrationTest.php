<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\PermissionMiddleware;
use Infocyph\Foundation\Http\Resolver\OAuthBearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('resolves account and service OAuth bearer tokens into the existing principal and authorization pipeline', function (): void {
    $fixture = new OAuth21FlowFixture();
    $audience = 'https://resource.example.test';

    try {
        $accountToken = oauth21AccountBearerToken($fixture, $audience);
        $serviceToken = oauth21ServiceBearerToken($fixture, $audience);
        $validator = new OAuthAccessTokenValidator(
            $fixture->accessTokens,
            $fixture->clients,
            $fixture->authorizationStore,
            new DBLayerOAuthAccessRevocationStore($fixture->factory, $fixture->tables),
            $fixture->scopes,
            $fixture->accounts,
            $fixture->clock,
        );
        $resolver = new OAuthBearerTokenPrincipalResolver(new ConfigRepository([
            'auth' => [
                'http' => [
                    'bearer_header' => 'Authorization',
                    'bearer_prefix' => 'Bearer ',
                ],
                'oauth' => ['resource_audiences' => [$audience]],
            ],
        ]), $validator);

        $account = $resolver->resolve(Request::fake(headers: ['Authorization' => 'Bearer ' . $accountToken]));
        $service = $resolver->resolve(Request::fake(headers: ['Authorization' => 'Bearer ' . $serviceToken]));

        expect($account)->not->toBeNull()
            ->and($account?->type())->toBe(PrincipalType::ACCOUNT)
            ->and($account?->accountId())->toBe('account-1')
            ->and($account?->metadata()['auth_via'] ?? null)->toBe('oauth_bearer')
            ->and($account?->metadata()['oauth_scopes'] ?? null)->toBe(['profile.read'])
            ->and($service)->not->toBeNull()
            ->and($service?->type())->toBe(PrincipalType::SERVICE)
            ->and($service?->accountId())->toBeNull()
            ->and($service?->metadata()['auth_via'] ?? null)->toBe('oauth_bearer')
            ->and($service?->metadata()['oauth_scopes'] ?? null)->toBe(['service.read']);

        $seen = [];
        $authorizer = new class($seen) implements AuthorizerInterface {
            /** @var list<array{principal:PrincipalInterface,ability:string}> */
            public array $seen = [];

            public function __construct(array $seen) { unset($seen); }

            public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void
            {
                $this->seen[] = ['principal' => $principal, 'ability' => $ability];
            }

            public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision
            {
                $this->seen[] = ['principal' => $principal, 'ability' => $ability];
                return AuthorizationDecision::allow();
            }
        };
        $responses = new AuthResponseFactory();
        $exceptions = new AuthExceptionMapper($responses);
        $context = new CurrentPrincipalContext();
        $auth = new AuthMiddleware($context, $responses);
        $permission = new PermissionMiddleware($context, $authorizer, $exceptions, $responses, ['resource.read']);
        $next = static fn(Request $request): Response => Response::json(['ok' => true]);

        foreach ([$account, $service] as $principal) {
            expect($principal)->not->toBeNull();
            $context->set($principal);
            expect($auth(Request::fake(), $next)->getStatusCode())->toBe(200)
                ->and($permission(Request::fake(), $next)->getStatusCode())->toBe(200);
        }

        expect($authorizer->seen)->toHaveCount(2)
            ->and($authorizer->seen[0]['principal'])->toBe($account)
            ->and($authorizer->seen[0]['ability'])->toBe('resource.read')
            ->and($authorizer->seen[1]['principal'])->toBe($service)
            ->and($authorizer->seen[1]['ability'])->toBe('resource.read');
    } finally {
        $fixture->close();
    }
});

function oauth21AccountBearerToken(OAuth21FlowFixture $fixture, string $audience): string
{
    $redirect = 'https://account-resource.example.test/callback';
    $verifier = str_repeat('a', 64);
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

function oauth21ServiceBearerToken(OAuth21FlowFixture $fixture, string $audience): string
{
    $registration = $fixture->clients->register(
        OAuthClientType::Confidential,
        [OAuthGrantType::ClientCredentials],
        [],
        ['service.read'],
        [$audience],
    );

    return $fixture->tokens->exchange([
        'grant_type' => OAuthGrantType::ClientCredentials->value,
        'scope' => 'service.read',
        'audience' => $audience,
    ], new OAuthClientAuthentication(
        OAuthClientAuthenticationMethod::ClientSecretBasic,
        $registration->client->clientId,
        $registration->secret,
    ))->accessToken;
}

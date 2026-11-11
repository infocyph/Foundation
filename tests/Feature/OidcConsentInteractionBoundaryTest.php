<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionPolicy;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Foundation\Session\Store\ArraySessionStore;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Response\Response;

it('rechecks OIDC interaction on a valid-CSRF approval POST before issuing a code or consent', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now, openId: true);
    $config = new ConfigRepository([
        'auth' => ['oauth' => [
            'issuer' => 'https://issuer.example.test',
            'grants' => ['authorization_code'],
            'routes' => [
                'authorization' => '/oauth/authorize',
                'token' => '/oauth/token',
                'revocation' => '/oauth/revoke',
                'introspection' => '/oauth/introspect',
                'jwks' => '/.well-known/jwks.json',
            ],
        ]],
        'session' => ['driver' => 'array'],
    ]);
    $sessionConfig = SessionConfig::fromRepository($config, sys_get_temp_dir());
    $browserStore = new ArraySessionStore();
    $browser = new BrowserSession(
        null,
        static fn() => $browserStore,
        static fn() => null,
        $sessionConfig,
    );
    $csrfToken = $browser->csrfToken();
    $principals = new CurrentPrincipalContext(RuntimeStateContainer::principal());
    $keys = new class implements JwkSetProviderInterface {
        #[\Override]
        public function jwks(): array
        {
            return ['keys' => []];
        }
    };
    $manager = new OAuthManager(
        $fixture->requests,
        $fixture->consents,
        $fixture->codes,
        $fixture->tokens,
        $fixture->revocation,
        $fixture->introspection,
        new AuthorizationServerMetadata($config),
        $keys,
        $fixture->clients,
        openIdInteractions: new OpenIdInteractionPolicy(new EpicryptClockAdapter($fixture->clock)),
        clock: $fixture->clock,
    );
    $handler = new OAuthHttpHandler($manager, new OAuthHttpInput(), new OAuthHttpResponseFactory(), $config);
    $controller = new OAuthAuthorizationController($handler, $principals, $sessionConfig);
    $csrf = new CsrfMiddleware($sessionConfig);
    $root = sys_get_temp_dir() . '/foundation-oidc-approval-route-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700, true)) {
        throw new RuntimeException('Cannot create the OIDC test route root.');
    }

    try {
        // Dispatch through the real Webrick kernel and native CSRF middleware,
        // not a hand-invoked controller callback.
        $web = Foundation::web([
            'base_path' => $root,
            '_config_cache' => false,
            'router' => ['cache' => false, 'files' => []],
        ])->boot();
        $web->make(Registrar::class)->post(
            '/oauth/authorize',
            $controller->authorization(...),
            ['name' => 'test.oidc.approval', 'middleware' => [$csrf]],
        );
        $web->make(Registrar::class)->get(
            '/oauth/authorize',
            $controller->authorization(...),
            ['name' => 'test.oidc.authorization', 'middleware' => [$csrf]],
        );

        $client = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            ['https://client.example.test/callback'],
            ['openid', 'profile'],
            ['https://issuer.example.test/oidc/userinfo'],
        )->client;
        $base = [
            'client_id' => $client->clientId,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge(str_repeat('v', 64)),
            'code_challenge_method' => 'S256',
            'scope' => 'openid profile',
            'audience' => 'https://issuer.example.test/oidc/userinfo',
            'nonce' => 'consent-provenance-nonce',
        ];
        $submit = static function (array $params, Principal $principal) use ($principals, $browser, $csrfToken, $web): Response {
            $principals->set($principal);
            $uri = 'https://issuer.example.test/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $request = Request::fake(
                query: $params,
                post: ['decision' => 'approve', '_token' => $csrfToken],
                headers: ['Host' => 'issuer.example.test', 'Origin' => 'https://issuer.example.test'],
                method: 'POST',
                uri: $uri,
            )->withAttribute(BrowserSession::REQUEST_ATTRIBUTE, $browser);

            return $web->handle($request);
        };

        $stale = new Principal('account-1', accountId: 'account-1', metadata: ['auth_time' => $now - 7_200]);
        $unknown = new Principal('account-1', accountId: 'account-1');
        $fresh = new Principal('account-1', accountId: 'account-1', metadata: ['auth_time' => $now - 30]);

        foreach ([
            [$stale, ['prompt' => 'none', 'max_age' => '60']],
            [$unknown, ['prompt' => 'none', 'max_age' => '60']],
            [$fresh, ['prompt' => 'login']],
            [$fresh, ['prompt' => 'select_account']],
            [$fresh, ['prompt' => 'none', 'acr_values' => 'urn:infocyph:acr:high']],
            [$fresh, ['prompt' => 'none', 'max_age' => '0']],
        ] as [$principal, $parameters]) {
            $params = $base + $parameters;
            $response = $submit($params, $principal);
            expect($response->getStatusCode())->toBe(302)
                ->and($response->getHeaderLine('Location'))->toContain('error=')
                ->not->toContain('code=');
            expect($fixture->consents->hasConsent($principal, $fixture->requests->validate($params)))->toBeFalse();
        }

        $sameSecond = new Principal('account-1', accountId: 'account-1', metadata: ['auth_time' => $now]);
        foreach (['none', 'consent'] as $prompt) {
            $zeroParams = $base + ['prompt' => $prompt, 'max_age' => '0'];
            $zeroRequest = $fixture->requests->validate($zeroParams);
            $consent = $fixture->consents->grant($sameSecond, $zeroRequest);

            $rejected = $submit($zeroParams, $sameSecond);
            expect($rejected->getHeaderLine('Location'))->toContain('error=login_required')->not->toContain('code=');
            $uri = 'https://issuer.example.test/oauth/authorize?' . http_build_query($zeroParams, '', '&', PHP_QUERY_RFC3986);
            $get = $web->handle(Request::fake(
                query: $zeroParams,
                headers: ['Host' => 'issuer.example.test'],
                uri: $uri,
            )->withAttribute(BrowserSession::REQUEST_ATTRIBUTE, $browser));
            expect($get->getHeaderLine('Location'))->toContain('error=login_required')->not->toContain('code=')
                ->and($fixture->consents->grant($sameSecond, $zeroRequest))->toEqual($consent);
            expect(fn() => $fixture->codes->issue($zeroRequest, $sameSecond))->toThrow(OAuthProtocolException::class);
            expect($fixture->factory->connection()->table($fixture->tables->oauthAuthorizationCodeStates())->count())->toBe(0);
        }

        $successParams = $base + ['prompt' => 'consent', 'max_age' => '60'];
        $approved = $submit($successParams, $fresh);
        expect($approved->getStatusCode())->toBe(302)
            ->and($approved->getHeaderLine('Location'))->toContain('code=')
            ->not->toContain('error=');
        expect($fixture->consents->hasConsent($fresh, $fixture->requests->validate($successParams)))->toBeTrue();

        $principals->set($stale);
        $invalidCsrf = Request::fake(
            query: $successParams,
            post: ['decision' => 'approve', '_token' => 'forged'],
            headers: ['Host' => 'issuer.example.test'],
            method: 'POST',
            uri: 'https://issuer.example.test/oauth/authorize?' . http_build_query($successParams, '', '&', PHP_QUERY_RFC3986),
        )->withAttribute(BrowserSession::REQUEST_ATTRIBUTE, $browser);
        expect($web->handle($invalidCsrf)->getStatusCode())->toBe(419);
    } finally {
        $fixture->close();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                throw new \UnexpectedValueException('Expected a filesystem iterator entry.');
            }

            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
});

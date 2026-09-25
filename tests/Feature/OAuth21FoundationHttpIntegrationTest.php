<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthClientStore;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthClientStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthRateLimitMiddleware;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Scope\OAuthScopeResolver;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

it('keeps the Foundation-owned OAuth HTTP surface absent while disabled', function (): void {
    $root = foundationOAuthHttpProject();

    try {
        $app = Foundation::web([
            'base_path' => $root,
            '_config_cache' => false,
            'router' => ['cache' => false, 'files' => []],
        ])->boot();

        expect(foundationOAuthHttpRoutes($app->make(Registrar::class)))->toBe([])
            ->and($app->container()->getRepository()->hasResolvedSingleton(OAuthManager::class))->toBeFalse();
    } finally {
        foundationOAuthHttpRemoveProject($root);
    }
});

it('owns and resolves the complete opt-in OAuth HTTP surface', function (): void {
    [$root, $privateKey, $publicKey] = foundationOAuthHttpKeyProject();

    try {
        $app = Foundation::web(foundationOAuthHttpOptions($root, $privateKey, $publicKey))->boot();
        $routes = foundationOAuthHttpRoutes($app->make(Registrar::class));

        expect($routes)->toHaveKeys([
            'GET /.well-known/oauth-authorization-server',
            'GET /.well-known/jwks.json',
            'GET /oauth/authorize',
            'POST /oauth/authorize',
            'POST /oauth/token',
            'POST /oauth/revoke',
            'POST /oauth/introspect',
        ])->and($routes['GET /oauth/authorize']->getHandler())
            ->toBe([OAuthAuthorizationController::class, 'authorization'])
            ->and($routes['GET /oauth/authorize']->getMiddlewares())->toBe([
                'oauth-throttle:authorization',
                'session',
                'csrf',
                'resolve-auth',
                'auth',
            ])->and($routes['POST /oauth/token']->getHandler())->toBe([OAuthHttpHandler::class, 'token'])
            ->and($routes['POST /oauth/token']->getMiddlewares())->toBe(['oauth-throttle:token'])
            ->and($app->make(OAuthHttpHandler::class))->toBeInstanceOf(OAuthHttpHandler::class)
            ->and($app->make(OAuthAuthorizationController::class))->toBeInstanceOf(OAuthAuthorizationController::class)
            ->and(MiddlewareAliases::resolveString('oauth-throttle:token'))
            ->toBeInstanceOf(Closure::class);
    } finally {
        DB::purge();
        foundationOAuthHttpRemoveProject($root);
    }
});

it('includes Foundation OAuth routes in compiled Webrick releases', function (): void {
    [$root, $privateKey, $publicKey] = foundationOAuthHttpKeyProject();
    $options = foundationOAuthHttpOptions($root, $privateKey, $publicKey);
    mkdir($root . '/bootstrap/cache', 0775, true);
    $router = $root . '/bootstrap/cache/router.php';

    try {
        $release = new WebReleaseCompiler()->compile(
            $options,
            $root . '/bootstrap/cache/intermix.php',
            $router,
            $root . '/bootstrap/cache/release.json',
            ['auth', 'cache', 'database', 'session'],
        );
        expect($release['intermix']['skipped'] ?? null)->toBe([]);

        $payload = require $router;
        expect($payload)->toBeArray();
        $artifact = CompiledRouterArtifact::fromPayload($payload);
        $oauthMetadata = null;
        foreach ($artifact->routes() as $route) {
            if ($route->getName() === 'oauth.metadata') {
                $oauthMetadata = $route;
                break;
            }
        }

        expect($oauthMetadata)->not->toBeNull()
            ->and($oauthMetadata->getPath())->toBe('/.well-known/oauth-authorization-server');

        $oauthPlan = $artifact->planForIndex($oauthMetadata->getIndex());
        expect($oauthPlan->resolverSpec())->toBe([OAuthHttpHandler::class, 'metadata']);
    } finally {
        foundationResetWebrickProductionRegistries();
        DB::purge();
        foundationOAuthHttpRemoveProject($root);
    }
});

it('resolves Foundation-owned OAuth environment configuration once at bootstrap', function (): void {
    $root = foundationOAuthHttpProject();
    $keys = [
        'AUTH_OAUTH_ENABLED' => 'true',
        'AUTH_OAUTH_ISSUER' => 'https://identity.example.test',
        'AUTH_OAUTH_ACTIVE_KEY_ID' => 'oauth-key-1',
        'AUTH_OAUTH_PRIVATE_KEY' => '/run/secrets/oauth-private.pem',
        'AUTH_OAUTH_PUBLIC_KEYS' => json_encode([[
            'id' => 'oauth-key-1',
            'path' => '/run/secrets/oauth-public.pem',
            'status' => 'active',
        ]], JSON_THROW_ON_ERROR),
    ];
    $snapshot = foundationOAuthHttpEnvironmentSnapshot(array_keys($keys));

    try {
        foreach ($keys as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        $app = Foundation::web([
            'base_path' => $root,
            '_config_cache' => false,
            'router' => ['cache' => false, 'files' => []],
        ]);

        expect($app->config()->get('auth.oauth.enabled'))->toBeTrue()
            ->and($app->config()->get('auth.oauth.issuer'))->toBe('https://identity.example.test')
            ->and($app->config()->get('auth.oauth.signing.active_key_id'))->toBe('oauth-key-1')
            ->and($app->config()->get('auth.oauth.signing.public_keys.0.status'))->toBe('active');
    } finally {
        foundationOAuthHttpRestoreEnvironment($snapshot);
        foundationOAuthHttpRemoveProject($root);
    }
});

/** @return array<string, object> */
function foundationOAuthHttpRoutes(Registrar $registrar): array
{
    $routes = [];
    foreach ($registrar->compile()->all() as $route) {
        if (str_starts_with($route->getName(), 'oauth.')) {
            $routes[$route->getMethod() . ' ' . $route->getPath()] = $route;
        }
    }

    return $routes;
}

/** @return array{0:string,1:string,2:string} */
function foundationOAuthHttpKeyProject(): array
{
    $root = foundationOAuthHttpProject();
    $pair = KeyPairGenerator::ec()->generate();
    $privateKey = $root . '/oauth-private.pem';
    $publicKey = $root . '/oauth-public.pem';
    file_put_contents($privateKey, $pair['private']);
    file_put_contents($publicKey, $pair['public']);

    return [$root, $privateKey, $publicKey];
}

function foundationOAuthHttpProject(): string
{
    $root = sys_get_temp_dir() . '/foundation-oauth-http-' . bin2hex(random_bytes(5));
    mkdir($root . '/routes', 0775, true);

    return $root;
}

/** @return array<string, mixed> */
function foundationOAuthHttpOptions(string $root, string $privateKey, string $publicKey): array
{
    return [
        'base_path' => $root,
        '_config_cache' => false,
        'app' => ['env' => 'testing'],
        'auth' => [
            'oauth' => [
                'enabled' => true,
                'issuer' => 'https://identity.example.test',
                'signing' => [
                    'algorithm' => 'ES256',
                    'active_key_id' => 'oauth-key-1',
                    'private_key' => $privateKey,
                    'public_keys' => [[
                        'id' => 'oauth-key-1',
                        'path' => $publicKey,
                        'status' => 'active',
                    ]],
                ],
            ],
        ],
        'cache' => [
            'default' => 'file',
            'stores' => [
                'file' => ['driver' => 'file', 'path' => 'storage/cache/file'],
            ],
        ],
        'database' => [
            'default' => 'oauth',
            'connections' => [
                'oauth' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'router' => ['cache' => false, 'files' => []],
        'session' => ['driver' => 'array'],
    ];
}

/**
 * @param list<string> $keys
 * @return array<string, array{env:mixed,server:mixed,process:string|false,env_exists:bool,server_exists:bool}>
 */
function foundationOAuthHttpEnvironmentSnapshot(array $keys): array
{
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = [
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
            'process' => getenv($key),
            'env_exists' => array_key_exists($key, $_ENV),
            'server_exists' => array_key_exists($key, $_SERVER),
        ];
    }

    return $snapshot;
}

/** @param array<string, array{env:mixed,server:mixed,process:string|false,env_exists:bool,server_exists:bool}> $snapshot */
function foundationOAuthHttpRestoreEnvironment(array $snapshot): void
{
    foreach ($snapshot as $key => $state) {
        if ($state['env_exists']) {
            $_ENV[$key] = $state['env'];
        } else {
            unset($_ENV[$key]);
        }
        if ($state['server_exists']) {
            $_SERVER[$key] = $state['server'];
        } else {
            unset($_SERVER[$key]);
        }
        if ($state['process'] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $state['process']);
        }
    }
}

function foundationOAuthHttpRemoveProject(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

it('performs one native OAuth protocol validation per HTTP authorization request', function (): void {
    $fixture = new OAuth21FlowFixture();
    $clientStore = new FoundationOAuthClientStoreProbe(
        new DBLayerOAuthClientStore($fixture->factory, $fixture->tables),
    );
    $config = new ConfigRepository([
        'auth' => [
            'oauth' => [
                'issuer' => 'https://issuer.example.test',
                'oidc' => ['enabled' => false],
                'scope_permissions' => [],
                'routes' => [
                    'authorization' => '/oauth/authorize',
                    'token' => '/oauth/token',
                    'revocation' => '/oauth/revoke',
                    'introspection' => '/oauth/introspect',
                    'jwks' => '/.well-known/jwks.json',
                ],
                'grants' => [OAuthGrantType::AuthorizationCode->value],
            ],
        ],
    ]);
    $clients = new OAuthClientManager(
        $clientStore,
        new class implements PasswordHasherInterface {
            public function hash(string $plainPassword, array $context = []): string
            {
                unset($context);

                return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);
            }
        },
        new class implements PasswordVerifierInterface {
            public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
            {
                return new PasswordVerificationResult(password_verify($plainPassword, $storedHash));
            }
        },
        $fixture->clock,
        new OpaqueToken(),
        false,
    );
    $requests = new AuthorizationRequestValidator(
        $clients,
        new OAuthScopeResolver($clientStore, $config),
        $config,
    );
    $manager = new OAuthManager(
        $requests,
        $fixture->consents,
        $fixture->codes,
        $fixture->tokens,
        $fixture->revocation,
        $fixture->introspection,
        new AuthorizationServerMetadata($config),
        new class implements JwkSetProviderInterface {
            public function jwks(): array
            {
                return ['keys' => []];
            }
        },
        $clients,
    );
    $handler = new OAuthHttpHandler(
        $manager,
        new OAuthHttpInput(),
        new OAuthHttpResponseFactory(),
        $config,
    );
    $redirectUri = 'https://client.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('v', 64);

    try {
        $registration = $clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        $clientStore->resetCounts();

        $query = http_build_query([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
        ], '', '&', PHP_QUERY_RFC3986);
        $result = $handler->authorization(Request::fake(
            headers: ['Host' => 'issuer.example.test'],
            uri: 'https://issuer.example.test/oauth/authorize?' . $query,
        ));

        expect($result)->toBeInstanceOf(AuthorizationRequest::class)
            ->and($clientStore->redirectUriReads)->toBe(1)
            ->and($clientStore->findReads)->toBe(2)
            ->and($clientStore->scopeReads)->toBe(2);
    } finally {
        $fixture->close();
    }
});

final class FoundationOAuthClientStoreProbe implements OAuthClientStoreInterface
{
    public int $findReads = 0;

    public int $redirectUriReads = 0;

    public int $scopeReads = 0;

    public function __construct(private readonly OAuthClientStoreInterface $inner) {}

    public function find(string $clientId): ?OAuthClient
    {
        ++$this->findReads;

        return $this->inner->find($clientId);
    }

    public function list(int $limit = 100): array
    {
        return $this->inner->list($limit);
    }

    public function redirectUris(string $clientId): array
    {
        ++$this->redirectUriReads;

        return $this->inner->redirectUris($clientId);
    }

    public function register(OAuthClient $client, array $redirectUris, array $scopes): void
    {
        $this->inner->register($client, $redirectUris, $scopes);
    }

    public function replaceRedirectUris(string $clientId, array $redirectUris, int $createdAt): void
    {
        $this->inner->replaceRedirectUris($clientId, $redirectUris, $createdAt);
    }

    public function replaceScopes(string $clientId, array $scopes, int $createdAt): void
    {
        $this->inner->replaceScopes($clientId, $scopes, $createdAt);
    }

    public function resetCounts(): void
    {
        $this->findReads = 0;
        $this->redirectUriReads = 0;
        $this->scopeReads = 0;
    }

    public function save(OAuthClient $client): void
    {
        $this->inner->save($client);
    }

    public function scopes(string $clientId): array
    {
        ++$this->scopeReads;

        return $this->inner->scopes($clientId);
    }
}

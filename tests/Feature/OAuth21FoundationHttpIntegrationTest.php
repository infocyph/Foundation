<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthRateLimitMiddleware;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\RouteCacheManager;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Webrick\Request\Request;
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
            ->toBeInstanceOf(OAuthRateLimitMiddleware::class);
    } finally {
        DB::purge();
        foundationOAuthHttpRemoveProject($root);
    }
});

it('includes Foundation OAuth routes in generated route caches', function (): void {
    [$root, $privateKey, $publicKey] = foundationOAuthHttpKeyProject();
    $options = foundationOAuthHttpOptions($root, $privateKey, $publicKey);
    $options['router']['cache'] = true;

    try {
        $cli = Foundation::cli($options);
        new RouteCacheManager($cli)->write('fused', RouteCachePath::for($cli->config()));

        $response = Foundation::web($options)->handle(Request::fake(
            headers: ['Host' => 'identity.example.test'],
            uri: 'https://identity.example.test/.well-known/oauth-authorization-server',
        ));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        expect($response->getStatusCode())->toBe(200)
            ->and($body['issuer'] ?? null)->toBe('https://identity.example.test');
    } finally {
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

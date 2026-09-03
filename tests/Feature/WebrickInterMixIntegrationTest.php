<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnRuntime;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Resolver\PrincipalResolverInterface;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Routing\RouteCacheManager;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\WebrickMiddlewareFactory;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Foundation\Testing\TestKit;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Support\RouteCache as WebrickRouteCache;

interface FoundationTestGateway
{
    public function name(): string;
}

final readonly class LocalFoundationGateway implements FoundationTestGateway
{
    public function name(): string
    {
        return 'local';
    }
}

final readonly class ProductionFoundationGateway implements FoundationTestGateway
{
    public function name(): string
    {
        return 'production';
    }
}

final class FoundationScopedProbe
{
    private static int $nextSequence = 0;
    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$nextSequence;
    }
}

it('applies InterMix environment bindings from the application environment', function (): void {
    $provider = new class extends ServiceProvider {
        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);

            $builder->options()
                ->bindInterfaceForEnv('local', FoundationTestGateway::class, LocalFoundationGateway::class)
                ->bindInterfaceForEnv('production', FoundationTestGateway::class, ProductionFoundationGateway::class);
        }
    };

    $app = Foundation::web([
        'app' => ['env' => 'local'],
        'providers' => ['web' => [$provider]],
    ]);

    expect($app->make(FoundationTestGateway::class))->toBeInstanceOf(LocalFoundationGateway::class);
});

it('scopes request-lifetime services through the HTTP kernel', function (): void {
    $provider = new class extends ServiceProvider {
        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);

            $builder->bindFactory(
                'scoped.probe',
                static fn(): FoundationScopedProbe => new FoundationScopedProbe(),
                LifetimeEnum::Scoped,
            );
        }
    };
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Foundation\Application\Application;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/scope-check', static function (Application $app): Response {
    $first = $app->make('scoped.probe');
    $second = $app->make('scoped.probe');
    return Response::json(['first' => $first->sequence, 'second' => $second->sequence]);
}, ['name' => 'scope.check']);
PHP,
    ]);

    try {
        $app = Foundation::web(['base_path' => $project, 'providers' => ['web' => [$provider]]]);
        $first = foundationJsonResponse($app->handle(foundationRequest('/scope-check')));
        $second = foundationJsonResponse($app->handle(foundationRequest('/scope-check')));
        expect($first['first'])->toBe($first['second'])
            ->and($second['first'])->toBe($second['second'])
            ->and($first['first'])->not->toBe($second['first']);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('loads route files and exposes native Webrick route services', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/facade-route', static fn(): array => ['registered' => true], ['name' => 'facade.route']);
PHP,
    ]);

    try {
        $app = Foundation::web(['base_path' => $project])->boot();
        $routes = $app->make(Collection::class);
        $kernel = $app->make(RouterKernel::class);
        expect($routes->findByName('facade.route'))->not->toBeNull()
            ->and($kernel)->toBe($app->make(RouterKernel::class))
            ->and(foundationJsonResponse($app->handle(foundationRequest('/facade-route'))))->toBe(['registered' => true]);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('keeps unrelated optional subsystems deferred for plain routes', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Foundation\Auth\Exception\AuthenticationException;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/lean', static fn(): Response => Response::json(['ok' => true]));
Router::get('/auth-error', static fn(): never => throw new AuthenticationException('Authentication required.'));
PHP,
    ]);

    try {
        $app = Foundation::web(['base_path' => $project]);
        $repository = $app->container()->getRepository();
        expect($repository->hasResolvedSingleton(AuthManager::class))->toBeFalse()
            ->and($repository->hasResolvedSingleton(CacheManager::class))->toBeFalse()
            ->and(foundationJsonResponse($app->handle(foundationRequest('/lean'))))->toBe(['ok' => true])
            ->and($repository->hasResolvedSingleton(AuthManager::class))->toBeFalse()
            ->and($repository->hasResolvedSingleton(CacheManager::class))->toBeFalse()
            ->and($repository->hasResolvedSingleton(ExceptionRenderer::class))->toBeFalse();

        expect($app->handle(foundationRequest('/missing'))->getStatusCode())->toBe(404)
            ->and($repository->hasResolvedSingleton(HttpExceptionLogger::class))->toBeTrue();
        expect($app->handle(foundationRequest('/auth-error'))->getStatusCode())->toBe(401)
            ->and($repository->hasResolvedSingleton(ExceptionRenderer::class))->toBeTrue();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('clears principal session and database state between HTTP execution scopes', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
Router::post('/testing/activate-contexts', static function (Application $app): Response {
    $app->make(CurrentPrincipalContext::class)->set(new Principal('principal-1'));
    $sessions = $app->make(SessionManager::class);
    $sessions->enter($sessions->open(null));
    $database = $app->make(DBLayerFactory::class)->connection();
    $database->begin();
    $database->statement('INSERT INTO worker_contexts (name) VALUES (?)', ['must-rollback']);
    return Response::json(['activated' => true], 202, ['X-Test-Context' => 'active']);
});
Router::get('/testing/context-status', static function (Application $app): Response {
    try {
        $app->make(SessionManager::class)->current();
        $sessionActive = true;
    } catch (LogicException) {
        $sessionActive = false;
    }
    $database = $app->make(DBLayerFactory::class)->connection();
    return Response::json([
        'principal' => $app->make(CurrentPrincipalContext::class)->get()?->id(),
        'session_active' => $sessionActive,
        'transaction_level' => $database->transactionLevel(),
        'rows' => $database->select('SELECT name FROM worker_contexts'),
    ]);
});
PHP,
    ]);

    try {
        $app = Foundation::web([
            'base_path' => $project,
            'database' => ['default' => 'testing', 'connections' => ['testing' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'session' => ['driver' => 'array'],
        ]);
        $app->make(DBLayerFactory::class)->connection()->statement(
            'CREATE TABLE worker_contexts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
        $client = $app->make(TestKit::class)->http()->withHeaders(['X-Test-Client' => 'foundation']);
        $client->post('/testing/activate-contexts', ['name' => 'request'])
            ->assertStatus(202)->assertHeader('X-Test-Context', 'active')->assertJson(['activated' => true]);
        $status = $client->get('/testing/context-status')
            ->assertStatus(200)
            ->assertJsonPath('principal', null)
            ->assertJsonPath('session_active', false)
            ->assertJsonPath('transaction_level', 0)
            ->assertJsonPath('rows', []);
        expect($status->json())->toMatchArray([
            'principal' => null, 'session_active' => false, 'transaction_level' => 0, 'rows' => [],
        ]);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('keeps optional auth adapters lazy until their capabilities are selected', function (): void {
    $project = foundationIntegrationProject([]);
    try {
        $app = Foundation::web([
            'base_path' => $project,
            'auth' => [
                'drivers' => [
                    'cache' => 'cache', 'ids' => 'uid', 'mfa' => 'otp', 'notifications' => 'talkingbytes',
                    'passkey' => 'webauthn', 'passwords' => 'security', 'storage' => 'database', 'tokens' => 'security',
                ],
                'webauthn' => ['origin' => 'https://example.test', 'rp_id' => 'example.test'],
            ],
        ]);
        $repository = $app->container()->getRepository();
        expect($app->make(AuthManager::class))->toBeInstanceOf(AuthManager::class);
        foreach ([
            AccessTokenServiceInterface::class, AccountProviderInterface::class, AuthIdGeneratorInterface::class,
            AuthNotifierInterface::class, CounterStoreInterface::class, MfaVerifierInterface::class,
            PasskeyServiceInterface::class, PasswordHasherInterface::class, TtlStoreInterface::class, WebAuthnRuntime::class,
        ] as $service) {
            expect($repository->hasResolvedSingleton($service))->toBeFalse();
        }
        expect($app->make(AuthServices::class)->passwordHasher())->toBeInstanceOf(PasswordHasherInterface::class)
            ->and($repository->hasResolvedSingleton(PasswordHasherInterface::class))->toBeTrue()
            ->and($repository->hasResolvedSingleton(AccessTokenServiceInterface::class))->toBeFalse();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('resolves auth actions and selected auth capabilities through DI only', function (): void {
    $app = Foundation::web(['auth' => ['drivers' => ['mfa' => 'simple']]]);
    $repository = $app->container()->getRepository();

    expect($app->make(AuthManager::class))->toBeInstanceOf(AuthManager::class)
        ->and($repository->hasResolvedSingleton(Authenticator::class))->toBeFalse()
        ->and($repository->hasResolvedSingleton(MfaManager::class))->toBeFalse()
        ->and($app->make(AuthActions::class))->toBeInstanceOf(AuthActions::class)
        ->and($repository->hasResolvedSingleton(Authenticator::class))->toBeFalse()
        ->and($app->make(AuthServices::class)->mfa())->toBeInstanceOf(MfaManager::class)
        ->and($repository->hasResolvedSingleton(MfaManager::class))->toBeTrue();
});

it('isolates current principals between concurrent fibers and restores failed request context', function (): void {
    $context = new CurrentPrincipalContext();
    $context->set(new Principal('main'));
    $first = new Fiber(static function () use ($context): string {
        $context->set(new Principal('first'));
        Fiber::suspend($context->require()->id());
        return $context->require()->id();
    });
    $second = new Fiber(static function () use ($context): ?string {
        $context->set(new Principal('second'));
        Fiber::suspend($context->require()->id());
        $context->clear();
        return $context->get()?->id();
    });
    expect($first->start())->toBe('first')->and($second->start())->toBe('second')->and($context->require()->id())->toBe('main');
    $first->resume();
    $second->resume();
    expect($first->getReturn())->toBe('first')->and($second->getReturn())->toBeNull()->and($context->require()->id())->toBe('main');

    $previous = new Principal('previous', accountId: 'previous');
    $resolved = new Principal('request', accountId: 'request');
    $context->set($previous);
    $resolver = new RequestPrincipalResolver(
        new ConfigRepository(['auth' => ['http' => ['principal_resolvers' => ['test']]]]),
        ['test' => new readonly class($resolved) implements PrincipalResolverInterface {
            public function __construct(private Principal $principal) {}
            public function name(): string { return 'test'; }
            public function resolve(Request $request): Principal
            {
                unset($request);

                return $this->principal;
            }
        }],
    );
    $middleware = new ResolvePrincipalMiddleware($context, $resolver);
    expect(fn() => $middleware(
        foundationRequest('/auth-failure'),
        static function (Request $request) use ($context): Response {
            unset($request);
            expect($context->require()->id())->toBe('request');
            throw new RuntimeException('handler failed');
        },
    ))->toThrow(RuntimeException::class, 'handler failed')
        ->and($context->require()->id())->toBe('previous');
});

it('does not build configured middleware aliases until a route uses them', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/without-cookie-middleware', static fn(): Response => Response::json(['ok' => true]));
Router::get('/with-cookie-middleware', static fn(): Response => Response::json(['ok' => true]), ['middleware' => ['encrypted-cookie']]);
PHP,
    ]);
    try {
        $app = Foundation::web([
            'base_path' => $project,
            'router' => ['middleware' => [
                'aliases' => ['encrypted-cookie' => 'cookie_encryption'],
                'definitions' => ['cookie_encryption' => [
                    'keys' => [str_repeat('k', 32)], 'store' => 'memory',
                ]],
            ]],
        ]);
        $repository = $app->container()->getRepository();
        expect(foundationJsonResponse($app->handle(foundationRequest('/without-cookie-middleware'))))->toBe(['ok' => true])
            ->and($repository->hasResolvedSingleton(CacheManager::class))->toBeFalse();
        expect(foundationJsonResponse($app->handle(foundationRequest('/with-cookie-middleware'))))->toBe(['ok' => true])
            ->and($repository->hasResolvedSingleton(CacheManager::class))->toBeTrue();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('registers only middleware aliases required by a warm route cache', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
Router::get('/cached-plain', static fn(): Response => Response::json(['ok' => true]));
Router::get('/cached-auth', static fn(): Response => Response::json(['ok' => true]), ['middleware' => ['auth']]);
PHP,
    ]);
    try {
        $config = ['base_path' => $project, '_config_cache' => false, 'router' => ['matcher' => 'fused', 'files' => ['web.php']]];
        $cli = Foundation::cli($config);
        (new RouteCacheManager($cli))->write('fused', RouteCachePath::for($cli->config()));
        $app = Foundation::web($config);
        $repository = $app->container()->getRepository();
        expect(foundationJsonResponse($app->handle(foundationRequest('/cached-plain'))))->toBe(['ok' => true])
            ->and(MiddlewareAliases::has('auth'))->toBeTrue()
            ->and(MiddlewareAliases::has('policy'))->toBeFalse()
            ->and(MiddlewareAliases::has('session'))->toBeFalse()
            ->and($repository->hasResolvedSingleton(AuthManager::class))->toBeFalse();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('boots every matcher from cache while preserving signed URL services', function (): void {
    foreach (['fused', 'generated', 'sharded'] as $matcher) {
        $project = foundationIntegrationProject([]);
        $options = [
            'base_path' => $project,
            '_config_cache' => false,
            'router' => [
                'matcher' => $matcher,
                'signed_urls' => ['key' => 'foundation-cache-signing-secret'],
            ],
        ];
        $cacheApplication = Foundation::cli($options);
        $config = $cacheApplication->config();
        try {
            WebrickRouteCache::build([
                'cache' => RouteCachePath::for($config),
                'matcher' => $matcher,
                'register' => static function (Registrar $router): void {
                    $router->get('/cached/{name}', 'foundationCachedRouteHandler', ['name' => 'cached.show']);
                },
                'signKey' => 'foundation-cache-signing-secret',
                'fallbackAliasesFromRegistrar' => false,
            ]);
            RouteCachePath::markFresh($config);

            $app = Foundation::web($options);
            expect(foundationJsonResponse($app->handle(foundationRequest('/cached/Codex'))))->toBe(['name' => 'Codex'])
                ->and(Route::signedUrlFor('cached.show', ['name' => 'Codex']))->toContain('/cached/Codex');
        } finally {
            foundationIntegrationRemoveDirectory($project);
        }
    }
});

it('applies definitions to string global middleware without recursively booting', function (): void {
    $project = foundationIntegrationProject([]);
    try {
        $app = Foundation::web([
            'base_path' => $project,
            'router' => ['middleware' => [
                'globals' => ['pre' => ['maintenance_mode', 'response_cache'], 'post' => []],
                'definitions' => [
                    'maintenance_mode' => ['file' => 'storage/framework/down'],
                    'response_cache' => ['enabled' => false],
                ],
            ]],
        ]);
        $middleware = $app->make(WebrickMiddlewareFactory::class)->preGlobal();
        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(MaintenanceModeMiddleware::class)
            ->and($app->booted())->toBeFalse();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

/** @param array<string,string> $files */
function foundationIntegrationProject(array $files): string
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-webrick-intermix-' . bin2hex(random_bytes(5));
    foreach ($files as $path => $contents) {
        $target = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $contents);
    }
    return $root;
}

function foundationCachedRouteHandler(string $name): Response
{
    return Response::json(['name' => $name]);
}

/** @return array<string,mixed> */
function foundationJsonResponse(Response $response): array
{
    $decoded = json_decode((string) $response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $query */
function foundationRequest(string $path, array $query = []): Request
{
    return Request::fake(
        query: $query,
        headers: ['Host' => 'example.test'],
        uri: 'https://example.test/' . ltrim($path, '/'),
    );
}

function foundationIntegrationRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foundationIntegrationRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}
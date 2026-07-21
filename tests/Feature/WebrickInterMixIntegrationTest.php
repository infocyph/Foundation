<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Facades\Route;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\WebrickMiddlewareFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Middleware\MaintenanceModeMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
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

it('applies InterMix environment bindings from the application environment', function (): void {
    $provider = new class extends ServiceProvider {
        public function register(Application $app): void
        {
            $app->container()
                ->options()
                ->bindInterfaceForEnv('local', FoundationTestGateway::class, LocalFoundationGateway::class)
                ->bindInterfaceForEnv('production', FoundationTestGateway::class, ProductionFoundationGateway::class);
        }
    };

    $app = Foundation::create([
        'app' => [
            'env' => 'local',
        ],
        'providers' => [$provider],
    ]);

    expect($app->make(FoundationTestGateway::class))->toBeInstanceOf(LocalFoundationGateway::class);
});

it('scopes request-lifetime services through the HTTP kernel', function (): void {
    $provider = new class extends ServiceProvider {
        public function register(Application $app): void
        {
            $app->container()->bind('scoped.probe', fn() => new stdClass(), LifetimeEnum::Scoped);
        }
    };

    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Facades\App;
use Infocyph\Webrick\Response\Response;

/** @var \Infocyph\Foundation\Routing\RouterManager $router */

$router->router()->get('/scope-check', static function (): Response {
    $app = App::instance();
    $first = $app->make('scoped.probe');
    $second = $app->make('scoped.probe');

    return Response::json([
        'first' => spl_object_id($first),
        'second' => spl_object_id($second),
    ]);
}, 'scope.check');
PHP,
    ]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'providers' => [$provider],
        ]);

        $first = foundationJsonResponse($app->handle(foundationRequest('/scope-check')));
        $second = foundationJsonResponse($app->handle(foundationRequest('/scope-check')));

        expect($first['first'])->toBe($first['second'])
            ->and($second['first'])->toBe($second['second'])
            ->and($first['first'])->not->toBe($second['first']);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('loads route files through Webrick facade declarations', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router as Route;

Route::get('/facade-route', static fn(): array => ['registered' => true], 'facade.route');
PHP,
    ]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
        ]);

        $routes = $app->boot()->router()->routes();
        $response = $app->handle(foundationRequest('/facade-route'));

        expect($routes->findByName('facade.route'))->not->toBeNull()
            ->and(foundationJsonResponse($response))->toBe(['registered' => true]);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('reuses one Webrick kernel for the application lifetime', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Webrick\Router\Facade\Router as Route;

Route::get('/kernel', static fn(): array => ['ok' => true]);
PHP,
    ]);

    try {
        $app = Foundation::create(['base_path' => $project])->boot();
        $router = $app->router();

        expect($router->kernel())->toBe($router->kernel());
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('keeps unrelated subsystems deferred for plain routes', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/lean', static fn(): Response => Response::json(['ok' => true]));
PHP,
    ]);

    try {
        $app = Foundation::create(['base_path' => $project]);

        expect($app->container()->has(AuthManager::class))->toBeFalse()
            ->and($app->container()->has(CacheManager::class))->toBeFalse()
            ->and($app->has(AuthManager::class))->toBeTrue()
            ->and(foundationJsonResponse($app->handle(foundationRequest('/lean'))))->toBe(['ok' => true])
            ->and($app->container()->has(AuthManager::class))->toBeFalse()
            ->and($app->container()->has(CacheManager::class))->toBeFalse();

        expect($app->cache())->toBeInstanceOf(CacheManager::class)
            ->and($app->container()->has(CacheManager::class))->toBeTrue();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('does not build configured middleware aliases until a route uses them', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/without-cookie-middleware', static fn(): Response => Response::json(['ok' => true]));
Router::get('/with-cookie-middleware', static fn(): Response => Response::json(['ok' => true]), [
    'middleware' => ['encrypted-cookie'],
]);
PHP,
    ]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'router' => [
                'middleware' => [
                    'aliases' => ['encrypted-cookie' => 'cookie_encryption'],
                    'definitions' => [
                        'cookie_encryption' => [
                            'keys' => [str_repeat('k', 32)],
                            'store' => 'memory',
                        ],
                    ],
                ],
            ],
        ]);

        expect(foundationJsonResponse($app->handle(foundationRequest('/without-cookie-middleware'))))
            ->toBe(['ok' => true])
            ->and($app->container()->has(CacheManager::class))->toBeFalse();

        expect(foundationJsonResponse($app->handle(foundationRequest('/with-cookie-middleware'))))
            ->toBe(['ok' => true])
            ->and($app->container()->has(CacheManager::class))->toBeTrue();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('activates auth only when auth middleware is dispatched', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/protected', static fn(): Response => Response::json(['ok' => true]), [
    'middleware' => ['auth'],
]);
PHP,
    ]);

    try {
        $app = Foundation::create(['base_path' => $project]);
        expect($app->container()->has(AuthManager::class))->toBeFalse();

        $response = $app->handle(foundationRequest('/protected'));

        expect($response->getStatusCode())->toBe(401)
            ->and($app->container()->has(AuthManager::class))->toBeTrue();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('does not consume an existing route cache when routing cache is disabled', function (): void {
    $project = foundationIntegrationProject([
        'bootstrap/cache/routes/fused.php' => "<?php\n\nreturn [];\n",
        'routes/web.php' => <<<'PHP'
<?php

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/source-route', static fn(): Response => Response::json(['source' => true]));
PHP,
    ]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'router' => ['cache' => false],
        ]);

        expect(foundationJsonResponse($app->handle(foundationRequest('/source-route'))))
            ->toBe(['source' => true]);
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('boots every matcher from cache while preserving signed URL services', function (): void {
    foreach (['fused', 'generated', 'sharded'] as $matcher) {
        $project = foundationIntegrationProject([]);
        $config = new ConfigRepository([
            'app' => ['base_path' => $project],
            'router' => ['matcher' => $matcher],
        ]);

        try {
            WebrickRouteCache::build([
                'cache' => RouteCachePath::for($config),
                'matcher' => $matcher,
                'register' => static function (Registrar $router): void {
                    $router->get('/cached/{name}', 'foundationCachedRouteHandler', [
                        'name' => 'cached.show',
                    ]);
                },
                'signKey' => 'foundation-cache-signing-secret',
                'fallbackAliasesFromRegistrar' => false,
            ]);

            $app = Foundation::create([
                'base_path' => $project,
                'router' => [
                    'matcher' => $matcher,
                    'signed_urls' => ['key' => 'foundation-cache-signing-secret'],
                ],
            ]);

            expect(foundationJsonResponse($app->handle(foundationRequest('/cached/Codex'))))
                ->toBe(['name' => 'Codex'])
                ->and(Route::signedUrlFor('cached.show', ['name' => 'Codex']))
                ->toContain('/cached/Codex');
        } finally {
            foundationIntegrationRemoveDirectory($project);
        }
    }
});

it('applies definitions to string global middleware without recursively booting', function (): void {
    $project = foundationIntegrationProject([]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'router' => [
                'middleware' => [
                    'globals' => [
                        'pre' => ['maintenance_mode', 'response_cache'],
                        'post' => [],
                    ],
                    'definitions' => [
                        'maintenance_mode' => [
                            'file' => 'storage/framework/down',
                        ],
                        'response_cache' => [
                            'enabled' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $middleware = $app->make(WebrickMiddlewareFactory::class)->preGlobal();

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(MaintenanceModeMiddleware::class)
            ->and($app->booted())->toBeFalse();
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('discovers attribute routes and exposes Webrick URL generation services', function (): void {
    $project = foundationIntegrationProject([]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'router' => [
                'expose_url_services' => true,
                'attributes' => [
                    'enabled' => true,
                    'directories' => [
                        'Infocyph\\Foundation\\Tests\\Fixtures\\' => __DIR__ . '/../Fixtures',
                    ],
                ],
            ],
        ]);

        $routes = $app->boot()->router()->routes();

        expect($routes->findByName('attribute.hello'))->not->toBeNull();

        $response = $app->handle(foundationRequest('/attribute-hello/Codex'));

        expect(foundationJsonResponse($response)['hello'])->toBe('Codex')
            ->and(Route::urlFor('attribute.hello', ['name' => 'Codex']))->toBe('/attribute-hello/Codex');
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

it('wires Webrick global middleware and signed-url aliases through router config', function (): void {
    $project = foundationIntegrationProject([
        'routes/web.php' => <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;

/** @var \Infocyph\Foundation\Routing\RouterManager $router */

$router->router()->get('/signed/files/{file}', static fn(string $file): Response => Response::json([
    'file' => $file,
]), [
    'name' => 'signed.files.show',
    'middleware' => ['signed'],
]);

$router->router()->get('/telemetry', static fn(): Response => Response::json(['ok' => true]), 'telemetry.show');
PHP,
    ]);

    try {
        $app = Foundation::create([
            'base_path' => $project,
            'router' => [
                'expose_url_services' => true,
                'url_base_uri' => 'https://example.test',
                'signed_urls' => [
                    'key' => 'integration-signing-key',
                    'default_ttl' => 900,
                ],
                'middleware' => [
                    'globals' => [
                        'pre' => ['telemetry'],
                        'post' => [],
                    ],
                ],
            ],
        ]);

        $signedUrl = Route::signedUrlFor('signed.files.show', ['file' => 'report.pdf']);
        $parts = parse_url($signedUrl);
        parse_str($parts['query'] ?? '', $query);

        $signedResponse = $app->handle(foundationRequest(
            path: $parts['path'] ?? '/signed/files/report.pdf',
            query: $query,
        ));
        $telemetryResponse = $app->handle(foundationRequest('/telemetry'));

        expect(foundationJsonResponse($signedResponse)['file'])->toBe('report.pdf')
            ->and($telemetryResponse->getHeaderLine('X-Request-Id'))->not->toBe('');
    } finally {
        foundationIntegrationRemoveDirectory($project);
    }
});

/**
 * @param array<string, string> $files
 */
function foundationIntegrationProject(array $files): string
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'foundation-webrick-intermix-'
        . bin2hex(random_bytes(5));

    foreach ($files as $path => $contents) {
        $target = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($target, $contents);
    }

    return $root;
}

function foundationCachedRouteHandler(string $name): Response
{
    return Response::json(['name' => $name]);
}

/**
 * @return array<string, mixed>
 */
function foundationJsonResponse(Response $response): array
{
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $query
 */
function foundationRequest(string $path, array $query = []): Request
{
    $normalized = '/' . ltrim($path, '/');

    return Request::fake(
        query: $query,
        headers: ['Host' => 'example.test'],
        uri: 'https://example.test' . $normalized,
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
        if (is_dir($path)) {
            foundationIntegrationRemoveDirectory($path);

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}

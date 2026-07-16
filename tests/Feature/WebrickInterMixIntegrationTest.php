<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Facades\Route;
use Infocyph\Foundation\Foundation;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

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
    $provider = new class extends ServiceProvider
    {
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
    $provider = new class extends ServiceProvider
    {
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
                'webrick' => [
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

<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\RouteCapability;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;

final class FoundationCompiledReleaseHandler
{
    public function __invoke(): Response
    {
        return Response::json(['compiled' => true]);
    }
}

final class FoundationCompiledReleaseFailureHandler
{
    public function __invoke(): never
    {
        throw new RuntimeException('sensitive-foundation-runtime-failure');
    }
}

final class FoundationCompiledReleaseMiddleware
{
    public function __invoke(Request $request, Closure $next): Response
    {
        unset($request);

        return $next();
    }
}

function foundationWebReleasePlainHandler(): Response
{
    return Response::json(['plain' => true]);
}

it('compiles and boots a trusted route-first Webrick release without live router construction', function (): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $log = $project . '/storage/logs/web-release.log';
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'logging' => [
            'driver' => 'file',
            'level' => 'warning',
            'path' => $log,
            'exceptions' => [
                'include_message' => false,
                'include_trace' => false,
            ],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => ['pre' => [], 'post' => []],
            ],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: [],
        );
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;

        expect($trustedSha256)->toBeString()
            ->and($trustedSha256)->toMatch('/^[a-f0-9]{64}$/D')
            ->and($release['intermix']['skipped'] ?? null)->toBe([]);

        $compiled = realpath($intermix);
        expect($compiled)->toBeString();
        $metadata = json_decode(
            (string) file_get_contents(dirname($compiled) . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        expect($metadata['fallback']['required'] ?? null)->toBeFalse()
            ->and($metadata['fallback']['identity_required'] ?? null)->toBeFalse();

        $payload = require $router;
        expect($payload)->toBeArray();
        $artifact = CompiledRouterArtifact::fromPayload($payload);
        $plansByPath = [];
        foreach ($artifact->routes() as $route) {
            $plansByPath[$route->getPath()] = $artifact->planForIndex($route->getIndex());
        }

        $plainPlan = $plansByPath['/plain'] ?? null;
        expect($plainPlan)->not->toBeNull()
            ->and($plainPlan->requiresRequest())->toBeFalse()
            ->and($plainPlan->requiresScope())->toBeFalse()
            ->and(RouteCapability::has($plainPlan->capabilities, RouteCapability::MIDDLEWARE))->toBeFalse();

        $middlewarePlan = $plansByPath['/middleware'] ?? null;
        expect($middlewarePlan)->not->toBeNull()
            ->and($middlewarePlan->requiresRequest())->toBeTrue()
            ->and($middlewarePlan->requiresScope())->toBeTrue()
            ->and(RouteCapability::has($middlewarePlan->capabilities, RouteCapability::MIDDLEWARE))->toBeTrue();

        $runtime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $trustedSha256,
            foundationCapabilities: [],
        );
        expect(UrlGeneratorRegistry::frozen())->toBeTrue()
            ->and(UrlGeneratorRegistry::get()->urlFor('plain.show'))->toBe('/plain');

        $response = $runtime->kernel->handle(Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/compiled',
        ));
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('{"compiled":true}');

        $missing = $runtime->kernel->handle(Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/missing',
        ));
        expect($missing->getStatusCode())->toBe(404);

        $methodNotAllowed = $runtime->kernel->handle(Request::fake(
            method: 'POST',
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/compiled',
        ));
        expect($methodNotAllowed->getStatusCode())->toBe(405)
            ->and($methodNotAllowed->getHeaderLine('Allow'))->toContain('GET');

        $failure = $runtime->kernel->handle(Request::fake(
            headers: ['Accept' => 'application/json', 'Host' => 'release.test'],
            uri: 'https://release.test/failure',
        ));
        expect($failure->getStatusCode())->toBe(500)
            ->and((string) $failure->getBody())->toContain('Internal Server Error')
            ->and((string) $failure->getBody())->not->toContain('sensitive-foundation-runtime-failure')
            ->and(is_file($log))->toBeTrue();
        $logged = file_get_contents($log);
        expect($logged)->toBeString()
            ->and($logged)->toContain(RuntimeException::class)
            ->and($logged)->not->toContain('sensitive-foundation-runtime-failure');

        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            str_repeat('0', 64),
            foundationCapabilities: [],
        ))->toThrow(RuntimeException::class, 'trust identity mismatch');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('compiles maintenance as an opt-in Webrick gate with bounded runtime refresh', function (): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $maintenancePath = $project . '/storage/framework/maintenance.json';
    mkdir(dirname($maintenancePath), 0777, true);
    file_put_contents($maintenancePath, json_encode([
        'enabled' => true,
        'enabled_at' => gmdate(DATE_ATOM),
        'retry_after' => 17,
        'message' => 'Foundation maintenance window.',
    ], JSON_THROW_ON_ERROR));

    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'operations' => [
            'maintenance' => [
                'driver' => 'file',
                'path' => 'storage/framework/maintenance.json',
                'refresh_milliseconds' => 0,
                'retry_after' => 3600,
                'web' => ['enabled' => true],
            ],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => ['pre' => [], 'post' => []],
            ],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: [],
        );
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;
        expect($trustedSha256)->toBeString()
            ->and($release['intermix']['skipped'] ?? null)->toBe([]);

        $payload = require $router;
        expect($payload)->toBeArray();
        $artifact = CompiledRouterArtifact::fromPayload($payload);
        $plainPlan = null;
        foreach ($artifact->routes() as $route) {
            if ($route->getPath() === '/plain') {
                $plainPlan = $artifact->planForIndex($route->getIndex());
                break;
            }
        }

        expect($plainPlan)->not->toBeNull()
            ->and($plainPlan->requiresRequest())->toBeFalse()
            ->and($plainPlan->requiresScope())->toBeFalse()
            ->and(RouteCapability::has($plainPlan->capabilities, RouteCapability::MIDDLEWARE))->toBeFalse()
            ->and($artifact->hasGlobalMiddleware())->toBeTrue();

        $runtime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $trustedSha256,
            foundationCapabilities: [],
        );
        $request = Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/plain',
        );
        $maintenance = $runtime->kernel->handle($request);
        expect($maintenance->getStatusCode())->toBe(503)
            ->and($maintenance->getHeaderLine('Retry-After'))->toBe('17');

        unlink($maintenancePath);
        $available = $runtime->kernel->handle($request);
        expect($available->getStatusCode())->toBe(200)
            ->and((string) $available->getBody())->toBe('{"plain":true}');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

function foundationWebReleaseProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-web-release-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/plain', 'foundationWebReleasePlainHandler', ['name' => 'plain.show']);
Router::get('/middleware', 'foundationWebReleasePlainHandler', [
    'name' => 'middleware.show',
    'middleware' => [FoundationCompiledReleaseMiddleware::class],
]);
Router::get('/compiled', FoundationCompiledReleaseHandler::class, ['name' => 'compiled.show']);
Router::get('/failure', FoundationCompiledReleaseFailureHandler::class);
PHP);

    return $project;
}

function foundationWebReleaseRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

it('boots an explicitly compiled Runwire web host through native Webrick and InterMix', function (): void {
    $project = foundationWebReleaseProject();
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $manifest = $project . '/bootstrap/cache/release.json';
    $capabilities = ['runwire'];
    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: $capabilities,
        );
        expect($release['intermix']['skipped'] ?? null)->toBe([]);

        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $release['release_runtime_manifest_sha256'],
            foundationCapabilities: $capabilities,
        );
        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $host = $loaded->runwireApplication($runtime);

        expect($host)->toBeInstanceOf(\Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication::class);
        $host->start();
        expect($host->healthy())->toBeTrue();
        $host->shutdown();
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('refuses implicit Runwire host construction from a plain PHP web release', function (): void {
    $project = foundationWebReleaseProject();
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $manifest = $project . '/bootstrap/cache/release.json';
    try {
        $release = new WebReleaseCompiler()->compile($config, $intermix, $router, $manifest, []);
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        );

        expect(fn(): mixed => $loaded->runwireApplication(\Infocyph\Runwire\RuntimeContext::standalone()))
            ->toThrow(LogicException::class, 'runwire capability');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

final class FoundationRunwireReleaseContextHandler
{
    public static function show(
        \Infocyph\Runwire\RuntimeContext $runtime,
        \Infocyph\Runwire\RequestContext $runwireRequest,
        \Infocyph\Webrick\Request\Request $request,
    ): \Infocyph\Webrick\Response\Response {
        return \Infocyph\Webrick\Response\Response::json([
            'mode' => $runtime->mode,
            'request' => $runwireRequest->requestId,
            'path' => $request->getUri()->getPath(),
        ]);
    }
}

it('sends the exact host Runwire request through compiled Webrick and native InterMix', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-context', [FoundationRunwireReleaseContextHandler::class, 'show']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'logging' => [
            'driver' => 'file',
            'level' => 'debug',
            'path' => $project . '/storage/logs/runwire-diagnostic.log',
            'exceptions' => ['include_message' => true, 'include_trace' => false],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire'],
        );
        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $request = \Infocyph\Runwire\RequestContext::create($runtime, requestId: 'foundation-native-request');
        $native = new \Infocyph\Runwire\Http\HttpRequest(
            method: 'GET',
            target: '/runwire-context',
            version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
            headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
            body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
            context: $request,
        );
        $writer = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
        $host = $loaded->runwireApplication($runtime);
        $host->start();

        try {
            $host->handle($native, $writer, completeResponse: true);
            if ($writer->status !== 200) {
                $logPath = $project . '/storage/logs/runwire-diagnostic.log';
                $diagnostics = is_file($logPath) ? (string) file_get_contents($logPath) : '(no log file)';

                throw new RuntimeException('Compiled Runwire request failed: ' . $writer->body . ' ' . $diagnostics);
            }

            expect($writer->status)->toBe(200)
                ->and($writer->endCalls)->toBe(1)
                ->and($request->completed())->toBeTrue()
                ->and(json_decode($writer->body, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'mode' => $runtime->mode,
                    'request' => 'foundation-native-request',
                    'path' => '/runwire-context',
                ]);
        } finally {
            $host->shutdown();
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

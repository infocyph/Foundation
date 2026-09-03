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
            'middleware' => [
                'globals' => ['pre' => [], 'post' => []],
            ],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile($config, $intermix, $router, $manifest);
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;

        expect($trustedSha256)->toBeString()
            ->and($trustedSha256)->toMatch('/^[a-f0-9]{64}$/D');

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

        $runtime = WebReleaseRuntime::loadPrevalidated($config, $manifest, $trustedSha256);
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

        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            str_repeat('0', 64),
        ))->toThrow(RuntimeException::class, 'trust identity mismatch');
    } finally {
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

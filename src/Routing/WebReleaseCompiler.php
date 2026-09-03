<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Router\Build\ReleaseCompiler as WebrickReleaseCompiler;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Definition\Registrar;

/** Coordinates the Foundation web graph through Webrick's single release compiler. */
final readonly class WebReleaseCompiler
{
    public function __construct(
        private WebGraphFactory $graphs = new WebGraphFactory(),
        private WebrickReleaseCompiler $compiler = new WebrickReleaseCompiler(),
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function compile(
        array $config,
        string $intermixPath,
        string $routerPath,
        string $releaseManifestPath,
    ): array {
        $graph = $this->graphs->compose($config);
        $settings = new WebReleaseConfiguration($graph);
        $settings->assertArtifactSafeMiddleware();

        $intermixPath = $settings->resolvePath($intermixPath);
        $routerPath = $settings->resolvePath($routerPath);
        $releaseManifestPath = $settings->resolvePath($releaseManifestPath);

        $release = $this->compiler->compile(
            builder: $graph->builder,
            register: static function (Registrar $registrar) use ($graph): void {
                $graph->application->make(RouteFileLoader::class)->load($registrar);
            },
            environment: $settings->environment(),
            configFingerprint: $settings->configFingerprint(),
            intermixPath: $intermixPath,
            routerPath: $routerPath,
            releaseManifestPath: $releaseManifestPath,
            registrarOptions: $settings->registrarOptions(),
            preGlobal: [],
            postGlobal: [],
            preGlobalTags: [],
            postGlobalTags: [],
            enrichGraph: static function (ContainerBuilder $builder, RouterBuildResult $routes): void {
                new WebProductionGraph()->prepareBuild($builder, $routes);
            },
        );

        $runtimeManifestPath = WebrickReleaseCompiler::runtimeManifestPath($releaseManifestPath);
        $runtimeManifestSha256 = hash_file('sha256', $runtimeManifestPath);
        if (!is_string($runtimeManifestSha256)) {
            throw new \RuntimeException('Unable to fingerprint the compiled Foundation web release manifest.');
        }

        // This value is returned to deployment tooling but is deliberately not
        // written into the release manifest whose identity it authenticates.
        $release['release_runtime_manifest_sha256'] = $runtimeManifestSha256;

        return $release;
    }
}

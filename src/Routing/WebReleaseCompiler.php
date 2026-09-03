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
     * @param array<int|string, mixed>|null $capabilities Null preserves installed-package discovery; [] is minimal.
     * @return array<string, mixed>
     */
    public function compile(
        array $config,
        string $intermixPath,
        string $routerPath,
        string $releaseManifestPath,
        ?array $capabilities = null,
    ): array {
        $graph = $this->graphs->compose($config, $capabilities);
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
            preGlobal: $settings->preGlobal(),
            postGlobal: [],
            preGlobalTags: [],
            postGlobalTags: [],
            enrichGraph: static function (ContainerBuilder $builder, RouterBuildResult $routes): void {
                new WebProductionGraph()->prepareBuild($builder, $routes);
            },
        );
        $this->assertNoSkippedDefinitions($release);

        $runtimeManifestPath = WebrickReleaseCompiler::runtimeManifestPath($releaseManifestPath);
        $runtimeManifestSha256 = hash_file('sha256', $runtimeManifestPath);
        if (!is_string($runtimeManifestSha256)) {
            throw new \RuntimeException('Unable to fingerprint the compiled Foundation web release manifest.');
        }

        // Returned to trusted deployment tooling, never written into the Webrick
        // manifest whose exact runtime representation it authenticates.
        $release['release_runtime_manifest_sha256'] = $runtimeManifestSha256;
        $release['foundation_capabilities'] = $graph->context->capabilities;

        return $release;
    }

    /** @param array<string, mixed> $release */
    private function assertNoSkippedDefinitions(array $release): void
    {
        $intermix = $release['intermix'] ?? null;
        $skipped = is_array($intermix) ? ($intermix['skipped'] ?? null) : null;
        if (!is_array($skipped)) {
            throw new \UnexpectedValueException('Foundation web release is missing the InterMix skipped-definition report.');
        }
        if ($skipped === []) {
            return;
        }

        $details = [];
        foreach ($skipped as $id => $reason) {
            $details[] = sprintf(
                '%s: %s',
                is_string($id) ? $id : (string) $id,
                is_string($reason) ? $reason : 'unknown static-compilation failure',
            );
        }

        throw new \RuntimeException(
            'Foundation web release contains definitions that were not statically compiled: '
            . implode('; ', $details),
        );
    }
}

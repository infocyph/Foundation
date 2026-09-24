<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\LoadedReleaseGeneration;
use Infocyph\Foundation\Runtime\ReleaseGenerationLease;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;

/** Process-boot loader for the active immutable Foundation generation. */
final readonly class FoundationReleaseRuntime
{
    public function __construct(private ActiveGeneration $active = new ActiveGeneration()) {}

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function nonWeb(
        array $config,
        RuntimeMode $runtime,
        string $releaseRoot,
    ): GeneratedRuntime {
        unset($config);
        $this->assertNonWeb($runtime);
        [$generation, $manifest, $directory, , $lease] = $this->activeManifest($releaseRoot);
        $section = FoundationReleaseManifest::section($manifest, $runtime->value);
        $loaded = GeneratedRuntime::loadRelease(
            $runtime,
            $directory . DIRECTORY_SEPARATOR . $this->relative($section['intermix_path'] ?? null),
            FoundationReleaseManifest::nonEmptyString($manifest['environment'] ?? null, 'environment'),
            FoundationReleaseManifest::digest(
                $manifest['config_fingerprint'] ?? null,
                32,
                'config_fingerprint',
            ),
            FoundationReleaseManifest::digest(
                $section['digest'] ?? null,
                32,
                $runtime->value . '.digest',
            ),
            FoundationReleaseManifest::capabilities(
                $section['capabilities'] ?? null,
                $runtime->value . '.capabilities',
            ),
        );

        return $this->attachGeneration($loaded, $releaseRoot, $generation, $lease);
    }

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function nonWebPrevalidated(
        array $config,
        RuntimeMode $runtime,
        string $releaseRoot,
        string $trustedFoundationManifestSha256,
    ): GeneratedRuntime {
        unset($config);
        $this->assertNonWeb($runtime);
        [$generation, $manifest, $directory, , $lease] = $this->trustedActiveManifestWithLease(
            $releaseRoot,
            $trustedFoundationManifestSha256,
        );
        $section = FoundationReleaseManifest::section($manifest, $runtime->value);
        $loaded = GeneratedRuntime::loadPrevalidated(
            [],
            $runtime,
            $directory . DIRECTORY_SEPARATOR . $this->relative($section['intermix_path'] ?? null),
            FoundationReleaseManifest::digest(
                $section['metadata_sha256'] ?? null,
                64,
                $runtime->value . '.metadata_sha256',
            ),
            FoundationReleaseManifest::digest(
                $section['digest'] ?? null,
                32,
                $runtime->value . '.digest',
            ),
            FoundationReleaseManifest::capabilities(
                $section['capabilities'] ?? null,
                $runtime->value . '.capabilities',
            ),
            FoundationReleaseManifest::nonEmptyString($manifest['environment'] ?? null, 'environment'),
            FoundationReleaseManifest::digest(
                $manifest['config_fingerprint'] ?? null,
                32,
                'config_fingerprint',
            ),
        );

        return $this->attachGeneration(
            $loaded,
            $releaseRoot,
            $generation,
            $lease,
            strtolower(trim($trustedFoundationManifestSha256)),
        );
    }

    /** @return array{0:string,1:array<string,mixed>,2:string,3:string} */
    public function trustedActiveManifest(string $releaseRoot, string $trustedSha256): array
    {
        [$generation, $manifest, $directory, $manifestPath] = $this->trustedActiveManifestWithLease(
            $releaseRoot,
            $trustedSha256,
        );

        return [$generation, $manifest, $directory, $manifestPath];
    }

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function web(
        array $config,
        string $releaseRoot,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        unset($config);
        [$generation, $manifest, $directory, , $lease] = $this->activeManifest($releaseRoot);
        $web = FoundationReleaseManifest::section($manifest, 'web');
        $runtime = WebReleaseRuntime::loadCompiled(
            $this->releaseConfig($manifest, $directory),
            $directory . DIRECTORY_SEPARATOR . $this->relative($web['release_manifest'] ?? null),
            $adapter,
            FoundationReleaseManifest::capabilities($web['capabilities'] ?? null, 'web.capabilities'),
            $this->matcherCachePath($web, $directory),
        );

        return $this->attachWebGeneration($runtime, $releaseRoot, $generation, $lease);
    }

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function webPrevalidated(
        array $config,
        string $releaseRoot,
        string $trustedFoundationManifestSha256,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        unset($config);
        [$generation, $manifest, $directory, , $lease] = $this->trustedActiveManifestWithLease(
            $releaseRoot,
            $trustedFoundationManifestSha256,
        );
        $web = FoundationReleaseManifest::section($manifest, 'web');
        $runtime = WebReleaseRuntime::loadPrevalidatedCompiled(
            $this->releaseConfig($manifest, $directory),
            $directory . DIRECTORY_SEPARATOR . $this->relative($web['release_manifest'] ?? null),
            FoundationReleaseManifest::digest(
                $web['runtime_manifest_sha256'] ?? null,
                64,
                'web.runtime_manifest_sha256',
            ),
            $adapter,
            FoundationReleaseManifest::capabilities($web['capabilities'] ?? null, 'web.capabilities'),
            $this->matcherCachePath($web, $directory),
        );

        return $this->attachWebGeneration(
            $runtime,
            $releaseRoot,
            $generation,
            $lease,
            strtolower(trim($trustedFoundationManifestSha256)),
        );
    }

    /** @return array{0:string,1:array<string,mixed>,2:string,3:string,4:ReleaseGenerationLease} */
    private function activeManifest(string $releaseRoot): array
    {
        $current = $this->active->current($releaseRoot);
        $generation = $current['generation'];
        $lease = ReleaseGenerationLease::acquireShared($releaseRoot, $generation);
        $manifestPath = $current['manifest'];

        try {
            $manifest = FoundationReleaseManifest::load($manifestPath);
            $expectedDependencies = FoundationReleaseManifest::digest(
                $manifest['dependency_fingerprint'] ?? null,
                32,
                'dependency_fingerprint',
            );
            if (!hash_equals($expectedDependencies, FoundationReleaseManifest::dependencyFingerprint())) {
                throw new \RuntimeException(
                    'Foundation generation dependency identity does not match the current Composer installation.',
                );
            }
        } catch (\Throwable $exception) {
            $lease->release();

            throw $exception;
        }

        return [$generation, $manifest, dirname($manifestPath), $manifestPath, $lease];
    }

    private function assertNonWeb(RuntimeMode $runtime): void
    {
        if ($runtime === RuntimeMode::Web) {
            throw new \InvalidArgumentException('Web runtime must use the coordinated Webrick release loader.');
        }
    }

    private function attachGeneration(
        GeneratedRuntime $runtime,
        string $releaseRoot,
        string $generation,
        ReleaseGenerationLease $lease,
        ?string $trustedFoundationManifestSha256 = null,
    ): GeneratedRuntime {
        $runtime->application->attachLoadedReleaseGeneration(new LoadedReleaseGeneration(
            $releaseRoot,
            $generation,
            $trustedFoundationManifestSha256,
            $lease,
        ));

        return $runtime;
    }

    private function attachWebGeneration(
        WebReleaseRuntime $runtime,
        string $releaseRoot,
        string $generation,
        ReleaseGenerationLease $lease,
        ?string $trustedFoundationManifestSha256 = null,
    ): WebReleaseRuntime {
        return new WebReleaseRuntime(
            $runtime->container,
            $runtime->kernel,
            $runtime->server,
            $runtime->capabilities,
            new LoadedReleaseGeneration(
                $releaseRoot,
                $generation,
                $trustedFoundationManifestSha256,
                $lease,
            ),
        );
    }

    /** @param array<string,mixed> $web */
    private function matcherCachePath(array $web, string $directory): ?string
    {
        $path = $web['matcher_cache_path'] ?? null;
        $sha256 = $web['matcher_cache_sha256'] ?? null;
        if ($path === null && $sha256 === null) {
            return null;
        }
        if (!is_string($path) || !is_string($sha256)) {
            throw new \UnexpectedValueException('Foundation web matcher cache metadata is incomplete.');
        }

        $absolute = $directory . DIRECTORY_SEPARATOR . $this->relative($path);
        FoundationReleaseTreeDigest::assertMatches(
            $absolute,
            FoundationReleaseManifest::digest($sha256, 64, 'web.matcher_cache_sha256'),
        );

        return $absolute;
    }

    private function relative(mixed $path): string
    {
        return str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            FoundationReleaseManifest::relativePath($path, 'runtime path'),
        );
    }

    /** @param array<string,mixed> $manifest */
    private function releaseConfig(array $manifest, string $directory): ConfigRepository
    {
        return FoundationReleaseConfig::load(
            $directory . DIRECTORY_SEPARATOR . $this->relative($manifest['config_path'] ?? null),
            FoundationReleaseManifest::digest($manifest['config_sha256'] ?? null, 64, 'config_sha256'),
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:string,3:string,4:ReleaseGenerationLease}
     */
    private function trustedActiveManifestWithLease(string $releaseRoot, string $trustedSha256): array
    {
        $trustedSha256 = strtolower(trim($trustedSha256));
        if (preg_match('/^[a-f0-9]{64}$/D', $trustedSha256) !== 1) {
            throw new \InvalidArgumentException('Trusted Foundation generation manifest SHA-256 is invalid.');
        }

        [$generation, $manifest, $directory, $manifestPath, $lease] = $this->activeManifest($releaseRoot);
        $actualSha256 = hash_file('sha256', $manifestPath);
        if (!is_string($actualSha256) || !hash_equals($trustedSha256, $actualSha256)) {
            $lease->release();

            throw new \RuntimeException('Foundation generation manifest trust identity mismatch.');
        }

        return [$generation, $manifest, $directory, $manifestPath, $lease];
    }
}

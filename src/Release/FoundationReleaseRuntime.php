<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\LoadedReleaseGeneration;
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
        [$generation, $manifest, $directory] = $this->activeManifest($releaseRoot);
        $section = FoundationReleaseManifest::section($manifest, $runtime->value);
        $loaded = GeneratedRuntime::loadRelease(
            $runtime,
            $directory . DIRECTORY_SEPARATOR . $this->relative($section['intermix_path'] ?? null),
            FoundationReleaseManifest::nonEmptyString($manifest['environment'] ?? null, 'environment'),
            FoundationReleaseManifest::digest(
                $manifest['config_fingerprint'] ?? null,
                64,
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

        return $this->attachGeneration($loaded, $releaseRoot, $generation);
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
        [$generation, $manifest, $directory] = $this->trustedActiveManifest(
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
                64,
                'config_fingerprint',
            ),
        );

        return $this->attachGeneration(
            $loaded,
            $releaseRoot,
            $generation,
            strtolower(trim($trustedFoundationManifestSha256)),
        );
    }

    /** @return array{0:string,1:array<string,mixed>,2:string,3:string} */
    public function trustedActiveManifest(string $releaseRoot, string $trustedSha256): array
    {
        $trustedSha256 = strtolower(trim($trustedSha256));
        if (preg_match('/^[a-f0-9]{64}$/D', $trustedSha256) !== 1) {
            throw new \InvalidArgumentException('Trusted Foundation generation manifest SHA-256 is invalid.');
        }

        [$generation, $manifest, $directory, $manifestPath] = $this->activeManifest($releaseRoot);
        $actualSha256 = hash_file('sha256', $manifestPath);
        if (!is_string($actualSha256) || !hash_equals($trustedSha256, $actualSha256)) {
            throw new \RuntimeException('Foundation generation manifest trust identity mismatch.');
        }

        return [$generation, $manifest, $directory, $manifestPath];
    }

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function web(
        array $config,
        string $releaseRoot,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        unset($config);
        [, $manifest, $directory] = $this->activeManifest($releaseRoot);
        $web = FoundationReleaseManifest::section($manifest, 'web');

        return WebReleaseRuntime::loadCompiled(
            $this->releaseConfig($manifest, $directory),
            $directory . DIRECTORY_SEPARATOR . $this->relative($web['release_manifest'] ?? null),
            $adapter,
            FoundationReleaseManifest::capabilities($web['capabilities'] ?? null, 'web.capabilities'),
        );
    }

    /** @param array<string,mixed> $config Retained for the public bootstrap contract; release inputs own runtime config. */
    public function webPrevalidated(
        array $config,
        string $releaseRoot,
        string $trustedFoundationManifestSha256,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        unset($config);
        [, $manifest, $directory] = $this->trustedActiveManifest(
            $releaseRoot,
            $trustedFoundationManifestSha256,
        );
        $web = FoundationReleaseManifest::section($manifest, 'web');

        return WebReleaseRuntime::loadPrevalidatedCompiled(
            $this->releaseConfig($manifest, $directory),
            $directory . DIRECTORY_SEPARATOR . $this->relative($web['release_manifest'] ?? null),
            FoundationReleaseManifest::digest(
                $web['runtime_manifest_sha256'] ?? null,
                64,
                'web.runtime_manifest_sha256',
            ),
            $adapter,
            FoundationReleaseManifest::capabilities($web['capabilities'] ?? null, 'web.capabilities'),
        );
    }

    /** @return array{0:string,1:array<string,mixed>,2:string,3:string} */
    private function activeManifest(string $releaseRoot): array
    {
        $current = $this->active->current($releaseRoot);
        $manifestPath = $current['manifest'];
        $manifest = FoundationReleaseManifest::load($manifestPath);

        return [$current['generation'], $manifest, dirname($manifestPath), $manifestPath];
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
        ?string $trustedFoundationManifestSha256 = null,
    ): GeneratedRuntime {
        $runtime->application->attachLoadedReleaseGeneration(new LoadedReleaseGeneration(
            $releaseRoot,
            $generation,
            $trustedFoundationManifestSha256,
        ));

        return $runtime;
    }

    /** @param array<string,mixed> $manifest */
    private function releaseConfig(array $manifest, string $directory): ConfigRepository
    {
        return FoundationReleaseConfig::load(
            $directory . DIRECTORY_SEPARATOR . $this->relative($manifest['config_path'] ?? null),
            FoundationReleaseManifest::digest($manifest['config_sha256'] ?? null, 64, 'config_sha256'),
        );
    }

    private function relative(mixed $path): string
    {
        return str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            FoundationReleaseManifest::relativePath($path, 'runtime path'),
        );
    }
}

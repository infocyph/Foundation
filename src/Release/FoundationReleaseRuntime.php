<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;

/** Process-boot loader for the active immutable Foundation generation. */
final readonly class FoundationReleaseRuntime
{
    public function __construct(private ActiveGeneration $active = new ActiveGeneration()) {}

    /** @param array<string,mixed> $config */
    public function nonWeb(
        array $config,
        RuntimeMode $runtime,
        string $releaseRoot,
    ): GeneratedRuntime {
        $this->assertNonWeb($runtime);
        [, $manifest, $directory] = $this->activeManifest($releaseRoot);
        $section = FoundationReleaseManifest::section($manifest, $runtime->value);

        return GeneratedRuntime::load(
            $config,
            $runtime,
            $directory . DIRECTORY_SEPARATOR . $this->relative($section['intermix_path'] ?? null),
            FoundationReleaseManifest::capabilities(
                $section['capabilities'] ?? null,
                $runtime->value . '.capabilities',
            ),
        );
    }

    /** @param array<string,mixed> $config */
    public function nonWebPrevalidated(
        array $config,
        RuntimeMode $runtime,
        string $releaseRoot,
        string $trustedFoundationManifestSha256,
    ): GeneratedRuntime {
        $this->assertNonWeb($runtime);
        [, $manifest, $directory] = $this->trustedActiveManifest($releaseRoot, $trustedFoundationManifestSha256);
        $section = FoundationReleaseManifest::section($manifest, $runtime->value);

        return GeneratedRuntime::loadPrevalidated(
            $config,
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

    /** @param array<string,mixed> $config */
    public function web(
        array $config,
        string $releaseRoot,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        [, $manifest, $directory] = $this->activeManifest($releaseRoot);
        $web = FoundationReleaseManifest::section($manifest, 'web');

        return WebReleaseRuntime::load(
            $config,
            $directory . DIRECTORY_SEPARATOR . $this->relative($web['release_manifest'] ?? null),
            $adapter,
            FoundationReleaseManifest::capabilities($web['capabilities'] ?? null, 'web.capabilities'),
        );
    }

    /** @param array<string,mixed> $config */
    public function webPrevalidated(
        array $config,
        string $releaseRoot,
        string $trustedFoundationManifestSha256,
        ?RuntimeAdapterInterface $adapter = null,
    ): WebReleaseRuntime {
        [, $manifest, $directory] = $this->trustedActiveManifest(
            $releaseRoot,
            $trustedFoundationManifestSha256,
        );
        $web = FoundationReleaseManifest::section($manifest, 'web');

        return WebReleaseRuntime::loadPrevalidated(
            $config,
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

    private function relative(mixed $path): string
    {
        return str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            FoundationReleaseManifest::relativePath($path, 'runtime path'),
        );
    }
}

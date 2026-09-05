<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Runtime\GeneratedRuntimeMetadata;
use Infocyph\Foundation\Worker\WorkerTopology;
use Infocyph\Webrick\Router\Build\ReleaseCompiler as WebrickReleaseCompiler;

/** Coordinates one immutable Foundation release generation across all runtimes. */
final readonly class FoundationReleaseCompiler
{
    public function __construct(
        private WebReleaseCompiler $web = new WebReleaseCompiler(),
        private GeneratedRuntimeCompiler $nonWeb = new GeneratedRuntimeCompiler(),
        private ActiveGeneration $active = new ActiveGeneration(),
        private WorkerTopology $workerTopology = new WorkerTopology(),
    ) {}

    /**
     * Capability topology is explicit for every generated runtime. Omitted runtime
     * entries therefore mean a deliberately minimal optional-capability graph.
     *
     * @param array<string,mixed> $config
     * @param array<string,array<int|string,mixed>> $capabilities
     * @return array{
     *   generation:string,
     *   manifest:string,
     *   manifest_sha256:string,
     *   active_pointer:string,
     *   release:array<string,mixed>
     * }
     */
    public function buildAndActivate(
        array $config,
        string $releaseRoot,
        array $capabilities = [],
        ?string $generation = null,
    ): array {
        $releaseRoot = $this->root($releaseRoot);
        $generation ??= gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
        $this->assertGeneration($generation);
        [$stage, $final] = $this->generationPaths($releaseRoot, $generation);

        try {
            $manifest = $this->compileGeneration($config, $stage, $final, $generation, $capabilities);
            FoundationReleaseManifest::write($stage . '/foundation.php', $manifest);
            $this->verifyStage($stage, $manifest);
            $this->publishStage($stage, $final, $generation);
            $stage = '';
            $manifestPath = $final . '/foundation.php';
            $manifestSha256 = $this->sha256($manifestPath, 'Foundation release manifest');
            $activePointer = $this->active->activate($releaseRoot, $generation);

            return [
                'generation' => $generation,
                'manifest' => $manifestPath,
                'manifest_sha256' => $manifestSha256,
                'active_pointer' => $activePointer,
                'release' => $manifest,
            ];
        } finally {
            if ($stage !== '' && is_dir($stage)) {
                $this->removeDirectory($stage);
            }
        }
    }

    /** Explicitly remove Foundation-owned release generations and the active pointer. */
    public function clear(string $releaseRoot): bool
    {
        $releaseRoot = $this->root($releaseRoot);
        $removed = $this->active->clear($releaseRoot);
        $generations = $releaseRoot . DIRECTORY_SEPARATOR . 'generations';
        if (is_dir($generations)) {
            $this->removeDirectory($generations);
            $removed = true;
        }

        return $removed;
    }

    /**
     * Explicit housekeeping; never called from request/job hot paths.
     *
     * @return list<string>
     */
    public function prune(string $releaseRoot, int $keep = 2): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Foundation release pruning must keep at least one generation.');
        }
        $releaseRoot = $this->root($releaseRoot);
        $generations = $releaseRoot . DIRECTORY_SEPARATOR . 'generations';
        if (!is_dir($generations)) {
            return [];
        }

        $active = $this->activeGenerationOrNull($releaseRoot);
        $entries = $this->generationTimes($generations);
        $retain = array_fill_keys(array_slice(array_keys($entries), 0, $keep), true);
        if ($active !== null) {
            $retain[$active] = true;
        }

        $removed = [];
        foreach (array_keys($entries) as $generation) {
            if (isset($retain[$generation])) {
                continue;
            }
            $this->removeDirectory($generations . DIRECTORY_SEPARATOR . $generation);
            $removed[] = $generation;
        }

        return $removed;
    }

    /**
     * Build-plane diagnostic only. Corrupt active metadata raises instead of
     * being flattened into a false "not ready" status.
     *
     * @return array{
     *   ready:bool,
     *   release_root:string,
     *   generation:?string,
     *   manifest:?string,
     *   manifest_sha256:?string
     * }
     */
    public function status(string $releaseRoot): array
    {
        $releaseRoot = $this->root($releaseRoot);
        if (!$this->active->exists($releaseRoot)) {
            return [
                'ready' => false,
                'release_root' => $releaseRoot,
                'generation' => null,
                'manifest' => null,
                'manifest_sha256' => null,
            ];
        }

        $active = $this->active->current($releaseRoot);

        return [
            'ready' => true,
            'release_root' => $releaseRoot,
            'generation' => $active['generation'],
            'manifest' => $active['manifest'],
            'manifest_sha256' => $this->sha256($active['manifest'], 'Foundation active release manifest'),
        ];
    }

    private function activeGenerationOrNull(string $releaseRoot): ?string
    {
        try {
            return $this->active->current($releaseRoot)['generation'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertGeneration(string $generation): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1) {
            throw new \InvalidArgumentException('Foundation release generation identifier is invalid.');
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,array<int|string,mixed>> $capabilities
     * @return array<string,mixed>
     */
    private function compileGeneration(
        array $config,
        string $stage,
        string $final,
        string $generation,
        array $capabilities,
    ): array {
        $this->mkdir($stage . '/web');
        $web = $this->web->compile(
            $config,
            $stage . '/web/container.php',
            $stage . '/web/router.php',
            $stage . '/web/release.json',
            $capabilities['web'] ?? [],
        );
        $runtimeManifestSha256 = $this->rebaseWebReleaseManifest($stage, $final);
        $environment = $this->stringField($web, 'environment');
        $configFingerprint = $this->digestField($web, 'config_fingerprint', 64);
        $webCapabilities = FoundationReleaseManifest::capabilities(
            $web['foundation_capabilities'] ?? null,
            'web.capabilities',
        );

        $runtimeSections = [];
        foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $runtime) {
            $runtimeSections[$runtime->value] = $this->compileNonWebSection(
                $config,
                $stage,
                $runtime,
                $capabilities[$runtime->value] ?? [],
                $environment,
                $configFingerprint,
            );
        }

        $topology = $this->workerTopology->compile($config, $stage . '/worker/providers.php');
        $runtimeSections['worker']['provider_topology'] = 'worker/providers.php';
        $runtimeSections['worker']['provider_topology_sha256'] = $topology['sha256'];

        return [
            'format' => FoundationReleaseManifest::FORMAT,
            'generation' => $generation,
            'environment' => $environment,
            'config_fingerprint' => $configFingerprint,
            'web' => [
                'release_manifest' => 'web/release.json',
                'runtime_manifest_sha256' => $runtimeManifestSha256,
                'capabilities' => $webCapabilities,
            ],
            'cli' => $runtimeSections['cli'],
            'worker' => $runtimeSections['worker'],
            'scheduler' => $runtimeSections['scheduler'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int|string,mixed> $capabilities
     * @return array<string,mixed>
     */
    private function compileNonWebSection(
        array $config,
        string $stage,
        RuntimeMode $runtime,
        array $capabilities,
        string $environment,
        string $configFingerprint,
    ): array {
        $name = $runtime->value;
        $this->mkdir($stage . '/' . $name);
        $report = $this->nonWeb->compile(
            $config,
            $runtime,
            $stage . '/' . $name . '/container.php',
            $capabilities,
        );
        $metadata = GeneratedRuntimeMetadata::read($report['path']);
        if (($metadata['environment'] ?? null) !== $environment
            || ($metadata['config_fingerprint'] ?? null) !== $configFingerprint
        ) {
            throw new \RuntimeException(sprintf(
                'Foundation %s runtime identity does not match the web release generation.',
                $name,
            ));
        }
        if ($report['skipped'] !== []) {
            throw new \RuntimeException(sprintf('Foundation %s runtime contains skipped definitions.', $name));
        }

        return [
            'intermix_path' => $name . '/container.php',
            'digest' => $report['digest'],
            'metadata_path' => $name . '/container.php.foundation.json',
            'metadata_sha256' => $report['metadata_sha256'],
            'capabilities' => $report['capabilities'],
        ];
    }

    /** @param array<string,mixed> $source */
    private function digestField(array $source, string $field, int $length): string
    {
        return FoundationReleaseManifest::digest($source[$field] ?? null, $length, $field);
    }

    /** @return array{0:string,1:string} */
    private function generationPaths(string $releaseRoot, string $generation): array
    {
        $generations = $releaseRoot . DIRECTORY_SEPARATOR . 'generations';
        $this->mkdir($generations);
        $stage = $generations . DIRECTORY_SEPARATOR . '.staging-' . $generation . '-' . bin2hex(random_bytes(4));
        $final = $generations . DIRECTORY_SEPARATOR . $generation;
        if (file_exists($final)) {
            throw new \RuntimeException(sprintf('Foundation release generation "%s" already exists.', $generation));
        }
        $this->mkdir($stage);

        return [$stage, $final];
    }

    /** @return array<string,int> */
    private function generationTimes(string $generations): array
    {
        $entries = [];
        foreach (new \DirectoryIterator($generations) as $entry) {
            if (!$entry->isDir() || $entry->isDot() || str_starts_with($entry->getFilename(), '.staging-')) {
                continue;
            }
            $entries[$entry->getFilename()] = $entry->getMTime();
        }
        arsort($entries, SORT_NUMERIC);

        return $entries;
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release directory "%s".', $directory));
        }
    }

    private function publishStage(string $stage, string $final, string $generation): void
    {
        if (!rename($stage, $final)) {
            throw new \RuntimeException(sprintf('Unable to publish Foundation release generation "%s".', $generation));
        }
    }

    private function rebaseWebReleaseManifest(string $stage, string $final): string
    {
        $releasePath = $stage . '/web/release.json';
        $json = file_get_contents($releasePath);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to read staged Webrick release manifest.');
        }
        $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \UnexpectedValueException('Staged Webrick release manifest is malformed.');
        }

        $intermix = $manifest['intermix'] ?? null;
        $webrick = $manifest['webrick'] ?? null;
        if (!is_array($intermix) || !is_array($webrick)) {
            throw new \UnexpectedValueException('Staged Webrick release manifest is incomplete.');
        }
        $intermix['path'] = $final . '/web/container.php';
        $webrick['path'] = $final . '/web/router.php';
        $webrick['meta'] = $final . '/web/router.php.meta.json';
        $manifest['intermix'] = $intermix;
        $manifest['webrick'] = $webrick;

        $this->writeAtomic(
            $releasePath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $runtimePath = WebrickReleaseCompiler::runtimeManifestPath($releasePath);
        $this->writeAtomic(
            $runtimePath,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($manifest, true) . ";\n",
        );
        $sha256 = hash_file('sha256', $runtimePath);
        if (!is_string($sha256)) {
            throw new \RuntimeException('Unable to fingerprint staged Webrick runtime manifest.');
        }

        return $sha256;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

    private function root(string $releaseRoot): string
    {
        $releaseRoot = rtrim($releaseRoot, DIRECTORY_SEPARATOR);
        if ($releaseRoot === '') {
            throw new \InvalidArgumentException('Foundation release root must not be empty.');
        }

        return $releaseRoot;
    }

    private function sha256(string $path, string $artifact): string
    {
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)) {
            throw new \RuntimeException(sprintf('Unable to fingerprint %s.', $artifact));
        }

        return $sha256;
    }

    /** @param array<string,mixed> $source */
    private function stringField(array $source, string $field): string
    {
        return FoundationReleaseManifest::nonEmptyString($source[$field] ?? null, $field);
    }

    /** @param array<string,mixed> $manifest */
    private function verifyStage(string $stage, array $manifest): void
    {
        FoundationReleaseManifest::assertValid($manifest);
        $web = FoundationReleaseManifest::section($manifest, 'web');
        $webRelease = FoundationReleaseManifest::relativePath(
            $web['release_manifest'] ?? null,
            'web.release_manifest',
        );
        $paths = ['foundation.php', $webRelease];
        foreach (['cli', 'worker', 'scheduler'] as $runtime) {
            $section = FoundationReleaseManifest::section($manifest, $runtime);
            $paths[] = FoundationReleaseManifest::relativePath(
                $section['intermix_path'] ?? null,
                $runtime . '.intermix_path',
            );
            $paths[] = FoundationReleaseManifest::relativePath(
                $section['metadata_path'] ?? null,
                $runtime . '.metadata_path',
            );
        }

        $worker = FoundationReleaseManifest::section($manifest, 'worker');
        $workerTopology = FoundationReleaseManifest::relativePath(
            $worker['provider_topology'] ?? null,
            'worker.provider_topology',
        );
        $workerTopologySha256 = FoundationReleaseManifest::digest(
            $worker['provider_topology_sha256'] ?? null,
            64,
            'worker.provider_topology_sha256',
        );
        $paths[] = $workerTopology;

        foreach ($paths as $relative) {
            $path = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('Foundation release generation is incomplete at "%s".', $relative));
            }
        }

        $this->workerTopology->load(
            $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $workerTopology),
            $workerTopologySha256,
        );

        $webReleasePath = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $webRelease);
        $webRuntimePath = WebrickReleaseCompiler::runtimeManifestPath($webReleasePath);
        $actualWebRuntimeSha256 = is_file($webRuntimePath) ? hash_file('sha256', $webRuntimePath) : false;
        $expectedWebRuntimeSha256 = $this->digestField($web, 'runtime_manifest_sha256', 64);
        if (!is_string($actualWebRuntimeSha256)
            || !hash_equals($expectedWebRuntimeSha256, $actualWebRuntimeSha256)
        ) {
            throw new \RuntimeException('Foundation staged Webrick runtime manifest trust identity mismatch.');
        }
        if (FoundationReleaseManifest::load($stage . '/foundation.php') !== $manifest) {
            throw new \RuntimeException('Foundation release manifest did not round-trip exactly before publication.');
        }
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException(sprintf('Unable to publish Foundation release metadata "%s".', $path));
        }
    }
}

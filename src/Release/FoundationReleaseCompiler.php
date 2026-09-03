<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Runtime\GeneratedRuntimeMetadata;

/** Coordinates one immutable Foundation release generation across all runtimes. */
final readonly class FoundationReleaseCompiler
{
    public function __construct(
        private WebReleaseCompiler $web = new WebReleaseCompiler(),
        private GeneratedRuntimeCompiler $nonWeb = new GeneratedRuntimeCompiler(),
        private ActiveGeneration $active = new ActiveGeneration(),
    ) {}

    /**
     * Capability topology is explicit for every generated runtime. Omitted runtime
     * entries therefore mean a deliberately minimal optional-capability graph.
     *
     * @param array<string,mixed> $config
     * @param array<string,array<int|string,mixed>> $capabilities
     * @return array{generation:string,manifest:string,active_pointer:string,release:array<string,mixed>}
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
            $manifest = $this->compileGeneration($config, $stage, $generation, $capabilities);
            FoundationReleaseManifest::write($stage . '/foundation.php', $manifest);
            $this->verifyStage($stage, $manifest);
            $this->publishStage($stage, $final, $generation);
            $stage = '';
            $activePointer = $this->active->activate($releaseRoot, $generation);

            return [
                'generation' => $generation,
                'manifest' => $final . '/foundation.php',
                'active_pointer' => $activePointer,
                'release' => $manifest,
            ];
        } finally {
            if ($stage !== '' && is_dir($stage)) {
                $this->removeDirectory($stage);
            }
        }
    }

    /** Explicit housekeeping; never called from request/job hot paths. @return list<string> */
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
     * @param array<string,mixed> $config
     * @param array<string,array<int|string,mixed>> $capabilities
     * @return array<string,mixed>
     */
    private function compileGeneration(
        array $config,
        string $stage,
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

        return [
            'format' => FoundationReleaseManifest::FORMAT,
            'generation' => $generation,
            'environment' => $environment,
            'config_fingerprint' => $configFingerprint,
            'web' => [
                'release_manifest' => 'web/release.json',
                'runtime_manifest_sha256' => $this->digestField($web, 'release_runtime_manifest_sha256', 64),
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

    /** @param array<string,mixed> $manifest */
    private function verifyStage(string $stage, array $manifest): void
    {
        FoundationReleaseManifest::assertValid($manifest);
        $web = FoundationReleaseManifest::section($manifest, 'web');
        $paths = ['foundation.php', FoundationReleaseManifest::relativePath(
            $web['release_manifest'] ?? null,
            'web.release_manifest',
        )];
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

        foreach ($paths as $relative) {
            $path = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('Foundation release generation is incomplete at "%s".', $relative));
            }
        }
        if (FoundationReleaseManifest::load($stage . '/foundation.php') !== $manifest) {
            throw new \RuntimeException('Foundation release manifest did not round-trip exactly before publication.');
        }
    }

    private function publishStage(string $stage, string $final, string $generation): void
    {
        if (!rename($stage, $final)) {
            throw new \RuntimeException(sprintf('Unable to publish Foundation release generation "%s".', $generation));
        }
    }

    private function activeGenerationOrNull(string $releaseRoot): ?string
    {
        try {
            return $this->active->current($releaseRoot)['generation'];
        } catch (\Throwable) {
            return null;
        }
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

    /** @param array<string,mixed> $source */
    private function digestField(array $source, string $field, int $length): string
    {
        return FoundationReleaseManifest::digest($source[$field] ?? null, $length, $field);
    }

    /** @param array<string,mixed> $source */
    private function stringField(array $source, string $field): string
    {
        return FoundationReleaseManifest::nonEmptyString($source[$field] ?? null, $field);
    }

    private function assertGeneration(string $generation): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1) {
            throw new \InvalidArgumentException('Foundation release generation identifier is invalid.');
        }
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release directory "%s".', $directory));
        }
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
}

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

        $generations = $releaseRoot . DIRECTORY_SEPARATOR . 'generations';
        if (!is_dir($generations) && !mkdir($generations, 0775, true) && !is_dir($generations)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation generations directory "%s".', $generations));
        }

        $stage = $generations . DIRECTORY_SEPARATOR . '.staging-' . $generation . '-' . bin2hex(random_bytes(4));
        $final = $generations . DIRECTORY_SEPARATOR . $generation;
        if (file_exists($final)) {
            throw new \RuntimeException(sprintf('Foundation release generation "%s" already exists.', $generation));
        }
        mkdir($stage, 0775, true);

        try {
            $this->mkdir($stage . '/web');
            $web = $this->web->compile(
                $config,
                $stage . '/web/container.php',
                $stage . '/web/router.php',
                $stage . '/web/release.json',
            );
            $environment = $this->stringField($web, 'environment');
            $configFingerprint = $this->digestField($web, 'config_fingerprint', 64);
            $runtimeManifestSha256 = $this->digestField($web, 'release_runtime_manifest_sha256', 64);

            $runtimeSections = [];
            foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $runtime) {
                $name = $runtime->value;
                $this->mkdir($stage . '/' . $name);
                $report = $this->nonWeb->compile(
                    $config,
                    $runtime,
                    $stage . '/' . $name . '/container.php',
                    $capabilities[$name] ?? [],
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
                if (($report['skipped'] ?? null) !== []) {
                    throw new \RuntimeException(sprintf('Foundation %s runtime contains skipped definitions.', $name));
                }

                $runtimeSections[$name] = [
                    'intermix_path' => $name . '/container.php',
                    'digest' => $this->digestField($report, 'digest', 32),
                    'metadata_path' => $name . '/container.php.foundation.json',
                    'metadata_sha256' => $this->digestField($report, 'metadata_sha256', 64),
                    'capabilities' => is_array($report['capabilities'] ?? null) ? $report['capabilities'] : [],
                ];
            }

            $manifest = [
                'format' => FoundationReleaseManifest::FORMAT,
                'generation' => $generation,
                'environment' => $environment,
                'config_fingerprint' => $configFingerprint,
                'web' => [
                    'release_manifest' => 'web/release.json',
                    'runtime_manifest_sha256' => $runtimeManifestSha256,
                ],
                'cli' => $runtimeSections['cli'],
                'worker' => $runtimeSections['worker'],
                'scheduler' => $runtimeSections['scheduler'],
            ];
            FoundationReleaseManifest::write($stage . '/foundation.php', $manifest);
            $this->verifyStage($stage, $manifest);

            if (!rename($stage, $final)) {
                throw new \RuntimeException(sprintf('Unable to publish Foundation release generation "%s".', $generation));
            }
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

        $active = null;
        try {
            $active = $this->active->current($releaseRoot)['generation'];
        } catch (\Throwable) {
            // Pruning is still safe when no active pointer exists: newest generations are retained.
        }

        $entries = [];
        foreach (new \DirectoryIterator($generations) as $entry) {
            if (!$entry->isDir() || $entry->isDot() || str_starts_with($entry->getFilename(), '.staging-')) {
                continue;
            }
            $entries[$entry->getFilename()] = $entry->getMTime();
        }
        arsort($entries, SORT_NUMERIC);
        $retain = array_fill_keys(array_slice(array_keys($entries), 0, $keep), true);
        if (is_string($active)) {
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

    /** @param array<string,mixed> $manifest */
    private function verifyStage(string $stage, array $manifest): void
    {
        FoundationReleaseManifest::assertValid($manifest);
        foreach ([
            'foundation.php',
            $manifest['web']['release_manifest'],
            $manifest['cli']['intermix_path'],
            $manifest['cli']['metadata_path'],
            $manifest['worker']['intermix_path'],
            $manifest['worker']['metadata_path'],
            $manifest['scheduler']['intermix_path'],
            $manifest['scheduler']['metadata_path'],
        ] as $relative) {
            if (!is_string($relative) || !is_file($stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                throw new \RuntimeException(sprintf('Foundation release generation is incomplete at "%s".', (string) $relative));
            }
        }
        $loaded = FoundationReleaseManifest::load($stage . '/foundation.php');
        if ($loaded !== $manifest) {
            throw new \RuntimeException('Foundation release manifest did not round-trip exactly before publication.');
        }
    }

    /** @param array<string,mixed> $source */
    private function digestField(array $source, string $field, int $length): string
    {
        $value = $source[$field] ?? null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function stringField(array $source, string $field): string
    {
        $value = $source[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
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

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;

/**
 * Composer phase of the Foundation 4 feature lifecycle. Does not activate,
 * publish config, migrate schemas or attempt rollback after Composer failure.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleFeatureComposerExecutor
{
    private ModuleFeaturePlanner $planner;

    private ProcessRunner $processes;

    public function __construct(
        private Application $application,
        ?ModuleFeaturePlanner $planner = null,
        ?ProcessRunner $processes = null,
    ) {
        $this->planner = $planner ?? new ModuleFeaturePlanner();
        $this->processes = $processes ?? new ProcessRunner();
    }

    /**
     * @param list<string> $features
     * @phpstan-param Context $context
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function applyPackages(
        string $operation,
        array $features,
        array $context,
        bool $dryRun = false,
    ): ProcessResult {
        if (!in_array($operation, ['install', 'remove', 'repair'], true)) {
            throw new \InvalidArgumentException('Only Composer feature lifecycle operations are supported.');
        }

        $plan = $this->planner->plan($operation, $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature change is blocked: ' . implode('; ', $plan['blockers']));
        }

        $packages = $operation === 'remove'
            ? $plan['effects']['remove']
            : array_map(
                static fn(string $package, string $constraint): string => $package . ':' . $constraint,
                array_keys($plan['effects']['require']),
                array_values($plan['effects']['require']),
            );

        if ($dryRun || $packages === []) {
            $this->assertRootRequirements($context);

            return $packages === []
                ? new ProcessResult(0)
                : $this->runComposer($operation, $packages, true);
        }

        $lockPath = $this->application->basePath('.foundation-module-composer.lock');
        if (is_link($lockPath)) {
            throw new \RuntimeException('Refusing symbolic-linked module Composer lock.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open module Composer lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire module Composer lock.');
            }
            $this->assertRootRequirements($context);

            return $this->runComposer($operation, $packages, false);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @phpstan-param Context $context
     * @throws \RuntimeException
     */
    private function assertRootRequirements(array $context): void
    {
        new ModuleFeatureCommandSnapshot($this->application)->assertCurrent($context);
        $path = $this->application->basePath('composer.json');
        if (!$context['root_known'] || is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Application Composer ownership is unavailable or unsafe.');
        }

        try {
            $contents = file_get_contents($path);
            $composer = json_decode($contents === false ? '' : $contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('Application composer.json is malformed.', previous: $failure);
        }
        if (!is_array($composer)) {
            throw new \RuntimeException('Application composer.json must be a JSON object.');
        }

        $requirements = $composer['require'] ?? [];
        if (!is_array($requirements)) {
            throw new \RuntimeException('Application Composer requirements must be an object.');
        }
        foreach ($requirements as $package => $constraint) {
            if (!is_string($package) || !is_string($constraint)) {
                throw new \RuntimeException('Application Composer requirements contain invalid entries.');
            }
        }

        ksort($requirements);
        $expected = $context['root_requirements'];
        ksort($expected);
        if ($requirements !== $expected) {
            throw new \RuntimeException('Application Composer ownership changed; re-plan before applying.');
        }
    }

    /**
     * Composer's installed metadata records the last installation mode, unlike
     * the root require-dev declaration or the presence of a particular tool.
     * @throws \RuntimeException
     */
    private function installedWithoutDev(): bool
    {
        $path = $this->application->basePath('vendor/composer/installed.json');
        if (is_link($path)) {
            throw new \RuntimeException('Composer installed metadata must not be symlinked.');
        }
        if (!is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read Composer installed metadata.');
        }

        try {
            /** @var array<array-key, mixed>|bool|float|int|string|null $metadata */
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('Composer installed metadata is malformed.', previous: $failure);
        }

        if (!is_array($metadata)) {
            throw new \RuntimeException('Composer installed metadata must be an array.');
        }
        if (array_key_exists('dev', $metadata) && !is_bool($metadata['dev'])) {
            throw new \RuntimeException('Composer installed development mode must be boolean.');
        }

        return ($metadata['dev'] ?? null) === false;
    }

    /**
     * @param list<string> $packages
     * @throws \Infocyph\Foundation\Exception\ServiceResolutionException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    private function runComposer(string $operation, array $packages, bool $dryRun): ProcessResult
    {
        $command = [
            'composer',
            $operation === 'remove' ? 'remove' : 'require',
            ...$packages,
            '--with-all-dependencies',
            '--no-interaction',
        ];
        if ($this->installedWithoutDev()) {
            $command[] = '--update-no-dev';
        }
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run(
            $command,
            new ProcessOptions(cwd: $this->application->basePath(), interactive: false),
        );
    }
}

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
     */
    private function assertRootRequirements(array $context): void
    {
        $path = $this->application->basePath('composer.json');
        if (!$context['root_known'] || is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Application Composer ownership is unavailable or unsafe.');
        }

        try {
            $composer = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
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
     * @param list<string> $packages
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
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run(
            $command,
            new ProcessOptions(cwd: $this->application->basePath(), interactive: false),
        );
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleFeatureResolver;
use Infocyph\Foundation\Module\ModuleFeatureCommandCatalog;
use Infocyph\Foundation\Module\ModuleFeatureCommandSnapshot;
use Infocyph\Foundation\Module\ModuleFeatureConfigPublisher;
use Infocyph\Foundation\Module\ModuleFeatureLifecycleExecutor;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;
use Infocyph\Foundation\Module\ModuleFeatureSchemaExecutor;
use Infocyph\Foundation\Module\ModuleSchemaManager;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;

/**
 * Foundation 4 public module command boundary: three modules and nine optional
 * qualified features. Core and PHPForge are not installable modules.
 *
 * All operations use the same fresh CLI Composer/feature snapshot and B2/B3
 * pure planner + safe lifecycle. No legacy-module fallback or aliases.
 */
final class ModuleSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'module:list' => $this->listing(),
            'module:show', 'module:doctor' => $this->inspect(),
            'module:plan' => $this->plan(),
            'module:install', 'module:remove', 'module:enable',
            'module:disable', 'module:repair' => $this->mutateFeature(),
            'module:config:publish' => $this->publishConfig(),
            'module:schema:status', 'module:schema:install',
            'module:schema:sync' => $this->schemas(),
            default => throw new \LogicException('Unknown Foundation 4 module command.'),
        };
    }

    private function catalog(): ModuleFeatureCommandCatalog
    {
        return new ModuleFeatureCommandCatalog();
    }

    /** @return list<string> */
    private function selection(): array
    {
        return $this->catalog()->selection(
            $this->argument(0) ?? throw new \LogicException('Expected a validated module or feature argument.'),
            $this->values('feature'),
        );
    }

    /**
     * @phpstan-import-type Context from \Infocyph\Foundation\Module\ModuleFeatureResolver
     * @phpstan-return Context
     */
    private function snapshot(): array
    {
        return new ModuleFeatureCommandSnapshot($this->application)->capture();
    }

    private function listing(): int
    {
        $inventory = $this->catalog()->inventory();
        if ($this->io()->machineReadable()) {
            return $this->emit($inventory);
        }

        $this->io()->table(
            ['Module', 'Feature IDs', 'Purpose'],
            array_map(
                static fn(array $group): array => [
                    $group['name'],
                    implode(', ', $group['features']),
                    $group['description'],
                ],
                $inventory['modules'],
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function inspect(): int
    {
        $catalog = $this->catalog();
        $inventory = $catalog->inventory();
        $requested = $this->argument(0) ?? throw new \LogicException('Expected a module argument.');
        $group = explode('.', $requested, 2)[0];
        $definition = array_find(
            $inventory['modules'],
            static fn(array $entry): bool => $entry['name'] === $group,
        );
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Unknown Foundation 4 module "%s".', $requested));
        }

        // A group is a read-only view of siblings, not an implicit selection.
        $selected = str_contains($requested, '.') || $this->values('feature') !== []
            ? $catalog->selection($requested, $this->values('feature'))
            : $definition['features'];
        $state = new ModuleFeatureResolver()->resolve($selected, $this->snapshot());
        $payload = [
            'schema_version' => 2,
            'module' => $group,
            'requested' => $requested,
            'features' => $definition['features'],
            'states' => $state['states'],
            'blockers' => $state['blockers'],
            'root_known' => $state['root_known'],
        ];

        if ($this->io()->machineReadable()) {
            return $this->emit($payload);
        }

        $this->io()->table(
            ['Feature', 'Enabled', 'Allowed', 'Missing extensions'],
            array_map(
                static fn(string $id, array $row): array => [
                    $id,
                    $row['enabled'],
                    $row['allowed'],
                    implode(', ', $row['missing_extensions']),
                ],
                array_keys($state['states']),
                array_values($state['states']),
            ),
        );
        foreach ($state['blockers'] as $blocker) {
            $this->io()->warning($blocker);
        }

        return ExitCode::SUCCESS;
    }

    private function plan(): int
    {
        $plan = new ModuleFeaturePlanner()->plan('install', $this->selection(), $this->snapshot());
        if ($this->io()->machineReadable()) {
            $this->io()->json($plan);
        } else {
            $this->io()->table(
                ['Requested feature(s)', 'Required feature(s)', 'Packages to require', 'Applicable'],
                [[
                    implode(', ', $plan['requested']),
                    implode(', ', $plan['required']),
                    implode(', ', array_keys($plan['effects']['require'])),
                    $plan['applicable'],
                ]],
            );
            foreach ($plan['blockers'] as $blocker) {
                $this->io()->warning($blocker);
            }
        }

        return $plan['applicable'] ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function mutateFeature(): int
    {
        $operation = substr($this->canonicalName(), strlen('module:'));
        $features = $this->selection();
        $snapshot = new ModuleFeatureCommandSnapshot($this->application);
        $result = new ModuleFeatureLifecycleExecutor($this->application)->apply(
            $operation,
            $features,
            $snapshot->capture(),
            in_array($operation, ['install', 'repair'], true) ? $snapshot->capture(...) : null,
            in_array($operation, ['install', 'remove'], true) && $this->flag('dry-run'),
        );

        if ($result['status'] === 'complete' && !in_array($operation, ['install', 'repair'], true)) {
            $this->invalidateRelease();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json(['schema_version' => 2, ...$result]);
        } else {
            $this->io()->note(sprintf('Feature %s: %s.', $operation, $result['status']));
            if ($result['recovery'] !== null) {
                $this->io()->warning($result['recovery']);
            }
        }

        return in_array($result['status'], ['complete', 'planned'], true)
            ? ExitCode::SUCCESS
            : ExitCode::FAILURE;
    }

    private function publishConfig(): int
    {
        $result = new ModuleFeatureConfigPublisher($this->application)->publish(
            $this->selection(),
            $this->snapshot(),
        );
        if ($result['published'] !== []) {
            $this->invalidateRelease();
        }

        return $this->emit(
            ['schema_version' => 2, ...$result],
            sprintf('%d feature-owned configuration files published.', count($result['published'])),
        );
    }

    private function schemas(): int
    {
        $name = $this->canonicalName();
        $snapshot = $this->snapshot();
        $features = $name === 'module:schema:sync'
            ? $snapshot['enabled']
            : $this->selection();

        if ($features === []) {
            return $this->emit(['schema_version' => 2, 'schemas' => []], 'No selected optional feature schemas.');
        }

        $plan = new ModuleFeaturePlanner()->plan('repair', $features, $snapshot);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Schema planning blocked: ' . implode('; ', $plan['blockers']));
        }

        // Foundation core auth/session schemas are not optional feature-owned.
        if ($plan['effects']['schemas'] !== [] && $plan['effects']['schemas'] !== ['messaging']) {
            throw new \RuntimeException('Unexpected optional schema ownership.');
        }

        if ($name === 'module:schema:status') {
            $schemas = in_array('messaging', $plan['effects']['schemas'], true)
                ? new ModuleSchemaManager($this->application, new ModuleCatalog())->status(
                    'messaging',
                    $this->option('connection'),
                )
                : [];
        } else {
            $schemas = new ModuleFeatureSchemaExecutor($this->application)->install(
                $features,
                $snapshot,
                $this->option('connection'),
                false,
            );
        }

        return $this->emit(['schema_version' => 2, 'schemas' => $schemas], 'Selected feature-owned schemas verified.');
    }

    private function invalidateRelease(): void
    {
        $config = $this->application->config()->all();
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $app['base_path'] = $this->application->basePath();
        $config['app'] = $app;
        new FoundationReleaseCompiler()->clear(FoundationReleaseBootstrap::resolveReleaseRoot($config));
    }
}

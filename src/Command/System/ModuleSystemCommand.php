<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;
use Infocyph\Foundation\Module\ModuleSchemaManager;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;

/**
 * @phpstan-import-type PackageState from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-import-type ModuleState from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-import-type ResolvedModule from \Infocyph\Foundation\Module\ModuleCatalog
 */
final class ModuleSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'module:config:publish' => $this->publishConfig(),
            'module:install' => $this->install(),
            'module:list' => $this->listing(),
            'module:remove' => $this->remove(),
            'module:schema:install' => $this->schemaInstall(),
            'module:schema:status' => $this->schemaStatus(),
            'module:schema:sync' => $this->schemaSync(),
            'module:show' => $this->show(),
            default => throw new \LogicException('Unsupported module system command.'),
        };
    }

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog();
    }

    private function install(): int
    {
        $requested = $this->module();
        $definition = $this->catalog()->resolve($requested);
        $module = $definition['name'];
        $manager = $this->manager();
        $dryRun = $this->flag('dry-run');
        $result = $manager->install($module, $dryRun);
        if (!$result->successful()) {
            return $result->exitCode;
        }

        $published = $dryRun
            ? ['published' => [], 'existing' => []]
            : $manager->publishConfig($module);
        $schemas = [];
        $schemaExit = ExitCode::SUCCESS;

        if (!$dryRun) {
            $this->invalidateCompiledRuntime();
            [$schemaExit, $schemas] = $this->syncSchemasFresh();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json([
                'module' => $module,
                'requested' => $requested,
                'exit_code' => $schemaExit,
                ...$published,
                'owned_schemas' => $definition['schemas'],
                'schemas' => $schemas,
            ]);
        } else {
            $this->io()->success(sprintf('Module "%s" is installed.', $module));
            foreach ($published['published'] as $path) {
                $this->io()->info('Published ' . $path);
            }
            if ($schemas === [] && $definition['schemas'] !== []) {
                $this->io()->info('No module database schema is required by the current configuration.');
            } else {
                $this->renderSchemas($schemas);
            }
        }

        return $schemaExit;
    }

    private function invalidateCompiledRuntime(): void
    {
        $config = $this->application->config()->all();
        $config['base_path'] = $this->application->basePath();
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $app['base_path'] = $this->application->basePath();
        $config['app'] = $app;

        new FoundationReleaseCompiler()->clear(
            FoundationReleaseBootstrap::resolveReleaseRoot($config),
        );
    }

    private function listing(): int
    {
        $modules = $this->manager()->all();
        if ($this->io()->machineReadable()) {
            $this->io()->json($modules);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Module', 'Status', 'Direct', 'Enabled', 'Configured', 'Ready', 'Packages', 'Purpose'],
            array_map(
                fn(array $module): array => [
                    $module['name'],
                    $module['status'],
                    $module['direct'],
                    $module['enabled'],
                    $module['configured'],
                    $module['ready'],
                    $this->packageSummary($module['packages']),
                    $module['description'],
                ],
                $modules,
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager($this->application, $this->catalog(), new ProcessRunner());
    }

    private function module(): string
    {
        return $this->argument(0) ?? throw new \LogicException('Validated module argument is unavailable.');
    }

    /** @param array<string,PackageState> $packages */
    private function packageSummary(array $packages): string
    {
        if ($packages === []) {
            return 'Foundation';
        }

        $summary = [];
        foreach ($packages as $package => $state) {
            $summary[] = $package . ' ' . ($state['version'] ?? $state['constraint']);
        }

        return implode(', ', $summary);
    }

    private function projectLauncher(): string
    {
        foreach ([
            $this->application->basePath('infbyte'),
            $this->application->basePath('vendor/bin/infbyte'),
        ] as $launcher) {
            if (is_file($launcher)) {
                return $launcher;
            }
        }

        throw new \RuntimeException('Unable to locate an Infbyte/Foundation CLI launcher for schema synchronization.');
    }

    private function publishConfig(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $result = $this->manager()->publishConfig($module, $this->flag('force'));
        if ($result['published'] !== []) {
            $this->invalidateCompiledRuntime();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json(['module' => $module, 'requested' => $requested, ...$result]);

            return ExitCode::SUCCESS;
        }

        foreach ($result['published'] as $path) {
            $this->io()->success('Published ' . $path);
        }
        foreach ($result['existing'] as $path) {
            $this->io()->note('Already exists: ' . $path);
        }
        if ($result['published'] === [] && $result['existing'] === []) {
            $this->io()->note(sprintf('Module "%s" owns no publishable config.', $module));
        }

        return ExitCode::SUCCESS;
    }

    private function remove(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $dryRun = $this->flag('dry-run');
        $result = $this->manager()->remove($module, $dryRun);
        if ($result->successful() && !$dryRun) {
            $this->invalidateCompiledRuntime();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json([
                'module' => $module,
                'requested' => $requested,
                'exit_code' => $result->exitCode,
            ]);
        } elseif ($result->successful()) {
            $this->io()->success(sprintf(
                'Module "%s" removed. Application schemas were preserved.',
                $module,
            ));
        }

        return $result->exitCode;
    }

    /**
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     */
    private function renderSchemas(array $schemas): void
    {
        if ($schemas === []) {
            $this->io()->note('No database schemas are owned by this module.');

            return;
        }

        $visible = array_values(array_filter(
            $schemas,
            static fn(array $schema): bool => $schema['applicable'] || $schema['state'] !== 'not-applicable',
        ));
        if ($visible === []) {
            $this->io()->info('No database schema is required by the current configuration.');

            return;
        }

        $this->io()->table(
            ['Schema', 'Module', 'Applicable', 'State', 'Detail'],
            array_map(
                static fn(array $schema): array => [
                    $schema['name'],
                    $schema['module'],
                    $schema['applicable'],
                    $schema['state'],
                    $schema['detail'],
                ],
                $visible,
            ),
        );
    }

    private function schemaInstall(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $schemas = $this->schemas()->install($module, $this->option('connection'));

        return $this->schemaResponse($schemas, $module, $requested, true);
    }

    /**
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     */
    private function schemaResponse(
        array $schemas,
        ?string $module = null,
        ?string $requested = null,
        bool $strict = false,
    ): int {
        $failed = array_any(
            $schemas,
            static fn(array $schema): bool => !$schema['installed'] && ($strict || $schema['applicable']),
        );
        $payload = ['schemas' => $schemas];
        if ($module !== null) {
            $payload['module'] = $module;
        }
        if ($requested !== null) {
            $payload['requested'] = $requested;
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json($payload);
        } else {
            $this->renderSchemas($schemas);
        }

        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    /** @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}|null */
    private function schemaRow(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $name = $value['name'] ?? null;
        $module = $value['module'] ?? null;
        $applicable = $value['applicable'] ?? null;
        $installed = $value['installed'] ?? null;
        $state = $value['state'] ?? null;
        $detail = $value['detail'] ?? null;

        if (!is_string($name)
            || !is_string($module)
            || !is_bool($applicable)
            || !is_bool($installed)
            || !is_string($state)
            || !is_string($detail)
        ) {
            return null;
        }

        return compact('name', 'module', 'applicable', 'installed', 'state', 'detail');
    }

    /** @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> */
    private function schemaRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $schemas = [];
        foreach ($value as $candidate) {
            $schema = $this->schemaRow($candidate);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }

    private function schemas(): ModuleSchemaManager
    {
        return new ModuleSchemaManager($this->application, $this->catalog());
    }

    private function schemaStatus(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $schemas = $this->schemas()->status($module, $this->option('connection'));

        return $this->schemaResponse($schemas, $module, $requested, true);
    }

    private function schemaSync(): int
    {
        $schemas = $this->schemas()->installApplicable($this->option('connection'));

        return $this->schemaResponse($schemas);
    }

    private function show(): int
    {
        $requested = $this->module();
        $definition = $this->catalog()->resolve($requested);
        $module = $this->moduleState($definition['name']);
        $config = $this->configRows($definition);
        $schemas = $this->schemas()->status($definition['name'], $this->option('connection'));
        $readiness = $this->showReadiness($module, $schemas);

        if ($this->io()->machineReadable()) {
            $this->io()->json([
                ...$module,
                'requested' => $requested,
                ...$readiness,
                'config' => $config,
                'schema_status' => $schemas,
            ]);

            return ExitCode::SUCCESS;
        }

        $this->renderShow($module, $readiness, $config, $schemas);

        return ExitCode::SUCCESS;
    }

    /** @return ModuleState */
    private function moduleState(string $name): array
    {
        $module = array_find(
            $this->manager()->all(),
            static fn(array $candidate): bool => $candidate['name'] === $name,
        );
        if (!is_array($module)) {
            throw new \LogicException(sprintf('Module "%s" is missing from the module registry.', $name));
        }

        return $module;
    }

    /**
     * @param ResolvedModule $definition
     * @return list<array{file:string,path:string,published:bool}>
     */
    private function configRows(array $definition): array
    {
        return array_map(
            function (string $filename): array {
                $path = $this->application->configPath($filename);

                return [
                    'file' => $filename,
                    'path' => $path,
                    'published' => is_file($path),
                ];
            },
            $definition['config'],
        );
    }

    /**
     * @param ModuleState $module
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     * @return array{status:string,schema_ready:bool,ready:bool,blockers:list<string>}
     */
    private function showReadiness(array $module, array $schemas): array
    {
        $schemaReady = !array_any(
            $schemas,
            static fn(array $schema): bool => $schema['applicable'] && !$schema['installed'],
        );
        $blockers = $module['blockers'];
        if (!$schemaReady) {
            $blockers[] = 'One or more applicable module schemas are not ready.';
        }
        $ready = $module['ready'] && $schemaReady;

        return [
            'status' => $this->showStatus($module, $schemaReady, $ready),
            'schema_ready' => $schemaReady,
            'ready' => $ready,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @param ModuleState $module */
    private function showStatus(array $module, bool $schemaReady, bool $ready): string
    {
        if (!$schemaReady && $module['enabled']) {
            return 'blocked';
        }
        if ($ready && !$module['built_in']) {
            return 'ready';
        }

        return $module['status'];
    }

    /**
     * @param ModuleState $module
     * @param array{status:string,schema_ready:bool,ready:bool,blockers:list<string>} $readiness
     * @param list<array{file:string,path:string,published:bool}> $config
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     */
    private function renderShow(array $module, array $readiness, array $config, array $schemas): void
    {
        $this->io()->table(
            ['Module', 'Status', 'Built-in', 'Direct', 'Enabled', 'Configured', 'Published', 'Ready', 'Purpose'],
            [[
                $module['name'],
                $readiness['status'],
                $module['built_in'],
                $module['direct'],
                $module['enabled'],
                $module['configured'],
                $module['config_published'],
                $readiness['ready'],
                $module['description'],
            ]],
        );
        $this->io()->writeln();
        $this->io()->table(
            ['Package', 'Constraint', 'Available', 'Direct', 'Transitive', 'Compatible', 'Version'],
            $this->packageRows($module['packages']),
        );

        $this->renderShowConfig($config);
        $this->renderShowSchemas($schemas);
    }

    /** @param array<string,PackageState> $packages @return list<array<int,mixed>> */
    private function packageRows(array $packages): array
    {
        if ($packages === []) {
            return [['Foundation', '', true, true, false, true, 'built-in']];
        }

        return array_map(
            static fn(string $package, array $state): array => [
                $package,
                $state['constraint'],
                $state['available'],
                $state['direct'],
                $state['transitive'],
                $state['compatible'] ?? '',
                $state['version'] ?? '',
            ],
            array_keys($packages),
            array_values($packages),
        );
    }

    /** @param list<array{file:string,path:string,published:bool}> $config */
    private function renderShowConfig(array $config): void
    {
        if ($config === []) {
            return;
        }

        $this->io()->writeln();
        $this->io()->table(
            ['Config', 'Published', 'Path'],
            array_map(static fn(array $entry): array => [$entry['file'], $entry['published'], $entry['path']], $config),
        );
    }

    /** @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas */
    private function renderShowSchemas(array $schemas): void
    {
        if ($schemas === []) {
            return;
        }

        $this->io()->writeln();
        $this->renderSchemas($schemas);
    }

    /**
     * Run configured schema provisioning in a new PHP process so Composer
     * package changes from this install are visible to the autoloader.
     *
     * @return array{int,list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>}
     */
    private function syncSchemasFresh(): array
    {
        $command = [
            PHP_BINARY,
            $this->projectLauncher(),
            'module:schema:sync',
            '--json',
            '--no-interaction',
        ];
        $connection = $this->option('connection');
        if ($connection !== null) {
            $command[] = '--connection=' . $connection;
        }
        $environment = $this->option('env');
        if ($environment !== null) {
            $command[] = '--env=' . $environment;
        }

        $result = new ProcessRunner()->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            captureOutput: true,
        ));
        $decoded = json_decode(trim($result->stdout), true);
        $schemas = is_array($decoded) ? $this->schemaRows($decoded['schemas'] ?? null) : [];

        if (!$result->successful() && $schemas === []) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
            $this->io()->error($detail !== '' ? $detail : 'Module schema synchronization failed.');
        }

        return [$result->exitCode, $schemas];
    }
}

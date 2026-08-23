<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;
use Infocyph\Foundation\Module\ModuleSchemaManager;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

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
        $this->application->make(ContainerCacheManager::class)->clear();
    }

    private function listing(): int
    {
        $modules = $this->manager()->all();
        if ($this->io()->machineReadable()) {
            $this->io()->json($modules);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Module', 'Status', 'Packages', 'Schemas', 'Purpose'],
            array_map(
                fn(array $module): array => [
                    $module['name'],
                    $module['status'],
                    $this->packageSummary($module['packages']),
                    $module['schemas'] === [] ? '' : implode(', ', $module['schemas']),
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

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog();
    }

    private function module(): string
    {
        return $this->argument(0) ?? throw new \LogicException('Validated module argument is unavailable.');
    }

    /** @param array<string,array{constraint:string,installed:bool,direct:bool,version:?string}> $packages */
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

    private function schemaInstall(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $schemas = $this->schemas()->install($module, $this->option('connection'));

        return $this->schemaResponse($schemas, $module, $requested, true);
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

    private function schemas(): ModuleSchemaManager
    {
        return new ModuleSchemaManager($this->application, $this->catalog());
    }

    private function show(): int
    {
        $requested = $this->module();
        $definition = $this->catalog()->resolve($requested);
        $module = null;
        foreach ($this->manager()->all() as $candidate) {
            if ($candidate['name'] === $definition['name']) {
                $module = $candidate;
                break;
            }
        }
        if (!is_array($module)) {
            throw new \LogicException(sprintf('Module "%s" is missing from the module registry.', $definition['name']));
        }

        $config = [];
        foreach ($definition['config'] as $filename) {
            $path = $this->application->configPath($filename);
            $config[] = [
                'file' => $filename,
                'path' => $path,
                'published' => is_file($path),
            ];
        }
        $schemas = $this->schemas()->status($definition['name'], $this->option('connection'));
        $data = [
            ...$module,
            'requested' => $requested,
            'config' => $config,
            'schema_status' => $schemas,
        ];

        if ($this->io()->machineReadable()) {
            $this->io()->json($data);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Module', 'Status', 'Built-in', 'Direct', 'Purpose'],
            [[$module['name'], $module['status'], $module['built_in'], $module['direct'], $module['description']]],
        );
        $this->io()->writeln();
        $this->io()->table(
            ['Package', 'Constraint', 'Installed', 'Direct', 'Version'],
            $module['packages'] === []
                ? [['Foundation', '', true, true, 'built-in']]
                : array_map(
                    static fn(string $package, array $state): array => [
                        $package,
                        $state['constraint'],
                        $state['installed'],
                        $state['direct'],
                        $state['version'] ?? '',
                    ],
                    array_keys($module['packages']),
                    array_values($module['packages']),
                ),
        );
        if ($config !== []) {
            $this->io()->writeln();
            $this->io()->table(
                ['Config', 'Published', 'Path'],
                array_map(static fn(array $entry): array => [$entry['file'], $entry['published'], $entry['path']], $config),
            );
        }
        if ($schemas !== []) {
            $this->io()->writeln();
            $this->renderSchemas($schemas);
        }

        return ExitCode::SUCCESS;
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

        $result = (new ProcessRunner())->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            captureOutput: true,
        ));
        $decoded = json_decode(trim($result->stdout), true);
        $schemas = is_array($decoded) && is_array($decoded['schemas'] ?? null)
            ? array_values(array_filter($decoded['schemas'], is_array(...)))
            : [];

        if (!$result->successful() && $schemas === []) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
            $this->io()->error($detail !== '' ? $detail : 'Module schema synchronization failed.');
        }

        return [$result->exitCode, $schemas];
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
}

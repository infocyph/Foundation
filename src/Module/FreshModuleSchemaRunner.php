<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

/**
 * @phpstan-type SchemaStatus array{
 *     name:string,
 *     module:string,
 *     applicable:bool,
 *     installed:bool,
 *     state:string,
 *     detail:string
 * }
 * @phpstan-type FreshSchemaRun array{
 *     exit_code:int,
 *     schemas:list<SchemaStatus>,
 *     error:?string
 * }
 */
final readonly class FreshModuleSchemaRunner
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ProcessRunner $processes = new ProcessRunner(),
    ) {}

    /**
     * @phpstan-return FreshSchemaRun
     */
    public function run(
        ?string $module = null,
        ?string $connection = null,
        ?string $environment = null,
        bool $applicableOnly = false,
    ): array {
        if ($module !== null && $this->catalog->resolve($module)['schemas'] === []) {
            return ['exit_code' => 0, 'schemas' => [], 'error' => null];
        }

        $result = $this->processes->run(
            $this->command($module, $connection, $environment, $applicableOnly),
            new ProcessOptions(
                cwd: $this->application->basePath(),
                captureOutput: true,
            ),
        );
        $decoded = json_decode(trim($result->stdout), true);
        $schemas = is_array($decoded) ? $this->schemaRows($decoded['schemas'] ?? null) : [];
        $error = null;

        if (!$result->successful() && $schemas === []) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
            $error = $detail !== '' ? $detail : 'Module schema synchronization failed.';
        }

        return [
            'exit_code' => $result->exitCode,
            'schemas' => $schemas,
            'error' => $error,
        ];
    }

    /**
     * @return list<string>
     */
    private function command(
        ?string $module,
        ?string $connection,
        ?string $environment,
        bool $applicableOnly,
    ): array {
        $command = [
            PHP_BINARY,
            $this->launcher(),
            $module === null ? 'module:schema:sync' : 'module:schema:install',
            '--json',
            '--no-interaction',
        ];
        if ($module !== null) {
            $command[] = $module;
        }
        if ($applicableOnly) {
            $command[] = '--applicable-only';
        }
        if ($connection !== null) {
            $command[] = '--connection=' . $connection;
        }
        if ($environment !== null) {
            $command[] = '--env=' . $environment;
        }

        return $command;
    }

    private function launcher(): string
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

    /** @phpstan-return SchemaStatus|null */
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

    /** @phpstan-return list<SchemaStatus> */
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
}

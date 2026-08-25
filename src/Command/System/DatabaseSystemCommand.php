<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\DBLayer\Monitoring\DatabaseMonitor;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Database\DatabaseMigrationManager;
use Infocyph\Foundation\Database\DBLayerFactory;

final class DatabaseSystemCommand extends SystemCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly DatabaseMigrationManager $migrations,
        private readonly DBLayerFactory $database,
    ) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'db:monitor' => $this->monitor(),
            'db:seed' => $this->seed(),
            'db:show' => $this->showDatabase(),
            'db:table' => $this->showTable(),
            'db:wipe' => $this->wipe(),
            'migrate' => $this->migrate(),
            'migrate:fresh' => $this->destructiveMigration('fresh'),
            'migrate:refresh' => $this->destructiveMigration('refresh'),
            'migrate:reset' => $this->destructiveMigration('reset'),
            'migrate:rollback' => $this->rollback(),
            'migrate:status' => $this->migrationStatus(),
            default => throw new \LogicException('Unsupported database system command.'),
        };
    }

    private static function tableValue(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function authorizeDestructive(string $operation): bool
    {
        if ($this->flag('force')) {
            return true;
        }
        if ($this->application->isProduction()) {
            $this->io()->error(sprintf(
                '%s is destructive in production; rerun with --force after explicit approval.',
                $operation,
            ));

            return false;
        }
        if (!$this->io()->interactive()) {
            $this->io()->error(sprintf('%s requires --force in non-interactive mode.', $operation));

            return false;
        }

        return $this->io()->confirm(sprintf('Run destructive %s?', $operation), false);
    }

    private function connectionName(): ?string
    {
        return $this->option('connection');
    }

    private function destructiveMigration(string $operation): int
    {
        if (!$this->authorizeDestructive('migrate:' . $operation)) {
            return ExitCode::FAILURE;
        }

        $runner = $this->migrations->runner($this->connectionName());
        $migrations = match ($operation) {
            'fresh' => $runner->fresh(true),
            'refresh' => $runner->refresh(true),
            'reset' => $runner->reset(true),
            default => throw new \LogicException('Unknown destructive migration operation.'),
        };

        return $this->migrationResult($migrations, ucfirst($operation) . ' migration operation completed.');
    }

    private function migrate(): int
    {
        $runner = $this->migrations->runner($this->connectionName());
        if ($this->flag('pretend')) {
            $preview = $runner->pretend();
            if ($this->io()->machineReadable()) {
                return $this->emit(['migrations' => $preview, 'pretend' => true]);
            }
            if ($preview === []) {
                $this->io()->info('No pending migration SQL.');

                return ExitCode::SUCCESS;
            }
            foreach ($preview as $migration => $statements) {
                $this->io()->writeln($migration . ':');
                foreach ($statements as $statement) {
                    $this->io()->writeln('  ' . $statement['sql']);
                    if ($statement['bindings'] !== []) {
                        $this->io()->writeln('    bindings=' . json_encode($statement['bindings'], JSON_THROW_ON_ERROR));
                    }
                }
            }

            return ExitCode::SUCCESS;
        }

        $migrations = $runner->run($this->flag('step'));

        return $this->migrationResult($migrations, 'Migrations completed.');
    }

    /** @param list<string> $migrations */
    private function migrationResult(array $migrations, string $message): int
    {
        if ($this->io()->machineReadable()) {
            return $this->emit(['migrations' => $migrations]);
        }
        if ($migrations === []) {
            $this->io()->info('Nothing to migrate.');

            return ExitCode::SUCCESS;
        }
        foreach ($migrations as $migration) {
            $this->io()->success($migration);
        }
        $this->io()->success($message);

        return ExitCode::SUCCESS;
    }

    private function migrationStatus(): int
    {
        $status = $this->migrations->runner($this->connectionName())->status();
        if ($this->io()->machineReadable()) {
            return $this->emit($status);
        }

        $rows = array_map(
            static fn(array $migration): array => [
                $migration['id'],
                $migration['applied'] ? 'yes' : 'no',
                $migration['batch'] ?? '',
            ],
            $status,
        );
        $this->io()->table(['Migration', 'Applied', 'Batch'], $rows);

        return ExitCode::SUCCESS;
    }

    private function monitor(): int
    {
        $connection = $this->database->connection($this->connectionName());
        $monitor = new DatabaseMonitor($connection);
        $section = strtolower($this->option('section', 'snapshot') ?? 'snapshot');
        $seconds = $this->positiveIntOption('seconds', 10);
        $data = match ($section) {
            'snapshot' => $monitor->snapshot($seconds, $this->flag('maintenance')),
            'status' => $monitor->status(),
            'sessions' => $monitor->sessions(),
            'queries', 'long-running', 'long_running_queries' => $monitor->longRunningQueries($seconds),
            'locks' => $monitor->locks(),
            'tables', 'table-metrics' => $monitor->tableMetrics(),
            'indexes', 'index-metrics' => $monitor->indexMetrics(),
            'replication' => $monitor->replication(),
            'maintenance' => $monitor->maintenance(),
            default => throw new \InvalidArgumentException(
                '--section must be snapshot|status|sessions|queries|locks|tables|indexes|replication|maintenance.',
            ),
        };

        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }

        $records = $this->recordList($data);
        if ($records !== null) {
            if ($records === []) {
                $this->io()->info('No records returned.');

                return ExitCode::SUCCESS;
            }

            $headers = array_values(array_unique(array_merge(...array_map(array_keys(...), $records))));
            if ($headers !== []) {
                $rows = array_map(
                    static fn(array $row): array => array_map(
                        static fn(string $key): bool|float|int|string|null => self::tableValue($row[$key] ?? null),
                        $headers,
                    ),
                    $records,
                );
                $this->io()->table($headers, $rows);

                return ExitCode::SUCCESS;
            }
        }

        return $this->emit($data);
    }

    private function positiveInt(string $name, string $value): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return $value === null ? $default : $this->positiveInt($name, $value);
    }

    /** @return list<array<string,mixed>>|null */
    private function recordList(mixed $data): ?array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }

        $records = [];
        foreach ($data as $row) {
            if (!is_array($row)
                || array_any(array_keys($row), static fn(int|string $key): bool => !is_string($key))
            ) {
                return null;
            }

            /** @var array<string,mixed> $row */
            $records[] = $row;
        }

        return $records;
    }

    private function rollback(): int
    {
        $runner = $this->migrations->runner($this->connectionName());
        $batch = $this->option('batch');
        $migrations = $batch !== null
            ? $runner->rollbackBatch($this->positiveInt('batch', $batch))
            : $runner->rollback($this->positiveIntOption('batches', 1));

        return $this->migrationResult($migrations, 'Rollback completed.');
    }

    private function seed(): int
    {
        $transactional = !$this->input()->hasOption('transaction') || $this->flag('transaction');
        $count = $this->migrations->seed($this->connectionName(), $transactional);

        return $this->emit(['seeded' => $count], sprintf('Ran %d database seeder(s).', $count));
    }

    private function showDatabase(): int
    {
        $connection = $this->database->connection($this->connectionName());
        $schema = new SchemaManager($connection);
        $data = [
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
            'tables' => count($schema->tables()),
        ];
        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }

        $this->io()->table(
            ['Driver', 'Database', 'Tables'],
            [[$data['driver'], $data['database'], $data['tables']]],
        );

        return ExitCode::SUCCESS;
    }

    private function showTable(): int
    {
        $table = $this->argument(0);
        if ($table === null) {
            throw new \LogicException('Validated table argument is unavailable.');
        }

        $connection = $this->database->connection($this->connectionName());
        $schema = new SchemaManager($connection);
        $exists = $schema->hasTable($table);
        $data = [
            'table' => $table,
            'exists' => $exists,
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
        ];
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } else {
            $this->io()->table(
                ['Table', 'Exists', 'Driver', 'Database'],
                [[$table, $exists, $data['driver'], $data['database']]],
            );
        }

        return $exists ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function wipe(): int
    {
        if (!$this->authorizeDestructive('db:wipe')) {
            return ExitCode::FAILURE;
        }

        $connection = $this->database->connection($this->connectionName());
        $schema = new SchemaManager($connection);
        $before = $schema->tables();
        $schema->dropAllTables(true);

        return $this->emit(
            ['dropped' => $before, 'count' => count($before)],
            sprintf('Dropped %d database table(s).', count($before)),
        );
    }
}

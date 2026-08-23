<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

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
            'db:seed' => $this->seed(),
            'db:show' => $this->showDatabase(),
            'db:table' => $this->showTable(),
            'migrate' => $this->migrate(),
            'migrate:fresh' => $this->destructiveMigration('fresh'),
            'migrate:refresh' => $this->destructiveMigration('refresh'),
            'migrate:reset' => $this->destructiveMigration('reset'),
            'migrate:rollback' => $this->rollback(),
            'migrate:status' => $this->migrationStatus(),
            default => throw new \LogicException('Unsupported database system command.'),
        };
    }

    private function connectionName(): ?string
    {
        return $this->option('connection');
    }

    private function destructiveMigration(string $operation): int
    {
        if (!$this->authorizeDestructive($operation)) {
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
        $migrations = $this->migrations
            ->runner($this->connectionName())
            ->run($this->flag('step'));

        return $this->migrationResult($migrations, 'Migrations completed.');
    }

    private function migrationResult(array $migrations, string $message): int
    {
        if ($this->io()->machineReadable()) {
            return $this->emit(['migrations' => array_values($migrations)]);
        }
        if ($migrations === []) {
            $this->io()->info('Nothing to migrate.');

            return ExitCode::SUCCESS;
        }
        foreach ($migrations as $migration) {
            $this->io()->success((string) $migration);
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

    private function rollback(): int
    {
        $batches = $this->positiveIntOption('batches', 1);
        $migrations = $this->migrations
            ->runner($this->connectionName())
            ->rollback($batches);

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

    private function authorizeDestructive(string $operation): bool
    {
        if ($this->flag('force')) {
            return true;
        }
        if ($this->application->isProduction()) {
            $this->io()->error(sprintf(
                'migrate:%s is destructive in production; rerun with --force after explicit approval.',
                $operation,
            ));

            return false;
        }
        if (!$this->io()->interactive()) {
            $this->io()->error(sprintf('migrate:%s requires --force in non-interactive mode.', $operation));

            return false;
        }

        return $this->io()->confirm(sprintf('Run destructive migrate:%s?', $operation), false);
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }

        return (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DatabaseMigrationManager;

abstract class AbstractDatabaseCommand extends AbstractFoundationCommand
{
    public function __construct(protected readonly Application $application) {}

    final protected static function defineDestructiveMigration(
        CommandDefinition $command,
        string $name,
        string $description,
    ): void {
        $command
            ->name($name)
            ->description($description)
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(Option::flag('force')->description('Explicitly authorize this destructive operation.'))
            ->option(self::jsonOption());
    }

    final protected function connection(): ?string
    {
        return $this->options()->nullableString('connection');
    }

    final protected function migrations(): DatabaseMigrationManager
    {
        return $this->application->db()->migrations();
    }

    final protected function runDestructiveMigration(string $command): int
    {
        if (!$this->options()->bool('force')) {
            $this->io()->error($command . ' requires --force.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $runner = $this->migrations()->runner($this->connection());
            [$resultKey, $result] = match ($command) {
                'migrate:fresh' => ['ran', $runner->fresh(true)],
                'migrate:refresh' => ['ran', $runner->refresh(true)],
                'migrate:reset' => ['rolled_back', $runner->reset(true)],
                default => throw new \LogicException('Unsupported destructive migration command: ' . $command),
            };
        } catch (\Throwable $exception) {
            $this->io()->error($command . ' failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report([$resultKey => $result, 'count' => count($result)]);

        return ExitCode::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\DatabaseInspector;

final class DatabaseShowCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly DatabaseInspector $database) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('db:show')
            ->description('Display database connection, version, and table information.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $report = $this->database->database($this->options()->nullableString('connection'));
        } catch (\Throwable $exception) {
            $this->io()->error('db:show failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        if ($this->options()->bool('json')) {
            $this->report($report);
        } else {
            $tables = $report['tables'];
            $this->io()->details([
                'connection' => $report['connection'],
                'driver' => $report['driver'],
                'database' => $report['database'],
                'version' => $report['version'],
                'connected' => $report['connected'],
                'table_prefix' => $report['table_prefix'],
                'table_count' => $report['table_count'],
            ]);
            $this->io()->listing($tables);
        }

        return ExitCode::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\DatabaseInspector;

final class DatabaseTableCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly DatabaseInspector $database) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('db:table')
            ->description('Inspect database table columns, indexes, and foreign keys.')
            ->argument(Argument::required('table')->description('Table identifier, for example: users.'))
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'));
    }

    protected function handle(): int
    {
        try {
            $report = $this->database->table(
                $this->arguments()->string('table'),
                $this->options()->nullableString('connection'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('db:table failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report($report);

        return ExitCode::SUCCESS;
    }
}

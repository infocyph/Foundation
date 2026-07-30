<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Session\SessionDatabaseSchema;

final class SessionSchemaInstallCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('session:schema:install')
            ->description('Create the configured DBLayer browser-session table.')
            ->option(Option::value('connection')->description('DBLayer connection name, for example: mysql.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $schema = $this->application->make(SessionDatabaseSchema::class);
            $connection = $this->options()->nullableString('connection');
            $schema->install($connection);
            $report = $schema->readiness($connection);
        } catch (\Throwable $exception) {
            $this->io()->error('session:schema:install failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report($report);

        return $report['installed'] ? ExitCode::SUCCESS : ExitCode::INVALID_USAGE;
    }
}

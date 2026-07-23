<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;

final class AuthSchemaInstallCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('auth:schema:install')
            ->description('Create missing Foundation authentication tables.')
            ->option(Option::value('connection')->description('Database connection name, for example: default.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $connection = $this->options()->nullableString('connection');
            $installer = $this->application->db()->authSchema();
            $installer->install($connection);
            $schema = $installer->readiness($connection);
        } catch (\Throwable $exception) {
            $this->io()->error('auth:schema:install failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report($schema);

        return $schema['installed'] === true
            ? ExitCode::SUCCESS
            : ExitCode::INVALID_USAGE;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;

final class AuthSchemaStatusCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('auth:schema:status')
            ->description('Report the Foundation authentication schema status.')
            ->option(Option::value('connection')->description('Database connection name, for example: default.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $schema = $this->application->db()->authSchema()->readiness(
                $this->options()->nullableString('connection'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('auth:schema:status failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report($schema);

        return $schema['installed'] === true
            ? ExitCode::SUCCESS
            : ExitCode::INVALID_USAGE;
    }
}

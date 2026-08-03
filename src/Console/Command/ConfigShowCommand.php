<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\Support\ConfigurationRedactor;

final class ConfigShowCommand extends AbstractFoundationCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly ConfigurationRedactor $redactor,
    ) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('config:show')
            ->description('Display resolved configuration with sensitive values redacted.')
            ->argument(Argument::required('key')->description(
                'Dot-notation configuration key, for example: database.connections.sqlite.',
            ));
    }

    protected function handle(): int
    {
        $key = $this->arguments()->string('key');
        if (!$this->application->config()->has($key)) {
            $this->io()->error(sprintf('Configuration key "%s" does not exist.', $key));

            return ExitCode::INVALID_USAGE;
        }

        $this->report([
            'key' => $key,
            'value' => $this->redactor->redact($this->application->config()->get($key), $key),
        ]);

        return ExitCode::SUCCESS;
    }
}

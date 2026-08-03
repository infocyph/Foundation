<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\EnvironmentSecretManager;

final class SecretGenerateCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly EnvironmentSecretManager $secrets) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('secret:generate')
            ->description('Generate the application authentication secret in .env.')
            ->option(Option::flag('force')->description(
                'Rotate an existing AUTH_TOKEN_SECRET. Allowed values: present|absent.',
            ));
    }

    protected function handle(): int
    {
        try {
            $path = $this->secrets->generate($this->options()->bool('force'));
        } catch (\Throwable $exception) {
            $this->io()->error('secret:generate failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Authentication secret generated securely at: ' . $path);

        return ExitCode::SUCCESS;
    }
}

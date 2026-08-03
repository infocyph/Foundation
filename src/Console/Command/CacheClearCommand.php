<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;

final class CacheClearCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('cache:clear')
            ->description('Clear the selected CacheLayer application store.')
            ->option(Option::value('store')->description(
                'Configured cache store name; null uses cache.default.',
            ));
    }

    protected function handle(): int
    {
        $store = $this->options()->nullableString('store');

        try {
            $cleared = $this->application->cache()->store($store)->clearCache();
        } catch (\Throwable $exception) {
            $this->io()->error('cache:clear failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        if (!$cleared) {
            $this->io()->error('cache:clear failed: the cache store rejected the clear operation.');

            return ExitCode::FAILURE;
        }

        $this->io()->success(sprintf('Application cache cleared: %s', $store ?? 'default'));

        return ExitCode::SUCCESS;
    }
}

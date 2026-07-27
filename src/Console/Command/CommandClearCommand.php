<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\CommandCacheManager;

final class CommandClearCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly CommandCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('command:clear')
            ->description('Remove the compiled command manifest.')
            ->option(Option::value('path', 'bootstrap/cache/console/commands.php')->description(
                'Manifest path, for example: bootstrap/cache/console/commands.php.',
            ));
    }

    protected function handle(): int
    {
        try {
            $path = $this->cache->path($this->options()->string('path'));
            $removed = $this->cache->clear($path);
        } catch (\Throwable $exception) {
            $this->io()->error('command:clear failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $removed
            ? $this->io()->success('Command manifest cleared: ' . $path)
            : $this->io()->info('Nothing to clear at: ' . $path);

        return ExitCode::SUCCESS;
    }
}

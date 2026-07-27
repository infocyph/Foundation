<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\CommandCacheManager;

final class CommandCacheCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly CommandCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('command:cache')
            ->description('Compile system and project command metadata.')
            ->option(Option::value('path', 'bootstrap/cache/console/commands.php')->description(
                'Manifest path, for example: bootstrap/cache/console/commands.php.',
            ))
            ->option(Option::value('routes', 'routes/console.php')->description(
                'Project command map, for example: routes/console.php.',
            ));
    }

    protected function handle(): int
    {
        try {
            $path = $this->cache->write(
                $this->options()->string('path'),
                $this->options()->string('routes'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('command:cache failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Command manifest ready at: ' . $path);

        return ExitCode::SUCCESS;
    }
}

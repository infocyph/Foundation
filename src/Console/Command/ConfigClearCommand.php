<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;

final class ConfigClearCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ConfigCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('config:clear')
            ->description('Remove single or sharded Foundation configuration caches.')
            ->option(Option::value('path', 'bootstrap/cache/config')->description(
                'Cache directory, for example: bootstrap/cache/config.',
            ));
    }

    protected function handle(): int
    {
        $path = $this->cache->path($this->options()->string('path'));

        try {
            $cleared = $this->cache->clear($path);
        } catch (\Throwable $exception) {
            $this->io()->error('config:clear failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        if ($cleared) {
            $this->io()->success('Configuration cache cleared: ' . $path);
        } else {
            $this->io()->info('Nothing to clear at: ' . $path);
        }

        return ExitCode::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;

final class ConfigCacheCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ConfigCacheManager $cache) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('config:cache')
            ->description('Compile the Foundation configuration cache.')
            ->option(Option::value('path', 'bootstrap/cache/config')->description(
                'Cache directory, for example: bootstrap/cache/config.',
            ))
            ->option(Option::value('type')->description(
                'Cache layout. Allowed values: sharded|single; defaults to app.config_cache.type.',
            ));
    }

    protected function handle(): int
    {
        $path = $this->cache->path($this->options()->string('path'));

        try {
            $type = $this->cache->write($path, $this->options()->nullableString('type'));
        } catch (\Throwable $exception) {
            $this->io()->error('config:cache failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success(sprintf('Configuration cached (%s): %s', $type, $path));

        return ExitCode::SUCCESS;
    }
}

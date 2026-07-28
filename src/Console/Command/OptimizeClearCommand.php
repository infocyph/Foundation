<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\CommandCacheManager;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class OptimizeClearCommand extends AbstractFoundationCommand
{
    public function __construct(
        private readonly ConfigCacheManager $config,
        private readonly RouteCacheManager $routes,
        private readonly CommandCacheManager $commands,
        private readonly ScheduleManager $schedule,
    ) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('optimize:clear')
            ->description('Remove configuration, route, and command caches.');
    }

    protected function handle(): int
    {
        try {
            $this->config->clear('bootstrap/cache/config');
            $matcher = $this->routes->matcher(null);
            $this->routes->clear($matcher, $this->routes->cachePath(null), true);
            $this->commands->clear('bootstrap/cache/console/commands.php');
            $this->schedule->clear();
        } catch (\Throwable $exception) {
            $this->io()->error('optimize:clear failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Application caches cleared.');

        return ExitCode::SUCCESS;
    }
}

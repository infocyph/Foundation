<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\CommandCacheManager;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;
use Infocyph\Foundation\Console\Support\ModuleManifestManager;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class OptimizeCommand extends AbstractFoundationCommand
{
    public function __construct(
        private readonly ConfigCacheManager $config,
        private readonly RouteCacheManager $routes,
        private readonly CommandCacheManager $commands,
        private readonly ScheduleManager $schedule,
        private readonly ModuleManifestManager $modules,
    ) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('optimize')
            ->description('Compile configuration, route, and command caches for deployment.');
    }

    protected function handle(): int
    {
        try {
            $configType = $this->config->write('bootstrap/cache/config');
            $matcher = $this->routes->matcher(null);
            $routePath = $this->routes->write($matcher, $this->routes->cachePath(null));
            $commandPath = $this->commands->write('bootstrap/cache/console/commands.php');
            $schedulePath = $this->schedule->configured() ? $this->schedule->write() : 'not configured';
            $modulePath = $this->modules->write();
        } catch (\Throwable $exception) {
            $this->io()->error('optimize failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success(sprintf(
            'Application caches ready: config=%s routes=%s commands=%s schedule=%s modules=%s',
            $configType,
            $routePath,
            $commandPath,
            $schedulePath,
            $modulePath,
        ));

        return ExitCode::SUCCESS;
    }
}

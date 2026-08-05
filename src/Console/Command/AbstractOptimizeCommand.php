<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Foundation\Console\Support\CommandCacheManager;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;
use Infocyph\Foundation\Console\Support\ModuleManifestManager;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Foundation\Console\Support\ScheduleManager;
use Infocyph\Foundation\Container\ContainerCacheManager;

abstract class AbstractOptimizeCommand extends AbstractFoundationCommand
{
    public function __construct(
        protected readonly ConfigCacheManager $config,
        protected readonly RouteCacheManager $routes,
        protected readonly CommandCacheManager $commands,
        protected readonly ScheduleManager $schedule,
        protected readonly ModuleManifestManager $modules,
        protected readonly ContainerCacheManager $container,
    ) {}

    /**
     * Remove the complete optimization set so an uncached application remains usable.
     */
    protected function clearArtifacts(): void
    {
        $this->config->clear('bootstrap/cache/config');
        $this->routes->clearAll();
        $this->commands->clear('bootstrap/cache/console/commands.php');
        $this->schedule->clear();
        $this->modules->clear();
        $this->container->clear();
    }

    /**
     * Best-effort rollback that preserves the original optimization failure.
     */
    protected function rollbackArtifacts(): void
    {
        try {
            $this->clearArtifacts();
        } catch (\Throwable) {
            // The original cache-generation failure remains the actionable error.
        }
    }
}

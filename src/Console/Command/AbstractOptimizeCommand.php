<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Foundation\Console\Support\CommandCacheManager;
use Infocyph\Foundation\Console\Support\ConfigCacheManager;
use Infocyph\Foundation\Console\Support\ModuleManifestManager;
use Infocyph\Foundation\Console\Support\RouteCacheManager;
use Infocyph\Foundation\Console\Support\ScheduleManager;

abstract class AbstractOptimizeCommand extends AbstractFoundationCommand
{
    public function __construct(
        protected readonly ConfigCacheManager $config,
        protected readonly RouteCacheManager $routes,
        protected readonly CommandCacheManager $commands,
        protected readonly ScheduleManager $schedule,
        protected readonly ModuleManifestManager $modules,
    ) {}
}

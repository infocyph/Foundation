<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Composer\InstalledVersions;
use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\Support\ModuleManager;

final class AboutCommand extends AbstractFoundationCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly ModuleManager $modules,
    ) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('about')
            ->description('Display application, runtime, optimization, and module information.')
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $installed = [];
        foreach ($this->modules->all() as $module) {
            if ($module['installed']) {
                $installed[] = $module['name'] . ':' . ($module['version'] ?? 'built-in');
            }
        }

        $report = [
            'application' => $this->application->config()->get('app.name', 'Foundation Application'),
            'environment' => $this->application->environment() ?? 'unknown',
            'debug' => (bool) $this->application->config()->get('app.debug', false),
            'php' => PHP_VERSION,
            'foundation' => InstalledVersions::getPrettyVersion('infocyph/foundation') ?? 'dev-main',
            'runtime' => 'console',
            'configuration_cached' => $this->application->config()->isCompiled(),
            'configuration_cache' => $this->application->config()->cacheDirectory(),
            'route_matcher' => $this->application->config()->get('router.matcher', 'fused'),
            'modules' => $installed,
        ];

        if ($this->options()->bool('json')) {
            $this->report($report);

            return ExitCode::SUCCESS;
        }

        $this->io()->details([
            'application' => is_scalar($report['application']) || $report['application'] === null
                ? $report['application']
                : 'Foundation Application',
            'environment' => $report['environment'],
            'debug' => $report['debug'],
            'php' => $report['php'],
            'foundation' => $report['foundation'],
            'runtime' => $report['runtime'],
            'configuration_cached' => $report['configuration_cached'],
            'configuration_cache' => $report['configuration_cache'],
            'route_matcher' => is_scalar($report['route_matcher']) || $report['route_matcher'] === null
                ? $report['route_matcher']
                : 'fused',
            'modules' => $installed === [] ? 'none' : implode(', ', $installed),
        ]);

        return ExitCode::SUCCESS;
    }
}

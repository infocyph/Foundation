<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;

final class OptimizeCommand extends AbstractOptimizeCommand
{
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
            $container = $this->container->compileWeb();
            $manifestPath = $this->container->publishManifest([
                'config' => $configType,
                'routes' => $routePath,
                'commands' => $commandPath,
                'schedule' => $schedulePath,
                'modules' => $modulePath,
            ], $container);
        } catch (\Throwable $exception) {
            $this->rollbackArtifacts();
            $this->io()->error('optimize failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success(sprintf(
            'Application caches ready: config=%s routes=%s commands=%s schedule=%s modules=%s container=%d manifest=%s',
            $configType,
            $routePath,
            $commandPath,
            $schedulePath,
            $modulePath,
            count($container['compiled']),
            $manifestPath,
        ));

        return ExitCode::SUCCESS;
    }
}

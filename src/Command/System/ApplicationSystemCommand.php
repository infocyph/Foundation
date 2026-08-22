<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Command\CommandCacheManager;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Config\ConfigurationRedactor;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Routing\RouteCacheManager;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Scheduling\ScheduleManager;
use Infocyph\Foundation\Security\EnvironmentSecretManager;

final class ApplicationSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'about' => $this->about(),
            'app:install' => $this->install(),
            'app:ready' => $this->ready(),
            'cache:clear' => $this->clearCache(),
            'command:cache' => $this->commandCache(),
            'command:clear' => $this->commandClear(),
            'config:cache' => $this->configCache(),
            'config:clear' => $this->configClear(),
            'config:show' => $this->configShow(),
            'env:show' => $this->environment(),
            'optimize' => $this->optimize(),
            'optimize:clear' => $this->optimizeClear(),
            'optimize:report' => $this->optimizeReport(),
            'secret:generate' => $this->generateSecret(),
            'serve' => $this->serve(),
            default => throw new \LogicException('Unsupported application system command.'),
        };
    }

    private function about(): int
    {
        $modules = [];
        foreach ((new ModuleCatalog())->all() as $name => $module) {
            $package = $module['package'];
            $modules[$name] = [
                'package' => $package,
                'installed' => ($module['built_in'] ?? false) === true
                    || $package === null
                    || InstalledVersions::isInstalled($package),
                'version' => is_string($package) && InstalledVersions::isInstalled($package)
                    ? InstalledVersions::getPrettyVersion($package)
                    : null,
            ];
        }

        $data = [
            'foundation' => InstalledVersions::isInstalled('infocyph/foundation')
                ? InstalledVersions::getPrettyVersion('infocyph/foundation')
                : 'dev',
            'php' => PHP_VERSION,
            'runtime' => $this->application->runtimeMode()->value,
            'environment' => $this->application->environment(),
            'base_path' => $this->application->basePath(),
            'modules' => $modules,
        ];
        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }

        $this->io()->table(
            ['Foundation', 'PHP', 'Runtime', 'Environment'],
            [[$data['foundation'], $data['php'], $data['runtime'], $data['environment'] ?? '']],
        );
        $rows = [];
        foreach ($modules as $name => $module) {
            $rows[] = [$name, $module['installed'], $module['version'] ?? ''];
        }
        $this->io()->writeln();
        $this->io()->table(['Module', 'Installed', 'Version'], $rows);

        return ExitCode::SUCCESS;
    }

    private function clearCache(): int
    {
        $store = $this->application->make(CacheManager::class)->store($this->option('store'));
        if (!$store->clear()) {
            $this->io()->error('Cache backend rejected the clear operation.');

            return ExitCode::FAILURE;
        }

        $this->io()->success('Cache cleared.');

        return ExitCode::SUCCESS;
    }

    private function commandCache(): int
    {
        $path = (new CommandCacheManager($this->application))->write();

        return $this->emit(['path' => $path], 'Command manifest cached: ' . $path);
    }

    private function commandClear(): int
    {
        $removed = (new CommandCacheManager($this->application))->clear();

        return $this->emit(['removed' => $removed], $removed ? 'Command manifest cleared.' : 'Command manifest is already clear.');
    }

    private function configCache(): int
    {
        $type = (new ConfigCacheManager($this->application))->write();

        return $this->emit(['type' => $type], 'Configuration cached using ' . $type . '.');
    }

    private function configClear(): int
    {
        $removed = (new ConfigCacheManager($this->application))->clear();

        return $this->emit(['removed' => $removed], $removed ? 'Configuration cache cleared.' : 'Configuration cache is already clear.');
    }

    private function configShow(): int
    {
        $key = $this->argument(0);
        if ($key === null) {
            throw new \LogicException('Validated configuration key is unavailable.');
        }
        if (!$this->application->config()->has($key)) {
            $this->io()->error(sprintf('Configuration key "%s" is not defined.', $key));

            return ExitCode::FAILURE;
        }

        $value = (new ConfigurationRedactor())->redact($this->application->config()->get($key), $key);

        return $this->emit(['key' => $key, 'value' => $value], is_scalar($value) || $value === null
            ? sprintf('%s = %s', $key, $value === null ? 'null' : var_export($value, true))
            : null);
    }

    private function environment(): int
    {
        $environment = $this->application->environment() ?? 'unknown';

        return $this->emit(['environment' => $environment], $environment);
    }

    private function generateSecret(): int
    {
        $manager = new EnvironmentSecretManager($this->application, new ConfigCacheManager($this->application));
        $path = $manager->generate($this->flag('force'));

        return $this->emit(['path' => $path], 'Application secret updated in ' . $path . '.');
    }

    private function install(): int
    {
        foreach (['cache', 'logs', 'sessions', 'uploads'] as $directory) {
            $path = $this->application->storagePath($directory);
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Unable to create runtime directory "%s".', $path));
            }
        }
        $cache = $this->application->bootstrapPath('cache');
        if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new \RuntimeException(sprintf('Unable to create bootstrap cache directory "%s".', $cache));
        }

        $path = (new EnvironmentSecretManager(
            $this->application,
            new ConfigCacheManager($this->application),
        ))->install();

        return $this->emit(['environment' => $path], 'Application runtime structure installed.');
    }

    private function optimize(): int
    {
        $config = new ConfigCacheManager($this->application);
        $routes = new RouteCacheManager($this->application);
        $commands = new CommandCacheManager($this->application);
        $schedule = new ScheduleManager($this->application);
        $containers = $this->application->make(ContainerCacheManager::class);

        $artifacts = [
            'config' => $config->write(),
            'routes' => $routes->write($routes->configuredMatcher(), $routes->cachePath(null)),
            'commands' => $commands->write(),
            'schedule' => $schedule->write(),
        ];
        $compiled = $containers->compileAll();
        $manifest = $containers->publishManifest($artifacts, $compiled);

        return $this->emit(
            ['manifest' => $manifest, 'artifacts' => $artifacts, 'containers' => $compiled],
            'Foundation runtime artifacts optimized.',
        );
    }

    private function optimizeClear(): int
    {
        $results = [
            'config' => (new ConfigCacheManager($this->application))->clear(),
            'routes' => (new RouteCacheManager($this->application))->clearAll(),
            'commands' => (new CommandCacheManager($this->application))->clear(),
            'schedule' => (new ScheduleManager($this->application))->clear(),
            'containers' => $this->application->make(ContainerCacheManager::class)->clear(),
        ];

        return $this->emit($results, array_any($results, static fn(bool $removed): bool => $removed)
            ? 'Optimized runtime artifacts cleared.'
            : 'Optimized runtime artifacts are already clear.');
    }

    private function optimizeReport(): int
    {
        $container = $this->application->make(ContainerCacheManager::class);
        $containers = [];
        foreach (RuntimeMode::cases() as $runtime) {
            $containers[$runtime->value] = $container->status($runtime);
        }
        $data = [
            'config' => is_dir($this->application->bootstrapPath('cache/config')),
            'routes' => RouteCachePath::isWarm($this->application->config()),
            'commands' => is_file($this->application->bootstrapPath('cache/commands.php')),
            'schedule' => is_file($this->application->bootstrapPath('cache/schedule.php')),
            'optimize_manifest' => is_file($this->application->bootstrapPath('cache/optimize.php')),
            'containers' => $containers,
        ];

        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }
        $this->io()->table(
            ['Artifact', 'Ready'],
            [
                ['config', $data['config']],
                ['routes', $data['routes']],
                ['commands', $data['commands']],
                ['schedule', $data['schedule']],
                ['optimize manifest', $data['optimize_manifest']],
            ],
        );
        $this->io()->writeln();
        $this->io()->table(
            ['Runtime', 'Ready', 'Compiled', 'Activation'],
            array_map(
                static fn(array $status): array => [
                    $status['runtime'],
                    $status['ready'],
                    $status['compiled'],
                    $status['activation'],
                ],
                array_values($containers),
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function ready(): int
    {
        $report = (new ReadinessReport($this->application))->generate();
        if ($this->io()->machineReadable()) {
            $this->io()->json($report);
        } else {
            $rows = [];
            foreach ($report['checks'] as $name => $check) {
                $rows[] = [$name, $check['ready'], $check['detail']];
            }
            $this->io()->table(['Check', 'Ready', 'Detail'], $rows);
        }

        return $report['ready'] ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function serve(): int
    {
        $host = $this->option('host', '127.0.0.1') ?? '127.0.0.1';
        $port = $this->positivePort($this->option('port', '8000') ?? '8000');
        $public = $this->application->publicPath();
        if (!is_dir($public)) {
            throw new \RuntimeException(sprintf('Public directory "%s" does not exist.', $public));
        }

        $endpoint = sprintf('http://%s:%d', $host, $port);
        if ($this->flag('dry-run')) {
            return $this->emit(['endpoint' => $endpoint, 'document_root' => $public], sprintf('%s -> %s', $endpoint, $public));
        }

        $this->io()->info('Development server listening on ' . $endpoint);

        return (new ProcessRunner())->run(
            [PHP_BINARY, '-S', $host . ':' . $port, '-t', $public],
            new ProcessOptions(
                cwd: $this->application->basePath(),
                interactive: true,
            ),
        )->exitCode;
    }

    private function positivePort(string $value): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1 || (int) $value > 65535) {
            throw new \InvalidArgumentException('--port must be an integer between 1 and 65535.');
        }

        return (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Command\CommandCacheManager;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Config\ConfigurationRedactor;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Security\EnvironmentSecretManager;
use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\SwooleOptions;

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
        foreach (new ModuleManager(
            $this->application,
            new ModuleCatalog(),
        )->all() as $module) {
            $modules[$module['name']] = $module;
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
            $packages = [];
            foreach ($module['packages'] as $package => $state) {
                $packages[] = $package . ' ' . ($state['version'] ?? $state['constraint']);
            }
            $rows[] = [
                $name,
                $module['status'],
                $packages === [] ? 'Foundation' : implode(', ', $packages),
            ];
        }
        $this->io()->writeln();
        $this->io()->table(['Module', 'Status', 'Packages'], $rows);

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
        $path = new CommandCacheManager($this->application)->write();

        return $this->emit(['path' => $path], 'Command manifest cached: ' . $path);
    }

    private function commandClear(): int
    {
        $removed = new CommandCacheManager($this->application)->clear();

        return $this->emit(['removed' => $removed], $removed ? 'Command manifest cleared.' : 'Command manifest is already clear.');
    }

    private function configCache(): int
    {
        $type = new ConfigCacheManager($this->application)->write();

        return $this->emit(['type' => $type], 'Configuration cached using ' . $type . '.');
    }

    private function configClear(): int
    {
        $removed = new ConfigCacheManager($this->application)->clear();

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

        $value = new ConfigurationRedactor()->redact($this->application->config()->get($key), $key);

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

        $path = new EnvironmentSecretManager(
            $this->application,
            new ConfigCacheManager($this->application),
        )->install();

        return $this->emit(['environment' => $path], 'Application runtime structure installed.');
    }

    private function optimize(): int
    {
        $config = $this->releaseConfig();
        $releaseRoot = FoundationReleaseBootstrap::resolveReleaseRoot($config);
        $release = new FoundationReleaseCompiler()->buildAndActivate(
            config: $config,
            releaseRoot: $releaseRoot,
            capabilities: $this->releaseCapabilities(),
        );

        return $this->emit(
            [
                'release_root' => $releaseRoot,
                'generation' => $release['generation'],
                'manifest' => $release['manifest'],
                'manifest_sha256' => $release['manifest_sha256'],
                'active_pointer' => $release['active_pointer'],
            ],
            sprintf('Foundation release generation %s optimized and activated.', $release['generation']),
        );
    }

    private function optimizeClear(): int
    {
        $releaseRoot = FoundationReleaseBootstrap::resolveReleaseRoot($this->releaseConfig());
        $removed = new FoundationReleaseCompiler()->clear($releaseRoot);

        return $this->emit(
            ['release_root' => $releaseRoot, 'removed' => $removed],
            $removed
                ? 'Foundation release generations cleared.'
                : 'Foundation release generations are already clear.',
        );
    }

    private function optimizeReport(): int
    {
        $releaseRoot = FoundationReleaseBootstrap::resolveReleaseRoot($this->releaseConfig());
        $data = new FoundationReleaseCompiler()->status($releaseRoot);

        if ($this->io()->machineReadable()) {
            return $this->emit($data);
        }
        $this->io()->table(
            ['Release root', 'Ready', 'Generation', 'Manifest SHA-256'],
            [[
                $data['release_root'],
                $data['ready'],
                $data['generation'] ?? '',
                $data['manifest_sha256'] ?? '',
            ]],
        );

        return ExitCode::SUCCESS;
    }

    private function positivePort(string $value): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1 || (int) $value > 65535) {
            throw new \InvalidArgumentException('--port must be an integer between 1 and 65535.');
        }

        return (int) $value;
    }

    private function ready(): int
    {
        $report = new ReadinessReport($this->application)->generate();
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

    /** @return array<string, array<string, bool>> */
    private function releaseCapabilities(): array
    {
        if (!$this->application->config()->has('app.capabilities')) {
            throw new \RuntimeException(
                'Production optimization requires an explicit app.capabilities list or map.',
            );
        }

        $configured = $this->application->config()->get('app.capabilities');
        if (!is_array($configured)) {
            throw new \UnexpectedValueException('app.capabilities must be a capability list or map.');
        }
        $resolved = FoundationBuildContext::fromConfig(
            $this->application->config(),
            RuntimeMode::Cli,
            $configured,
        )->capabilities;

        $capabilities = [];
        foreach (RuntimeMode::cases() as $runtime) {
            $capabilities[$runtime->value] = $resolved;
        }

        return $capabilities;
    }

    /** @return array<string, mixed> */
    private function releaseConfig(): array
    {
        $config = $this->application->config()->all();
        $config['_config_cache'] = false;
        $config['base_path'] = $this->application->basePath();
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $app['base_path'] = $this->application->basePath();
        $config['app'] = $app;

        return $config;
    }

    private function serve(): int
    {
        $options = $this->serveOptions();
        $selection = new RuntimeSelector()->select($options, new RuntimeEnvironmentProbe()->probe());
        $host = $options->swoole->host;
        $port = $options->swoole->port;
        $public = $this->application->publicPath();
        if (!is_dir($public)) {
            throw new \RuntimeException(sprintf('Public directory "%s" does not exist.', $public));
        }

        $address = sprintf('tcp://%s:%d', str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host, $port);
        $server = $this->serveServer($address, $options, $selection);
        $endpoint = str_replace('tcp://', 'http://', $address);
        if ($this->flag('dry-run')) {
            return $this->emit([
                'endpoint' => in_array($selection->driver, [RuntimeDriver::NATIVE, RuntimeDriver::SWOOLE], true) ? $endpoint : null,
                'document_root' => $public,
                'requested_runtime' => $options->driver->value,
                'runtime' => $selection->driver->value,
                'workers' => match ($selection->driver) {
                    RuntimeDriver::NATIVE => $server?->workers,
                    RuntimeDriver::SWOOLE => $options->swoole->workerCount,
                    default => null,
                },
                'protocol_policy' => $server?->http1->adaptive->mode->value,
                'warnings' => $selection->warnings,
            ], sprintf('Runwire %s: %s', $selection->driver->value, $endpoint));
        }

        $config = $this->releaseConfig();
        $releaseRoot = FoundationReleaseBootstrap::resolveReleaseRoot($config);
        $release = new FoundationReleaseCompiler()->buildAndActivate(
            config: $config,
            releaseRoot: $releaseRoot,
            capabilities: $this->releaseCapabilities(),
        );

        $this->io()->info(sprintf(
            'Runwire serving %s using Foundation generation %s.',
            $endpoint,
            $release['generation'],
        ));

        $bootstrap = new FoundationReleaseBootstrap($releaseRoot, $release['manifest_sha256']);
        $runtime = Runtime::create($options);
        if ($server === null) {
            $runtime->serveApplication($bootstrap);
        } else {
            $runtime->listen($server->withApplicationFactory($bootstrap))->run();
        }

        return ExitCode::SUCCESS;
    }

    private function serveOptions(): RuntimeOptions
    {
        $host = $this->option('host', '127.0.0.1') ?? '127.0.0.1';
        $port = $this->positivePort($this->option('port', '8000') ?? '8000');
        if ($host === '' || str_contains($host, '/') || str_contains($host, "\0")) {
            throw new \InvalidArgumentException('--host must be a non-empty host name or IP address.');
        }
        $driver = RuntimeDriver::tryFrom($this->option('runtime') ?? RuntimeDriver::AUTO->value)
            ?? throw new \InvalidArgumentException('Invalid --runtime; see serve --help for supported Runwire runtimes.');
        $workerOption = $this->option('workers');
        if ($workerOption !== null && preg_match('/^\d+$/D', $workerOption) !== 1) {
            throw new \InvalidArgumentException('--workers must be a non-negative integer.');
        }

        return new RuntimeOptions(
            driver: $driver,
            swoole: new SwooleOptions(host: trim($host, '[]'), port: $port, workerCount: (int) ($workerOption ?? 0)),
        );
    }

    private function servePolicy(AdaptiveProtocolPolicy $defaults, AdaptivePolicyMode $mode): AdaptiveProtocolPolicy
    {
        return new AdaptiveProtocolPolicy(
            mode: $mode,
            lowWatermarkBasisPoints: $defaults->lowWatermarkBasisPoints,
            highWatermarkBasisPoints: $defaults->highWatermarkBasisPoints,
            transitionSamples: $defaults->transitionSamples,
            ewmaNumerator: $defaults->ewmaNumerator,
            ewmaDenominator: $defaults->ewmaDenominator,
        );
    }

    private function serveServer(string $address, RuntimeOptions $options, RuntimeSelection $selection): ?Server
    {
        $policy = AdaptivePolicyMode::tryFrom($this->option('protocol-policy') ?? AdaptivePolicyMode::FIXED->value)
            ?? throw new \InvalidArgumentException('Invalid --protocol-policy; see serve --help for Runwire policies.');
        if ($selection->driver !== RuntimeDriver::NATIVE) {
            if ($this->option('protocol-policy') !== null) {
                throw new \InvalidArgumentException('--protocol-policy requires the native Runwire protocol engine.');
            }
            if ($selection->driver !== RuntimeDriver::SWOOLE
                && ($this->option('workers') !== null || $this->option('host') !== null || $this->option('port') !== null)
            ) {
                throw new \InvalidArgumentException('The selected host owns its bind address and workers; configure them in that host.');
            }

            return null;
        }
        $workers = $this->option('workers') === null ? 1 : $options->swoole->workerCount;
        if (!$selection->capabilities->ownsWorkerPool && $workers > 1) {
            throw new \InvalidArgumentException('Portable Runwire serving supports at most one worker; prefork capabilities are unavailable.');
        }
        $http1 = new Http1Limits();
        $http2 = new Http2Limits();

        return new Server(
            name: 'web',
            address: $address,
            handler: static function (): void {},
            workers: $workers,
            http1: new Http1Limits(adaptive: $this->servePolicy($http1->adaptive, $policy)),
            http2: new Http2Limits(adaptive: $this->servePolicy($http2->adaptive, $policy)),
        );
    }
}

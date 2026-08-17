<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class ContainerCacheManager
{
    private const int MANIFEST_FORMAT = 2;

    public function __construct(private Application $application) {}

    public function activate(): bool
    {
        if ($this->activationMode() !== 'always') {
            return false;
        }

        $container = $this->manifestContainer($this->application->runtimeMode());
        if ($container === null || $container['path'] !== $this->configuredPath($this->application->runtimeMode())) {
            return false;
        }

        try {
            $this->application->container()->usePrevalidated(
                $this->artifactPath($this->application->runtimeMode()),
                $container['fingerprint'],
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function clear(?RuntimeMode $runtime = null): bool
    {
        $removed = false;
        $runtimes = $runtime === null ? RuntimeMode::cases() : [$runtime];
        foreach ($runtimes as $mode) {
            $path = $this->artifactPath($mode);
            if (is_file($path)) {
                if (!unlink($path)) {
                    throw new \RuntimeException(sprintf('Unable to remove optimized artifact "%s".', $path));
                }
                $removed = true;
            }
        }
        if ($runtime === null && is_file($this->manifestPath())) {
            if (!unlink($this->manifestPath())) {
                throw new \RuntimeException(sprintf('Unable to remove optimize manifest "%s".', $this->manifestPath()));
            }
            $removed = true;
        }

        return $removed;
    }

    /**
     * @return array{runtime:string,path:string,fingerprint:string,compiled:list<string>,skipped:array<string,string>}
     */
    public function compile(RuntimeMode $runtime): array
    {
        $config = $this->application->config()->all();
        $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
        $appConfig['container'] = is_array($appConfig['container'] ?? null) ? $appConfig['container'] : [];
        $appConfig['container']['compiled_activation'] = 'off';
        $config['app'] = $appConfig;
        $config['_config_cache'] = false;

        $directory = dirname($this->artifactPath($runtime));
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create container cache directory "%s".', $directory));
        }

        $target = Application::create($config, $runtime);

        try {
            $target->container()->compileTo($this->artifactPath($runtime));
            $report = $target->container()->compilationReport();
        } finally {
            $target->container()->unset();
        }

        if ($report === null) {
            throw new \RuntimeException(sprintf('InterMix did not publish the %s compilation report.', $runtime->value));
        }

        return [
            'runtime' => $runtime->value,
            'path' => $report['path'],
            'fingerprint' => $report['fingerprint'],
            'compiled' => array_values($report['compiled']),
            'skipped' => $report['skipped'],
        ];
    }

    /** @return array<string, array{runtime:string,path:string,fingerprint:string,compiled:list<string>,skipped:array<string,string>}> */
    public function compileAll(): array
    {
        $reports = [];
        foreach (RuntimeMode::cases() as $runtime) {
            $reports[$runtime->value] = $this->compile($runtime);
        }

        return $reports;
    }

    /**
     * @param array<string, scalar|null> $artifacts
     * @param array<string, array{runtime:string,path:string,fingerprint:string,compiled:list<string>,skipped:array<string,string>}> $containers
     */
    public function publishManifest(array $artifacts, array $containers): string
    {
        $compiled = [];
        foreach ($containers as $runtime => $container) {
            $mode = RuntimeMode::tryFrom($runtime);
            if ($mode === null) {
                continue;
            }
            $compiled[$runtime] = [
                'path' => $this->configuredPath($mode),
                'fingerprint' => $container['fingerprint'],
                'compiled' => count($container['compiled']),
            ];
        }

        $manifest = [
            'format' => self::MANIFEST_FORMAT,
            'artifacts' => $artifacts,
            'containers' => $compiled,
        ];
        $this->writeAtomic(
            $this->manifestPath(),
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($manifest, true) . ";\n",
        );

        return $this->manifestPath();
    }

    /** @return array{ready:bool,activation:string,compiled:int,path:string,runtime:string} */
    public function status(?RuntimeMode $runtime = null): array
    {
        $runtime ??= $this->application->runtimeMode();
        $container = $this->manifestContainer($runtime);
        $ready = $container !== null
            && $container['path'] === $this->configuredPath($runtime)
            && is_file($this->artifactPath($runtime));

        return [
            'ready' => $ready,
            'activation' => $this->activationMode(),
            'compiled' => $ready ? $container['compiled'] : 0,
            'path' => $this->artifactPath($runtime),
            'runtime' => $runtime->value,
        ];
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function activationMode(): string
    {
        return strtolower(ValueNormalizer::string(
            $this->application->config()->get('app.container.compiled_activation'),
            'off',
        ));
    }

    private function artifactPath(RuntimeMode $runtime): string
    {
        $configured = $this->configuredPath($runtime);

        return $this->absolute($configured)
            ? $configured
            : $this->application->basePath($configured);
    }

    private function configuredPath(RuntimeMode $runtime): string
    {
        $configured = ValueNormalizer::string(
            $this->application->config()->get('app.container.compiled'),
            'bootstrap/cache/container/{runtime}.php',
        );
        if ($configured === 'bootstrap/cache/container.php') {
            $configured = 'bootstrap/cache/container/{runtime}.php';
        }

        return str_replace('{runtime}', $runtime->value, $configured);
    }

    /** @return array{path:string,fingerprint:string,compiled:int}|null */
    private function manifestContainer(RuntimeMode $runtime): ?array
    {
        if (!is_file($this->manifestPath())) {
            return null;
        }

        try {
            $manifest = require $this->manifestPath();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::MANIFEST_FORMAT) {
            return null;
        }

        $container = is_array($manifest['containers'] ?? null)
            ? ($manifest['containers'][$runtime->value] ?? null)
            : null;
        if (!is_array($container)) {
            return null;
        }

        $path = $container['path'] ?? null;
        $fingerprint = $container['fingerprint'] ?? null;
        $compiled = $container['compiled'] ?? null;
        if (!is_string($path)
            || !is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !is_int($compiled)
            || $compiled < 1
        ) {
            return null;
        }

        return ['path' => $path, 'fingerprint' => $fingerprint, 'compiled' => $compiled];
    }

    private function manifestPath(): string
    {
        return $this->application->basePath('bootstrap/cache/optimize.php');
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create optimize cache directory "%s".', $directory));
        }

        $temporary = tempnam($directory, '.optimize-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Unable to create a temporary manifest in "%s".', $directory));
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to publish optimize manifest "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}

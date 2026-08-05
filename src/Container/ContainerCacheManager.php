<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Support\ValueNormalizer;

/**
 * Builds and activates the application-owned InterMix resolver artifact.
 */
final readonly class ContainerCacheManager
{
    private const int MANIFEST_FORMAT = 1;

    public function __construct(private Application $application) {}

    /**
     * Activate only an explicitly selected, deployment-prevalidated artifact.
     */
    public function activate(): bool
    {
        if (!$this->application->runningInWeb() || $this->activationMode() !== 'always') {
            return false;
        }

        $container = $this->manifestContainer();
        if ($container === null || $container['path'] !== $this->configuredPath()) {
            return false;
        }

        try {
            $this->application->container()->usePrevalidated(
                $this->artifactPath(),
                $container['fingerprint'],
            );
        } catch (\Throwable) {
            // A request must remain available through InterMix's dynamic resolver.
            return false;
        }

        return true;
    }

    public function clear(): bool
    {
        $removed = false;
        foreach ([$this->artifactPath(), $this->manifestPath()] as $path) {
            if (!is_file($path)) {
                continue;
            }
            if (!unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to remove optimized artifact "%s".', $path));
            }
            $removed = true;
        }

        return $removed;
    }

    /**
     * Compile the fully registered web profile without activating optional modules.
     *
     * @return array{
     *   path:string,
     *   fingerprint:string,
     *   compiled:list<string>,
     *   skipped:array<string,string>
     * }
     */
    public function compileWeb(): array
    {
        $config = $this->application->config()->all();
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $app['container'] = is_array($app['container'] ?? null) ? $app['container'] : [];
        $app['container']['compiled_activation'] = 'off';
        $config['app'] = $app;
        $config['_config_cache'] = false;

        $web = Application::create($config, RuntimeMode::Web);

        try {
            $web->container()->compileTo($this->artifactPath());
            $report = $web->container()->compilationReport();
        } finally {
            $web->container()->unset();
        }

        if ($report === null) {
            throw new \RuntimeException('InterMix did not publish a container compilation report.');
        }

        return [
            'path' => $report['path'],
            'fingerprint' => $report['fingerprint'],
            'compiled' => array_values($report['compiled']),
            'skipped' => $report['skipped'],
        ];
    }

    /**
     * @param array<string, scalar|null> $artifacts
     * @param array{fingerprint:string,compiled:list<string>} $container
     */
    public function publishManifest(array $artifacts, array $container): string
    {
        $manifest = [
            'format' => self::MANIFEST_FORMAT,
            'artifacts' => $artifacts,
            'container' => [
                'path' => $this->configuredPath(),
                'fingerprint' => $container['fingerprint'],
                'compiled' => count($container['compiled']),
            ],
        ];
        $this->writeAtomic($this->manifestPath(), "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($manifest, true)
            . ";\n");

        return $this->manifestPath();
    }

    /**
     * @return array{ready:bool,activation:string,compiled:int,path:string}
     */
    public function status(): array
    {
        $container = $this->manifestContainer();
        $ready = $container !== null
            && $container['path'] === $this->configuredPath()
            && is_file($this->artifactPath());

        return [
            'ready' => $ready,
            'activation' => $this->activationMode(),
            'compiled' => $ready ? $container['compiled'] : 0,
            'path' => $this->artifactPath(),
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

    private function artifactPath(): string
    {
        $configured = $this->configuredPath();

        return $this->absolute($configured)
            ? $configured
            : $this->application->basePath($configured);
    }

    private function configuredPath(): string
    {
        return ValueNormalizer::string(
            $this->application->config()->get('app.container.compiled'),
            'bootstrap/cache/container.php',
        );
    }

    /**
     * @return array{path:string,fingerprint:string,compiled:int}|null
     */
    private function manifestContainer(): ?array
    {
        $path = $this->manifestPath();
        if (!is_file($path)) {
            return null;
        }

        try {
            $manifest = require $path;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($manifest)) {
            return null;
        }

        $container = $manifest['container'] ?? null;
        $path = is_array($container) ? ($container['path'] ?? null) : null;
        $fingerprint = is_array($container) ? ($container['fingerprint'] ?? null) : null;
        $compiled = is_array($container) ? ($container['compiled'] ?? null) : null;
        if (($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || !is_array($container)
            || !is_string($path)
            || !is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !is_int($compiled)
            || $compiled < 1
        ) {
            return null;
        }

        return [
            'path' => $path,
            'fingerprint' => $fingerprint,
            'compiled' => $compiled,
        ];
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

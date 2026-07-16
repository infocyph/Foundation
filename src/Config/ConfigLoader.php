<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Support\ValueNormalizer;

final class ConfigLoader
{
    /**
     * @param array<string, mixed> $inline
     */
    public function load(array $inline = []): ConfigRepository
    {
        $normalized = $this->normalizeInput($inline);
        $preset = ValueNormalizer::associativeArray($normalized['_preset'] ?? null);
        $cacheControl = $normalized['_config_cache'] ?? null;

        unset($normalized['_config_cache'], $normalized['_preset']);

        $basePath = $this->basePath($normalized);
        require_once __DIR__ . '/config_helpers.php';
        ConfigRuntime::activate($basePath);
        new EnvironmentLoader()->load($basePath, $normalized);
        $configDirectory = $this->configPath($basePath, $normalized);

        return ConfigRepository::fromLazyFiles(
            directory: $configDirectory,
            cacheDirectory: $this->configCacheEnabled($cacheControl)
                ? $this->configuredCachePath($cacheControl, $basePath)
                : null,
            fallback: $this->defaults(),
            overrides: ConfigMerger::mergeMany([$preset, $normalized]),
            namespaces: $this->configNamespaces($configDirectory),
        );
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @param array<string, mixed> $inline
     */
    private function basePath(array $inline): string
    {
        $app = ValueNormalizer::associativeArray($inline['app'] ?? null);
        $basePath = $app['base_path'] ?? null;

        return is_string($basePath) && $basePath !== ''
            ? rtrim($basePath, DIRECTORY_SEPARATOR)
            : (getcwd() ?: dirname(__DIR__, 2));
    }

    private function cachePath(string $basePath, string $path): string
    {
        return $this->absolute($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : $basePath . DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR);
    }

    private function configCacheEnabled(mixed $control): bool
    {
        return $control !== false
            && $control !== '0'
            && $control !== 'false';
    }

    /**
     * @return list<string>
     */
    private function configNamespaces(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $namespaces = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if ($namespace !== '') {
                $namespaces[] = $namespace;
            }
        }

        sort($namespaces);

        return $namespaces;
    }

    /**
     * @param array<string, mixed> $inline
     */
    private function configPath(string $basePath, array $inline): string
    {
        $paths = ValueNormalizer::associativeArray($inline['paths'] ?? null);
        $configured = $paths['config'] ?? null;

        if (!is_string($configured) || $configured === '') {
            return $basePath . DIRECTORY_SEPARATOR . 'config';
        }

        return $this->absolute($configured)
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : $basePath . DIRECTORY_SEPARATOR . trim($configured, DIRECTORY_SEPARATOR);
    }

    private function configuredCachePath(mixed $control, string $basePath): string
    {
        if (is_string($control) && $this->configCacheEnabled($control) && $control !== '') {
            return $this->cachePath($basePath, $control);
        }

        $configured = $_ENV['APP_CONFIG_CACHE'] ?? $_SERVER['APP_CONFIG_CACHE'] ?? getenv('APP_CONFIG_CACHE');

        return is_string($configured) && $this->configCacheEnabled($configured) && $configured !== ''
            ? $this->cachePath($basePath, $configured)
            : $basePath . DIRECTORY_SEPARATOR . 'bootstrap/cache/config';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return ConfigMerger::mergeMany([
            FoundationDefaults::all(),
            AuthDefaults::all(),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        $app = ValueNormalizer::associativeArray($input['app'] ?? null);

        if (isset($input['base_path']) && !isset($app['base_path'])) {
            $app['base_path'] = $input['base_path'];
        }

        if (isset($input['env']) && !isset($app['env'])) {
            $app['env'] = $input['env'];
        }

        if (isset($input['debug']) && !isset($app['debug'])) {
            $app['debug'] = $input['debug'];
        }

        if ($app !== []) {
            $input['app'] = $app;
        }

        return $input;
    }
}

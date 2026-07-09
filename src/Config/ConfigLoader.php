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

        unset($normalized['_preset']);

        $basePath = $this->basePath($normalized);
        new EnvironmentLoader()->load($basePath, $normalized);
        $fileConfig = $this->loadProjectConfig($basePath, $normalized);

        return new ConfigRepository(ConfigMerger::mergeMany([
            $this->defaults(),
            $preset,
            $fileConfig,
            $normalized,
        ]));
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
     * @param array<string, mixed> $inline
     * @return array<string, mixed>
     */
    private function loadProjectConfig(string $basePath, array $inline): array
    {
        $configDir = $this->configPath($basePath, $inline);
        if (!is_dir($configDir)) {
            return [];
        }

        $loaded = [];
        $files = glob($configDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $values = ValueNormalizer::associativeArray(require $file);

            if ($name === '' || $values === []) {
                continue;
            }

            $loaded[$name] = $values;
        }

        return $loaded;
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

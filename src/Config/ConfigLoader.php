<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\LazyFileConfig;

final class ConfigLoader
{
    public const string MANIFEST_FILE = '__manifest.php';

    public const string TYPE_SHARDED = 'sharded';

    public const string TYPE_SINGLE = 'single';

    private const int CACHE_FORMAT = 3;

    /** @param array<string, mixed> $inline */
    public function load(array $inline = []): ConfigRepository
    {
        $normalized = $this->normalizeInput($inline);
        $preset = $this->map($normalized['_preset'] ?? null);
        $cacheControl = $normalized['_config_cache'] ?? null;
        unset($normalized['_config_cache'], $normalized['_preset']);

        $basePath = $this->basePath($normalized);
        $cacheDirectory = $this->configCacheEnabled($cacheControl)
            ? $this->configuredCachePath($cacheControl, $basePath)
            : null;

        $cached = $cacheDirectory === null ? null : $this->loadCacheManifest($cacheDirectory);
        if (($cached['type'] ?? null) === self::TYPE_SINGLE) {
            return new ConfigRepository(
                ConfigMerger::mergeMany([$cached['data'], $preset, $normalized]),
                compiled: true,
            );
        }

        if ($cacheDirectory !== null && ($cached['type'] ?? null) === self::TYPE_SHARDED) {
            return ConfigRepository::fromLazyFiles(
                directory: $cacheDirectory,
                cacheDirectory: $cacheDirectory,
                fallback: $cached['complete'] ? [] : $this->defaults(),
                overrides: ConfigMerger::mergeMany([$preset, $normalized]),
                namespaces: $cached['namespaces'],
                compiled: true,
            );
        }

        new EnvironmentLoader()->load($basePath, $normalized);
        $configDirectory = $this->configPath($basePath, $normalized);

        return ConfigRepository::fromLazyFiles(
            directory: $configDirectory,
            cacheDirectory: $cacheDirectory,
            fallback: $this->defaults(),
            overrides: ConfigMerger::mergeMany([$preset, $normalized]),
            namespaces: $this->configNamespaces($configDirectory),
        );
    }

    public function writeCache(ConfigRepository $config, string $cacheDirectory, ?string $type = null): string
    {
        $this->ensureCacheDirectory($cacheDirectory);
        $cacheType = $this->cacheType($config, $type);
        $payload = $cacheType === self::TYPE_SINGLE
            ? $this->singleCachePayload($config, $cacheDirectory)
            : $this->shardedCachePayload($config, $cacheDirectory);

        $this->writeManifest($cacheDirectory, $payload);

        return $cacheType;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /** @param array<string, mixed> $input */
    private function basePath(array $input): string
    {
        $app = $this->map($input['app'] ?? null);
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

    private function cacheType(ConfigRepository $config, ?string $type): string
    {
        $configured = $type ?? $config->getString('app.config_cache.type', self::TYPE_SHARDED);
        $normalized = strtolower(trim($configured ?? self::TYPE_SHARDED));
        if (!in_array($normalized, [self::TYPE_SHARDED, self::TYPE_SINGLE], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported config cache type "%s".', $normalized));
        }

        return $normalized;
    }

    private function configCacheEnabled(mixed $control): bool
    {
        return $control !== false && $control !== '0' && $control !== 'false';
    }

    /** @return list<string> */
    private function configNamespaces(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $namespaces = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if ($namespace !== '' && $namespace[0] !== '_') {
                $namespaces[] = $namespace;
            }
        }
        sort($namespaces);

        return $namespaces;
    }

    /** @param array<string, mixed> $inline */
    private function configPath(string $basePath, array $inline): string
    {
        $paths = $this->map($inline['paths'] ?? null);
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

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ConfigMerger::mergeMany([FoundationDefaults::all(), AuthDefaults::all()]);
    }

    private function ensureCacheDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config cache directory "%s".', $directory));
        }
    }

    /**
     * @return array{type:'single',data:array<string,mixed>}|array{type:'sharded',namespaces:list<string>,complete:bool}|null
     */
    private function loadCacheManifest(string $directory): ?array
    {
        $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        try {
            $payload = require $file;
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($payload) || ($payload['_format'] ?? null) !== self::CACHE_FORMAT) {
            return null;
        }

        if (($payload['_type'] ?? null) === self::TYPE_SINGLE && is_array($payload['_data'] ?? null)) {
            return ['type' => self::TYPE_SINGLE, 'data' => $this->map($payload['_data'])];
        }
        if (($payload['_type'] ?? null) !== self::TYPE_SHARDED || !is_array($payload['_namespaces'] ?? null)) {
            return null;
        }

        $namespaces = [];
        foreach ($payload['_namespaces'] as $namespace) {
            if (!is_string($namespace) || preg_match('/^[A-Za-z0-9_-]+$/', $namespace) !== 1) {
                return null;
            }
            $namespaces[$namespace] = true;
        }

        return [
            'type' => self::TYPE_SHARDED,
            'namespaces' => array_keys($namespaces),
            'complete' => ($payload['_complete'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        $app = $this->map($input['app'] ?? null);
        foreach (['base_path', 'env', 'debug'] as $key) {
            if (array_key_exists($key, $input) && !array_key_exists($key, $app)) {
                $app[$key] = $input[$key];
            }
        }
        if ($app !== []) {
            $input['app'] = $app;
        }

        return $input;
    }

    /** @param list<string> $namespaces */
    private function removeStaleShards(string $directory, array $namespaces): void
    {
        $keep = array_fill_keys([...$namespaces, '__flat', '__manifest'], true);
        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (!isset($keep[pathinfo($file, PATHINFO_FILENAME)]) && !unlink($file)) {
                throw new \RuntimeException(sprintf('Unable to remove stale config cache shard "%s".', $file));
            }
        }
    }

    /** @return array{_format:int,_type:string,_namespaces:list<string>,_complete:bool} */
    private function shardedCachePayload(ConfigRepository $config, string $directory): array
    {
        $compiled = $config->all();
        $namespaces = [];
        foreach ($compiled as $namespace => $value) {
            if (is_array($value) && preg_match('/^[A-Za-z0-9_-]+$/', $namespace) === 1) {
                $namespaces[] = $namespace;
            }
        }
        sort($namespaces);
        $this->removeStaleShards($directory, $namespaces);

        new LazyFileConfig(
            directory: $directory,
            items: array_intersect_key($compiled, array_fill_keys($namespaces, true)),
            namespaceCacheDirectory: $directory,
        )->warmNamespaceCache($namespaces);

        // ArrayKit intentionally generates __flat.php here. Keep it: scalar/null leaf
        // lookups can use the flat index without loading an entire namespace shard.
        foreach ($namespaces as $namespace) {
            $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $namespace . '.php';
            if (!is_file($file) || !chmod($file, 0664)) {
                throw new \RuntimeException(sprintf('Unable to finalize lazy config cache "%s".', $file));
            }
        }

        return [
            '_format' => self::CACHE_FORMAT,
            '_type' => self::TYPE_SHARDED,
            '_namespaces' => $namespaces,
            '_complete' => true,
        ];
    }

    /** @return array{_format:int,_type:string,_data:array<string,mixed>} */
    private function singleCachePayload(ConfigRepository $config, string $directory): array
    {
        $this->removeStaleShards($directory, []);

        return ['_format' => self::CACHE_FORMAT, '_type' => self::TYPE_SINGLE, '_data' => $config->all()];
    }

    /** @param array<string, mixed> $payload */
    private function writeManifest(string $directory, array $payload): void
    {
        $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $temporary = tempnam($directory, '.manifest-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create temporary config manifest.');
        }

        try {
            $content = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $target)) {
                throw new \RuntimeException(sprintf('Unable to publish config cache manifest "%s".', $target));
            }
            chmod($target, 0664);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\Foundation\Support\ValueNormalizer;

final class ConfigLoader
{
    public const string MANIFEST_FILE = '__manifest.php';

    public const string TYPE_SHARDED = 'sharded';

    public const string TYPE_SINGLE = 'single';

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
        $cacheDirectory = $this->configCacheEnabled($cacheControl)
            ? $this->configuredCachePath($cacheControl, $basePath)
            : null;
        require_once __DIR__ . '/config_helpers.php';
        ConfigRuntime::activate($basePath);

        $cached = $cacheDirectory === null ? null : $this->loadCacheManifest($cacheDirectory);
        if (($cached['type'] ?? null) === self::TYPE_SINGLE) {
            return new ConfigRepository(ConfigMerger::mergeMany([$cached['data'], $preset, $normalized]));
        }
        if ($cacheDirectory !== null && ($cached['type'] ?? null) === self::TYPE_SHARDED) {
            return ConfigRepository::fromLazyFiles(
                directory: $cacheDirectory,
                cacheDirectory: $cacheDirectory,
                fallback: $cached['complete'] ? [] : $this->defaults(),
                overrides: ConfigMerger::mergeMany([$preset, $normalized]),
                namespaces: $cached['namespaces'],
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

    private function cacheType(ConfigRepository $config, ?string $type): string
    {
        $configured = $type ?? $config->getString('app.config_cache.type', self::TYPE_SHARDED);
        $normalized = strtolower(trim($configured ?? self::TYPE_SHARDED));

        if (!in_array($normalized, [self::TYPE_SHARDED, self::TYPE_SINGLE], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported config cache type "%s"; expected "%s" or "%s".',
                $normalized,
                self::TYPE_SHARDED,
                self::TYPE_SINGLE,
            ));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $compiled
     * @return list<string>
     */
    private function compiledNamespaces(array $compiled): array
    {
        $namespaces = [];

        foreach ($compiled as $namespace => $value) {
            if (is_array($value) && preg_match('/^[A-Za-z0-9_-]+$/', $namespace) === 1) {
                $namespaces[] = $namespace;
            }
        }

        sort($namespaces);

        return $namespaces;
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

    private function ensureCacheDirectory(string $cacheDirectory): void
    {
        if (!is_dir($cacheDirectory)
            && !mkdir($cacheDirectory, 0775, true)
            && !is_dir($cacheDirectory)
        ) {
            throw new \RuntimeException(sprintf('Unable to create config cache directory "%s".', $cacheDirectory));
        }
    }

    /**
     * @return array{type:'single',data:array<string,mixed>}|array{type:'sharded',namespaces:list<string>,complete:bool}|null
     */
    private function loadCacheManifest(string $cacheDirectory): ?array
    {
        $file = rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $payload = $this->requireManifest($file);
        if ($payload === null || ($payload['_format'] ?? null) !== 1) {
            return null;
        }

        return match ($payload['_type'] ?? null) {
            self::TYPE_SINGLE => $this->singleCacheManifest($payload),
            self::TYPE_SHARDED => $this->shardedCacheManifest($payload),
            default => null,
        };
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

    /** @param list<string> $namespaces */
    private function removeStaleShards(string $cacheDirectory, array $namespaces): void
    {
        $keep = array_fill_keys($namespaces, true);

        foreach (glob(rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if ($namespace === pathinfo(self::MANIFEST_FILE, PATHINFO_FILENAME) || isset($keep[$namespace])) {
                continue;
            }

            if (!unlink($file)) {
                throw new \RuntimeException(sprintf('Unable to remove stale config cache shard "%s".', $file));
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireManifest(string $file): ?array
    {
        try {
            $payload = require $file;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $manifest = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                return null;
            }

            $manifest[$key] = $value;
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type:'sharded',namespaces:list<string>,complete:bool}|null
     */
    private function shardedCacheManifest(array $payload): ?array
    {
        if (!is_array($payload['_namespaces'] ?? null)) {
            return null;
        }

        $namespaces = [];
        foreach ($payload['_namespaces'] as $namespace) {
            if (!is_string($namespace)
                || $namespace === ''
                || preg_match('/^[A-Za-z0-9_-]+$/', $namespace) !== 1
            ) {
                return null;
            }

            $namespaces[] = $namespace;
        }

        return [
            'type' => self::TYPE_SHARDED,
            'namespaces' => array_values(array_unique($namespaces)),
            'complete' => ($payload['_complete'] ?? false) === true,
        ];
    }

    /** @return array{_format:int,_type:string,_namespaces:list<string>,_complete:bool} */
    private function shardedCachePayload(ConfigRepository $config, string $cacheDirectory): array
    {
        $compiled = $config->all();
        $namespaces = $this->compiledNamespaces($compiled);
        $this->removeStaleShards($cacheDirectory, $namespaces);

        new LazyFileConfig(
            directory: $cacheDirectory,
            items: array_intersect_key($compiled, array_fill_keys($namespaces, true)),
            namespaceCacheDirectory: $cacheDirectory,
        )->warmNamespaceCache($namespaces);

        $flatIndex = rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '__flat.php';
        if (is_file($flatIndex) && !unlink($flatIndex)) {
            throw new \RuntimeException(sprintf('Unable to remove eager config index "%s".', $flatIndex));
        }

        foreach ($namespaces as $namespace) {
            $file = rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $namespace . '.php';
            if (!is_file($file) || !chmod($file, 0664)) {
                throw new \RuntimeException(sprintf('Unable to finalize lazy config cache "%s".', $file));
            }
        }

        return [
            '_format' => 1,
            '_type' => self::TYPE_SHARDED,
            '_namespaces' => $namespaces,
            '_complete' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type:'single',data:array<string,mixed>}|null
     */
    private function singleCacheManifest(array $payload): ?array
    {
        if (!is_array($payload['_data'] ?? null)) {
            return null;
        }

        $data = [];
        foreach ($payload['_data'] as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        return ['type' => self::TYPE_SINGLE, 'data' => $data];
    }

    /**
     * @return array{_format:int,_type:string,_data:array<string,mixed>}
     */
    private function singleCachePayload(ConfigRepository $config, string $cacheDirectory): array
    {
        $this->removeStaleShards($cacheDirectory, []);

        return [
            '_format' => 1,
            '_type' => self::TYPE_SINGLE,
            '_data' => $config->all(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeManifest(string $cacheDirectory, array $payload): void
    {
        $target = rtrim($cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $temporary = tempnam($cacheDirectory, '.manifest-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Unable to create a temporary config cache in "%s".', $cacheDirectory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false
                || !chmod($temporary, 0664)
                || !rename($temporary, $target)
            ) {
                throw new \RuntimeException(sprintf('Unable to write config cache manifest "%s".', $target));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}

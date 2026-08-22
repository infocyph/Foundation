<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

final class RouteCachePath
{
    private const int MANIFEST_VERSION = 1;

    /** @var \WeakMap<ConfigRepository, bool>|null */
    private static ?\WeakMap $warm = null;

    public static function enabled(ConfigRepository $config): bool
    {
        return $config->get('router.cache', true) !== false;
    }

    public static function for(ConfigRepository $config): string
    {
        $basePath = self::basePath($config);
        $directory = $basePath . DIRECTORY_SEPARATOR . 'bootstrap/cache/routes';

        return match ($config->getString('router.matcher', 'fused')) {
            'generated' => $directory . DIRECTORY_SEPARATOR . 'generated.php',
            'sharded' => $directory,
            default => $directory . DIRECTORY_SEPARATOR . 'fused.php',
        };
    }

    public static function isSourceFresh(ConfigRepository $config): bool
    {
        if (!self::enabled($config)) {
            return false;
        }

        $manifest = self::readManifest($config);
        if ($manifest === null || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
            return false;
        }

        $fingerprint = $manifest['fingerprint'] ?? null;

        return is_string($fingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && hash_equals($fingerprint, self::sourceFingerprint($config));
    }

    public static function isWarm(ConfigRepository $config): bool
    {
        if (!self::enabled($config)) {
            return false;
        }

        self::$warm ??= new \WeakMap();
        if ((self::$warm[$config] ?? false) === true) {
            return true;
        }
        if (!self::isSourceFresh($config)) {
            return false;
        }

        $matcher = match ($config->getString('router.matcher', 'fused')) {
            'generated' => GeneratedMatcher::make(),
            'sharded' => ShardedMatcher::make(),
            default => FusedMatcher::make(),
        };
        $matcher->enableCache(self::for($config));

        $warm = $matcher->canBootFromCache();
        if ($warm) {
            self::$warm[$config] = true;
        }

        return $warm;
    }

    public static function markFresh(ConfigRepository $config): void
    {
        if (!self::enabled($config)) {
            return;
        }

        $file = self::manifestPath($config);
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create route cache metadata directory "%s".', $directory));
        }

        $payload = [
            'version' => self::MANIFEST_VERSION,
            'fingerprint' => self::sourceFingerprint($config),
        ];
        $temporary = tempnam($directory, '.routes-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create temporary route cache metadata file.');
        }

        try {
            $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $file)) {
                throw new \RuntimeException(sprintf('Unable to publish route cache metadata "%s".', $file));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        self::$warm ??= new \WeakMap();
        self::$warm[$config] = true;
    }

    private static function basePath(ConfigRepository $config): string
    {
        return rtrim(
            $config->getString('app.base_path', getcwd() ?: '.') ?: (getcwd() ?: '.'),
            DIRECTORY_SEPARATOR,
        );
    }

    private static function manifestPath(ConfigRepository $config): string
    {
        $cache = self::for($config);

        return is_dir($cache) || $config->getString('router.matcher', 'fused') === 'sharded'
            ? rtrim($cache, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '__foundation.php'
            : $cache . '.foundation.php';
    }

    /** @return array<string, mixed>|null */
    private static function readManifest(ConfigRepository $config): ?array
    {
        $file = self::manifestPath($config);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        try {
            $payload = require $file;
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private static function sourceFingerprint(ConfigRepository $config): string
    {
        $basePath = self::basePath($config);
        $routing = [
            'matcher' => $config->get('router.matcher', 'fused'),
            'auto_slash_redirect' => $config->get('router.auto_slash_redirect', false),
            'expose_url_services' => $config->get('router.expose_url_services', false),
            'signed_urls' => $config->get('router.signed_urls', []),
            'url_base_uri' => $config->get('router.url_base_uri', ''),
            'middleware' => $config->get('router.middleware', []),
            'attributes' => $config->get('router.attributes', []),
            'files' => $config->get('router.files', ['web.php', 'api.php', 'auth.php']),
        ];

        $sources = [];
        foreach (self::routeFiles($config, $basePath) as $file) {
            $sources[$file] = self::fileFingerprint($file);
        }
        foreach (self::attributeSourceFiles($config, $basePath) as $file) {
            $sources[$file] = self::fileFingerprint($file);
        }
        ksort($sources, SORT_STRING);

        return hash('sha256', serialize([$routing, $sources]));
    }

    /** @return list<string> */
    private static function routeFiles(ConfigRepository $config, string $basePath): array
    {
        $routesPath = $config->getString('paths.routes', 'routes') ?? 'routes';
        $routesPath = self::absolute($routesPath)
            ? rtrim($routesPath, DIRECTORY_SEPARATOR)
            : $basePath . DIRECTORY_SEPARATOR . trim($routesPath, DIRECTORY_SEPARATOR);
        $configured = $config->get('router.files', ['web.php', 'api.php', 'auth.php']);
        if (!is_array($configured)) {
            $configured = ['web.php', 'api.php', 'auth.php'];
        }

        $files = [];
        foreach ($configured as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = self::absolute($file)
                    ? $file
                    : $routesPath . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
            }
        }

        return $files;
    }

    /** @return list<string> */
    private static function attributeSourceFiles(ConfigRepository $config, string $basePath): array
    {
        $attributes = $config->get('router.attributes', []);
        if (!is_array($attributes) || ($attributes['enabled'] ?? false) !== true) {
            return [];
        }

        $files = [];
        $directories = $attributes['directories'] ?? [];
        if (!is_array($directories) || $directories === []) {
            $appPath = $config->getString('paths.app', 'app') ?? 'app';
            $directories = ['App\\Http\\Controllers\\' => self::absolute($appPath)
                ? rtrim($appPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Http/Controllers'
                : $basePath . DIRECTORY_SEPARATOR . trim($appPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Http/Controllers'];
        }
        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory === '') {
                continue;
            }
            $resolved = self::absolute($directory)
                ? $directory
                : $basePath . DIRECTORY_SEPARATOR . trim($directory, DIRECTORY_SEPARATOR);
            array_push($files, ...self::phpFiles($resolved));
        }

        $classes = $attributes['classes'] ?? [];
        if (is_array($classes)) {
            foreach ($classes as $class) {
                if (!is_string($class) || $class === '') {
                    continue;
                }
                $file = self::autoloadFile($class);
                if ($file !== null) {
                    $files[] = $file;
                } else {
                    // Still invalidate when the explicit class list itself changes.
                    $files[] = 'class://' . $class;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    private static function autoloadFile(string $class): ?string
    {
        foreach (spl_autoload_functions() as $loader) {
            if (!is_array($loader) || !is_object($loader[0] ?? null) || !method_exists($loader[0], 'findFile')) {
                continue;
            }
            try {
                $file = $loader[0]->findFile($class);
            } catch (\Throwable) {
                continue;
            }
            if (is_string($file) && $file !== '') {
                return $file;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private static function fileFingerprint(string $file): string
    {
        if (str_starts_with($file, 'class://')) {
            return $file;
        }
        if (!is_file($file)) {
            return 'missing';
        }

        $hash = hash_file('sha256', $file);

        return is_string($hash) ? $hash : 'unreadable';
    }

    private static function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

final class RouteCachePath
{
    private const int MANIFEST_VERSION = 2;

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

    /**
     * Determine whether the deployment-built route artifact matches the active
     * routing configuration. Route/controller source freshness is owned by the
     * deployment route:cache/optimize step and is not rescanned at runtime.
     */
    public static function isSourceFresh(ConfigRepository $config): bool
    {
        if (!self::enabled($config)) {
            return false;
        }

        $manifest = self::readManifest($config);
        if ($manifest === null || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
            return false;
        }

        $fingerprint = $manifest['configuration'] ?? null;

        return is_string($fingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && hash_equals($fingerprint, self::configurationFingerprint($config));
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
            'configuration' => self::configurationFingerprint($config),
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

    private static function configurationFingerprint(ConfigRepository $config): string
    {
        return hash('sha256', serialize([
            'matcher' => $config->get('router.matcher', 'fused'),
            'auto_slash_redirect' => $config->get('router.auto_slash_redirect', false),
            'expose_url_services' => $config->get('router.expose_url_services', false),
            'signed_urls' => $config->get('router.signed_urls', []),
            'url_base_uri' => $config->get('router.url_base_uri', ''),
            'middleware' => $config->get('router.middleware', []),
            'attributes' => $config->get('router.attributes', []),
            'files' => $config->get('router.files', ['web.php', 'api.php', 'auth.php']),
        ]));
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
}

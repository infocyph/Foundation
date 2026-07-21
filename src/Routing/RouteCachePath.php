<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

final class RouteCachePath
{
    /** @var \WeakMap<ConfigRepository, bool>|null */
    private static ?\WeakMap $warm = null;

    public static function enabled(ConfigRepository $config): bool
    {
        return $config->get('router.cache', true) !== false;
    }

    public static function for(ConfigRepository $config): string
    {
        $basePath = $config->getString('app.base_path', getcwd() ?: '.') ?: (getcwd() ?: '.');
        $directory = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'bootstrap/cache/routes';

        return match ($config->getString('router.matcher', 'fused')) {
            'generated' => $directory . DIRECTORY_SEPARATOR . 'generated.php',
            'sharded' => $directory,
            default => $directory . DIRECTORY_SEPARATOR . 'fused.php',
        };
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
}

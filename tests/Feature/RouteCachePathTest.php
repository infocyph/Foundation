<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Support\RouteCache as WebrickRouteCache;

it('derives route cache locations from the selected matcher', function (): void {
    $basePath = '/tmp/foundation-route-cache';

    expect(RouteCachePath::for(new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'router' => ['matcher' => 'fused'],
    ])))->toBe($basePath . '/bootstrap/cache/routes/fused.php')
        ->and(RouteCachePath::for(new ConfigRepository([
            'app' => ['base_path' => $basePath],
            'router' => ['matcher' => 'generated'],
        ])))->toBe($basePath . '/bootstrap/cache/routes/generated.php')
        ->and(RouteCachePath::for(new ConfigRepository([
            'app' => ['base_path' => $basePath],
            'router' => ['matcher' => 'sharded'],
        ])))->toBe($basePath . '/bootstrap/cache/routes');
});

it('detects a warm single-file route cache', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-route-cache-' . bin2hex(random_bytes(4));
    $config = new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'router' => ['matcher' => 'fused'],
    ]);
    $cache = RouteCachePath::for($config);

    try {
        WebrickRouteCache::build([
            'cache' => $cache,
            'matcher' => 'fused',
            'register' => static fn(Registrar $router): mixed => $router->get('/fused', static fn(): string => 'ok'),
        ]);
        RouteCachePath::markFresh($config);

        expect(RouteCachePath::isWarm($config))->toBeTrue()
            ->and(RouteCachePath::isWarm(new ConfigRepository([
                'app' => ['base_path' => $basePath],
                'router' => ['matcher' => 'fused', 'cache' => false],
            ])))->toBeFalse();
    } finally {
        routeCacheRemoveDirectory($basePath);
    }
});

it('detects warm generated and sharded route caches', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-route-cache-' . bin2hex(random_bytes(4));
    $generated = new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'router' => ['matcher' => 'generated'],
    ]);
    $sharded = new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'router' => ['matcher' => 'sharded'],
    ]);
    $directory = $basePath . '/bootstrap/cache/routes';

    try {
        WebrickRouteCache::build([
            'cache' => RouteCachePath::for($generated),
            'matcher' => 'generated',
            'register' => static fn(Registrar $router): mixed => $router->get('/generated', static fn(): string => 'ok'),
        ]);
        RouteCachePath::markFresh($generated);
        expect(RouteCachePath::isWarm($generated))->toBeTrue();

        WebrickRouteCache::clear([
            'cache' => RouteCachePath::for($generated),
            'matcher' => 'generated',
        ]);
        WebrickRouteCache::build([
            'cache' => RouteCachePath::for($sharded),
            'matcher' => 'sharded',
            'register' => static fn(Registrar $router): mixed => $router->get('/sharded', static fn(): string => 'ok'),
        ]);
        RouteCachePath::markFresh($sharded);
        expect(RouteCachePath::isWarm($sharded))->toBeTrue();
    } finally {
        if (is_dir($directory)) {
            WebrickRouteCache::clear([
                'cache' => $directory,
                'matcher' => 'sharded',
                'aggressive' => true,
            ]);
        }
        routeCacheRemoveDirectory($basePath);
    }
});

function routeCacheRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            routeCacheRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

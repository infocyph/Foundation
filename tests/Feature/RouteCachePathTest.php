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
    $cache = $basePath . '/bootstrap/cache/routes/fused.php';
    mkdir(dirname($cache), 0777, true);
    file_put_contents($cache, "<?php\n\nreturn [];\n");

    try {
        expect(RouteCachePath::isWarm(new ConfigRepository([
            'app' => ['base_path' => $basePath],
            'router' => ['matcher' => 'fused'],
        ])))->toBeTrue()
            ->and(RouteCachePath::isWarm(new ConfigRepository([
                'app' => ['base_path' => $basePath],
                'router' => ['matcher' => 'fused', 'cache' => false],
            ])))->toBeFalse();
    } finally {
        unlink($cache);
        rmdir(dirname($cache));
        rmdir(dirname(dirname($cache)));
        rmdir(dirname(dirname(dirname($cache))));
        rmdir($basePath);
    }
});

it('detects warm generated and sharded route caches', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-route-cache-' . bin2hex(random_bytes(4));
    $directory = $basePath . '/bootstrap/cache/routes';
    mkdir($directory, 0777, true);

    try {
        WebrickRouteCache::build([
            'cache' => $directory . '/generated.php',
            'matcher' => 'generated',
            'register' => static fn(Registrar $router): mixed => $router->get('/generated', static fn(): string => 'ok'),
        ]);
        expect(RouteCachePath::isWarm(new ConfigRepository([
            'app' => ['base_path' => $basePath],
            'router' => ['matcher' => 'generated'],
        ])))->toBeTrue();

        WebrickRouteCache::clear([
            'cache' => $directory . '/generated.php',
            'matcher' => 'generated',
        ]);
        WebrickRouteCache::build([
            'cache' => $directory,
            'matcher' => 'sharded',
            'register' => static fn(Registrar $router): mixed => $router->get('/sharded', static fn(): string => 'ok'),
        ]);
        expect(RouteCachePath::isWarm(new ConfigRepository([
            'app' => ['base_path' => $basePath],
            'router' => ['matcher' => 'sharded'],
        ])))->toBeTrue();
    } finally {
        WebrickRouteCache::clear([
            'cache' => $directory,
            'matcher' => 'sharded',
            'aggressive' => true,
        ]);
        rmdir($directory);
        rmdir(dirname($directory));
        rmdir(dirname(dirname($directory)));
        rmdir($basePath);
    }
});

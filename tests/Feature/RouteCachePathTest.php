<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Routing\RouteCachePath;

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

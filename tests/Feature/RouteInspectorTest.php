<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\RouteInspector;

it('lists development routes without creating a standalone matcher cache', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-route-inspector-' . bin2hex(random_bytes(4));
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents(
        $basePath . '/routes/web.php',
        "<?php\n\n\$router->get('/health', static fn(): string => 'ok');\n",
    );

    try {
        $application = Foundation::cli([
            'base_path' => $basePath,
            'app' => [
                'base_path' => $basePath,
                'env' => 'testing',
            ],
            'router' => [
                'files' => ['web.php'],
            ],
        ]);
        $routes = new RouteInspector($application)->routes()->all();

        expect($routes)->toHaveCount(1)
            ->and($routes[0]->getMethod())->toBe('GET')
            ->and($routes[0]->getPath())->toBe('/health')
            ->and(is_dir($basePath . '/bootstrap/cache/routes'))->toBeFalse();
    } finally {
        routeInspectorRemoveDirectory($basePath);
    }
});

function routeInspectorRemoveDirectory(string $directory): void
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
            routeInspectorRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

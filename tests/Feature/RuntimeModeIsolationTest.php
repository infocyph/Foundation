<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Routing\RouteFileLoader;

it('keeps console and web boot graphs isolated', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-runtime-' . bin2hex(random_bytes(5));
    $routesPath = $basePath . '/routes';
    $sentinel = $basePath . '/web-route-loaded';
    mkdir($routesPath, 0775, true);
    file_put_contents(
        $routesPath . '/api.php',
        '<?php file_put_contents(' . var_export($sentinel, true) . ", 'loaded');\n",
    );

    $options = [
        'base_path' => $basePath,
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => ['api.php'],
        ],
    ];

    try {
        $console = Foundation::console($options);

        expect($console->runtimeMode())->toBe(RuntimeMode::Console)
            ->and($console->runningInConsole())->toBeTrue()
            ->and($console->booted())->toBeFalse()
            ->and($console->basePath())->toBe($basePath)
            ->and($console->booted())->toBeFalse()
            ->and($console->container()->has(PathManager::class))->toBeTrue()
            ->and($console->container()->has(RouteFileLoader::class))->toBeFalse()
            ->and($console->container()->has(HttpKernel::class))->toBeFalse()
            ->and($console->has(HttpKernel::class))->toBeFalse()
            ->and($console->make(RuntimeMode::class))->toBe(RuntimeMode::Console);

        $console->boot();

        expect(is_file($sentinel))->toBeFalse()
            ->and($console->container()->has(RouteFileLoader::class))->toBeFalse()
            ->and($console->container()->has(HttpKernel::class))->toBeFalse()
            ->and(fn() => $console->http())
            ->toThrow(LogicException::class, 'HTTP kernel is unavailable');

        $console->authManager();

        expect($console->container()->has(AuthMiddleware::class))->toBeFalse();

        $web = Foundation::web($options);

        expect($web->runtimeMode())->toBe(RuntimeMode::Web)
            ->and($web->runningInWeb())->toBeTrue()
            ->and($web->container()->has(RouteFileLoader::class))->toBeTrue()
            ->and($web->container()->has(HttpKernel::class))->toBeTrue()
            ->and($web->make(RuntimeMode::class))->toBe(RuntimeMode::Web);

        $web->boot();

        expect(file_get_contents($sentinel))->toBe('loaded');
    } finally {
        runtimeModeRemoveDirectory($basePath);
    }
});

it('selects only providers assigned to the active runtime', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-providers-' . bin2hex(random_bytes(5));
    $providerFile = $basePath . '/providers.php';
    mkdir($basePath, 0775, true);
    file_put_contents($providerFile, sprintf(
        "<?php\n\nreturn [\n"
        . "    'common' => [%s::class],\n"
        . "    'web' => [%s::class],\n"
        . "    'console' => [%s::class],\n"
        . "];\n",
        CacheServiceProvider::class,
        HttpServiceProvider::class,
        FilesystemServiceProvider::class,
    ));

    try {
        $loader = new ProviderFileLoader(new PathManager(
            basePath: $basePath,
            paths: ['providers' => $providerFile],
        ));

        expect($loader->providers(RuntimeMode::Web))->toBe([
            CacheServiceProvider::class,
            HttpServiceProvider::class,
        ])->and($loader->providers(RuntimeMode::Console))->toBe([
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
        ]);

        file_put_contents(
            $providerFile,
            sprintf("<?php\n\nreturn [%s::class];\n", HttpServiceProvider::class),
        );

        expect(fn() => $loader->providers(RuntimeMode::Web))
            ->toThrow(BootstrapException::class, 'must define common, web, and console');
    } finally {
        runtimeModeRemoveDirectory($basePath);
    }
});

function runtimeModeRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}

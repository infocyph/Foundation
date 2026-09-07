<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Routing\RouteFileLoader;

it('keeps CLI and web boot graphs isolated', function (): void {
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
        'router' => ['cache' => false, 'files' => ['api.php']],
    ];
    $explicitlyBound = static function (Application $app, string $id): bool {
        $repository = $app->container()->getRepository();
        return $repository->hasFunctionReference($id)
            || $repository->hasClosureResource($id)
            || $repository->hasResolved($id)
            || $repository->hasResolvedDefinition($id);
    };

    try {
        $cli = Foundation::cli($options);
        expect($cli->runtimeMode())->toBe(RuntimeMode::Cli)
            ->and($cli->runningInCli())->toBeTrue()
            ->and($cli->booted())->toBeFalse()
            ->and($cli->basePath())->toBe($basePath)
            ->and($cli->container()->has(PathManager::class))->toBeTrue()
            ->and($explicitlyBound($cli, RouteFileLoader::class))->toBeFalse()
            ->and($explicitlyBound($cli, HttpKernel::class))->toBeFalse()
            ->and($cli->make(RuntimeMode::class))->toBe(RuntimeMode::Cli);

        $cli->boot();
        expect(is_file($sentinel))->toBeFalse()
            ->and($explicitlyBound($cli, RouteFileLoader::class))->toBeFalse()
            ->and($explicitlyBound($cli, HttpKernel::class))->toBeFalse()
            ->and(fn() => $cli->http())->toThrow(LogicException::class, 'HTTP kernel is unavailable');

        $cli->make(AuthManager::class);
        expect($explicitlyBound($cli, AuthMiddleware::class))->toBeFalse();

        $web = Foundation::web($options);
        expect($web->runtimeMode())->toBe(RuntimeMode::Web)
            ->and($web->runningInWeb())->toBeTrue()
            ->and($explicitlyBound($web, RouteFileLoader::class))->toBeTrue()
            ->and($explicitlyBound($web, HttpKernel::class))->toBeTrue()
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
        . "    'cli' => [%s::class],\n"
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
        ])->and($loader->providers(RuntimeMode::Cli))->toBe([
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
        ])->and($loader->groups())->toBe([
            'common' => [CacheServiceProvider::class],
            'web' => [HttpServiceProvider::class],
            'cli' => [FilesystemServiceProvider::class],
            'worker' => [],
            'scheduler' => [],
        ]);

        file_put_contents($providerFile, sprintf("<?php\n\nreturn [%s::class];\n", HttpServiceProvider::class));
        expect(fn() => $loader->providers(RuntimeMode::Web))
            ->toThrow(BootstrapException::class, 'must define common, web, cli, worker, and scheduler');
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

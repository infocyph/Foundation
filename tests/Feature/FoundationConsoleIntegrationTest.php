<?php

declare(strict_types=1);

use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\IO\BufferedIO;
use Infocyph\Foundation\Console\Command\AppReadyCommand;
use Infocyph\Foundation\Console\FoundationConsole;
use Infocyph\Foundation\Foundation;

it('keeps console preflight paths independent of Foundation boot', function (array $arguments): void {
    $created = false;
    $io = new BufferedIO();
    $console = FoundationConsole::create(
        static function (?string $_profile) use (&$created) {
            $created = true;

            return Foundation::console([
                'base_path' => sys_get_temp_dir(),
                'env' => $_profile ?? 'testing',
                '_config_cache' => false,
            ]);
        },
        name: 'foundation-test',
        version: '1.0.0',
    )->withIO($io);

    expect($console->run($arguments))->toBe(ExitCode::SUCCESS)
        ->and($created)->toBeFalse();
})->with([
    'help' => [['foundation-test', '--help']],
    'list' => [['foundation-test', 'list']],
    'version' => [['foundation-test', '--version']],
]);

it('reserves Foundation system command routes', function (): void {
    expect(fn() => FoundationConsole::create(
        static fn(?string $profile) => Foundation::console([
            'base_path' => sys_get_temp_dir(),
            'env' => $profile ?? 'testing',
            '_config_cache' => false,
        ]),
        commands: ['route:cache' => stdClass::class],
    ))->toThrow(InvalidArgumentException::class, 'conflicts with a Foundation system command');
});

it('requires application commands to use an explicit valid route map', function (): void {
    $factory = static fn(?string $profile) => Foundation::console([
        'base_path' => sys_get_temp_dir(),
        'env' => $profile ?? 'testing',
        '_config_cache' => false,
    ]);

    expect(fn() => FoundationConsole::create(
        $factory,
        commands: [AppReadyCommand::class],
    ))->toThrow(InvalidArgumentException::class, 'command-name-to-class map')
        ->and(fn() => FoundationConsole::create(
            $factory,
            commands: ['app:invalid' => stdClass::class],
        ))->toThrow(InvalidArgumentException::class, 'must implement');
});

it('reuses one lazily created Foundation application for real commands', function (): void {
    $created = 0;
    $basePath = sys_get_temp_dir() . '/foundation-console-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    try {
        $io = new BufferedIO();
        $console = FoundationConsole::create(
            static function (?string $_profile) use (&$created, $basePath) {
                $created++;

                return Foundation::console([
                    'base_path' => $basePath,
                    'env' => $_profile ?? 'testing',
                    '_config_cache' => false,
                    'router' => ['files' => []],
                ]);
            },
            name: 'foundation-test',
        )->withIO($io);

        $path = $basePath . '/missing-config-cache';
        expect($console->run(['foundation-test', 'config:clear', '--path=' . $path]))
            ->toBe(ExitCode::SUCCESS)
            ->and($console->run(['foundation-test', 'config:clear', '--path=' . $path]))
            ->toBe(ExitCode::SUCCESS)
            ->and($created)->toBe(1);
    } finally {
        rmdir($basePath);
    }
});

it('distinguishes cleared and missing configuration caches', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-console-config-' . bin2hex(random_bytes(5));
    $cachePath = $basePath . '/cache';
    mkdir($cachePath, 0775, true);
    file_put_contents($cachePath . '/__manifest.php', '<?php return [];');

    try {
        $io = new BufferedIO();
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
            ]),
        )->withIO($io);

        expect($console->run(['foundation', 'config:clear', '--path=' . $cachePath]))
            ->toBe(ExitCode::SUCCESS)
            ->and(is_file($cachePath . '/__manifest.php'))->toBeFalse()
            ->and($console->run(['foundation', 'config:clear', '--path=' . $cachePath]))
            ->toBe(ExitCode::SUCCESS)
            ->and($io->output())->toBe([
                '[OK] Configuration cache cleared: ' . $cachePath,
                '[INFO] Nothing to clear at: ' . $cachePath,
            ]);
    } finally {
        if (is_file($cachePath . '/__manifest.php')) {
            unlink($cachePath . '/__manifest.php');
        }
        if (is_dir($cachePath)) {
            rmdir($cachePath);
        }
        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
});

it('builds and clears every Webrick matcher through typed commands', function (string $matcher): void {
    $basePath = sys_get_temp_dir() . '/foundation-console-route-' . bin2hex(random_bytes(5));
    $routesPath = $basePath . '/routes';
    mkdir($routesPath, 0775, true);
    file_put_contents($routesPath . '/api.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/console-cache', static fn(): array => ['cached' => true]);
PHP);

    try {
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
                'router' => [
                    'cache' => false,
                    'files' => ['api.php'],
                    'middleware' => [
                        'globals' => [
                            'pre' => [],
                            'post' => [],
                        ],
                    ],
                ],
            ]),
            name: 'foundation-test',
        )->withIO(new BufferedIO());
        $cache = $matcher === 'sharded'
            ? $basePath . '/cache/routes'
            : $basePath . '/cache/' . $matcher . '.php';

        expect($console->run([
            'foundation-test',
            'route:cache',
            '--matcher=' . $matcher,
            '--cache=' . $cache,
        ]))->toBe(ExitCode::SUCCESS)
            ->and($console->run([
                'foundation-test',
                'route:clear',
                '--matcher=' . $matcher,
                '--cache=' . $cache,
            ]))->toBe(ExitCode::SUCCESS);
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
})->with(['fused', 'generated', 'sharded']);

function foundationConsoleRemoveDirectory(string $directory): void
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

<?php

declare(strict_types=1);

use Infocyph\Console\Command\Command;
use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Discovery\CommandManifestCompiler;
use Infocyph\Console\IO\BufferedIO;
use Infocyph\Foundation\Console\Command\AppReadyCommand;
use Infocyph\Foundation\Console\FoundationConsole;
use Infocyph\Foundation\Console\FoundationConsoleRuntime;
use Infocyph\Foundation\Foundation;

final class FoundationUserListFixtureCommand extends Command
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('reports:daily')
            ->description('Build the daily report.');
    }

    protected function handle(): int
    {
        return ExitCode::SUCCESS;
    }
}

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

it('lists Foundation commands under System and application commands by namespace', function (): void {
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
        commands: ['reports:daily' => FoundationUserListFixtureCommand::class],
    )->withIO($io);

    expect($console->run(['foundation-test', 'list']))->toBe(ExitCode::SUCCESS)
        ->and($io->outputText())->toContain(
            "Available commands:\n\nSystem:\n  app:ready",
            "\n\nreports:\n  reports:daily",
        )
        ->and($created)->toBeFalse();
});

it('boots preflight commands from a compiled command manifest', function (): void {
    $created = false;
    $directory = sys_get_temp_dir() . '/foundation-command-manifest-' . bin2hex(random_bytes(5));
    $manifest = $directory . '/commands.php';

    try {
        new CommandManifestCompiler()->write(FoundationConsole::commands([]), $manifest);
        $console = FoundationConsole::create(
            static function (?string $profile) use (&$created) {
                $created = true;

                return Foundation::console([
                    'base_path' => sys_get_temp_dir(),
                    'env' => $profile ?? 'testing',
                    '_config_cache' => false,
                ]);
            },
            name: 'foundation-test',
            version: '1.0.0',
            commandManifest: $manifest,
        )->withIO(new BufferedIO());

        expect($console->run(['foundation-test', '--version']))->toBe(ExitCode::SUCCESS)
            ->and($created)->toBeFalse()
            ->and($manifest)->toBeFile();
    } finally {
        foundationConsoleRemoveDirectory($directory);
    }
});

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

it('reuses the immutable Foundation configuration adapter', function (): void {
    $created = 0;
    $runtime = new FoundationConsoleRuntime(
        static function (?string $profile) use (&$created) {
            $created++;

            return Foundation::console([
                'base_path' => sys_get_temp_dir(),
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
            ]);
        },
    );

    $runtime->useProfile('testing');
    $first = $runtime->configuration();

    expect($runtime->configuration())->toBe($first)
        ->and($runtime->container())->toBe($runtime->application()->container())
        ->and($created)->toBe(1);
});

it('lists direct Infocyph modules without running Composer', function (): void {
    $basePath = dirname(__DIR__, 2);
    $io = new BufferedIO();
    $console = FoundationConsole::create(
        static fn(?string $profile) => Foundation::console([
            'base_path' => $basePath,
            'env' => $profile ?? 'testing',
            '_config_cache' => false,
        ]),
        name: 'foundation-test',
    )->withIO($io);

    expect($console->run(['foundation-test', 'module:list', '--json=true']))
        ->toBe(ExitCode::SUCCESS);

    $report = json_decode(implode("\n", $io->output()), true, flags: JSON_THROW_ON_ERROR);
    $modules = array_column($report['modules'], null, 'name');

    expect(array_keys($modules))->toBe([
        'cache',
        'communication',
        'crypto',
        'db',
        'filesystem',
        'logging',
        'messaging',
        'otp',
        'passkeys',
        'resources',
        'session',
        'validation',
    ])->and($modules['db']['package'])->toBe('infocyph/dblayer')
        ->and($modules['db']['direct'])->toBeFalse()
        ->and($modules['db']['installed'])->toBeTrue();
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
            ->and($console->run([
                'foundation',
                'config:cache',
                '--path=' . $cachePath,
                '--type=single',
            ]))->toBe(ExitCode::SUCCESS)
            ->and(glob($cachePath . '/*.php'))->not->toBeEmpty()
            ->and($io->output())->toBe([
                '[OK] Configuration cache cleared: ' . $cachePath,
                '[INFO] Nothing to clear at: ' . $cachePath,
                '[OK] Configuration cached (single): ' . $cachePath,
            ]);
    } finally {
        foreach (glob($cachePath . '/*.php') ?: [] as $cacheFile) {
            unlink($cacheFile);
        }
        if (is_dir($cachePath)) {
            rmdir($cachePath);
        }
        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
});

it('clears direct command shards without removing unrelated console cache files', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-command-cache-' . bin2hex(random_bytes(5));
    $cachePath = $basePath . '/bootstrap/cache/console';
    $manifest = $cachePath . '/commands.php';
    $entry = $cachePath . '/commands-' . hash('sha256', 'example') . '.php';
    $sentinel = $cachePath . '/.gitignore';
    mkdir($cachePath, 0775, true);
    file_put_contents($manifest, '<?php return [];');
    file_put_contents($entry, '<?php return [];');
    file_put_contents($sentinel, "*\n!.gitignore\n");

    try {
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
            ]),
        )->withIO(new BufferedIO());

        expect($console->run([
            'foundation',
            'command:clear',
            '--path=' . $manifest,
        ]))->toBe(ExitCode::SUCCESS)
            ->and($manifest)->not->toBeFile()
            ->and($entry)->not->toBeFile()
            ->and($sentinel)->toBeFile();
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
});

it('builds and clears every application cache through aggregate commands', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-optimize-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents($basePath . '/config/router.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'files' => ['api.php'],
    'matcher' => 'fused',
];
PHP);
    file_put_contents($basePath . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/optimized', static fn(): array => ['optimized' => true]);
PHP);
    file_put_contents($basePath . '/routes/console.php', "<?php\n\nreturn [];\n");
    file_put_contents($basePath . '/routes/schedule.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Console\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('app:ready')->hourly()->withoutOverlap();
};
PHP);

    try {
        $io = new BufferedIO();
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
            ]),
        )->withIO($io);

        expect($console->run(['foundation', 'optimize']))->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->toBeFile()
            ->and($basePath . '/bootstrap/cache/routes/fused.php')->toBeFile()
            ->and($basePath . '/bootstrap/cache/console/commands.php')->toBeFile()
            ->and($basePath . '/bootstrap/cache/console/schedule.php')->toBeFile()
            ->and($console->run(['foundation', 'optimize']))->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->toBeFile()
            ->and($basePath . '/bootstrap/cache/routes/fused.php')->toBeFile()
            ->and($console->run(['foundation', 'optimize:clear']))->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->not->toBeFile()
            ->and($basePath . '/bootstrap/cache/routes/fused.php')->not->toBeFile()
            ->and($basePath . '/bootstrap/cache/console/commands.php')->not->toBeFile()
            ->and($basePath . '/bootstrap/cache/console/schedule.php')->not->toBeFile()
            ->and($console->run(['foundation', 'optimize:clear']))->toBe(ExitCode::SUCCESS)
            ->and(implode("\n", $io->output()))->toContain('Application caches cleared.');
    } finally {
        foundationConsoleRemoveDirectory($basePath);
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

it('runs unlocked schedules without resolving the configured lock provider', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-unlocked-schedule-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents($basePath . '/infbyte', "<?php\n\nexit(0);\n");
    file_put_contents($basePath . '/routes/schedule.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Console\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('reports:build')->everyMinute();
};
PHP);

    try {
        $io = new BufferedIO();
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
                'cache' => [
                    'lock' => [
                        'driver' => 'unsupported',
                        'store' => 'memory',
                    ],
                    'stores' => [
                        'memory' => ['driver' => 'memory'],
                    ],
                ],
            ]),
        )->withIO($io);

        expect($console->run(['foundation', 'schedule:run']))->toBe(ExitCode::SUCCESS)
            ->and(implode("\n", $io->output()))->toContain('reports:build: exit 0');
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
});

it('lists worker class maps without autoloading unselected providers', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-worker-list-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents(
        $basePath . '/routes/workers.php',
        "<?php\n\nreturn ['emails' => 'App\\\\Workers\\\\EmailWorker'];\n",
    );

    try {
        $io = new BufferedIO();
        $console = FoundationConsole::create(
            static fn(?string $profile) => Foundation::console([
                'base_path' => $basePath,
                'env' => $profile ?? 'testing',
                '_config_cache' => false,
            ]),
        )->withIO($io);

        expect($console->run(['foundation', 'worker:list']))->toBe(ExitCode::SUCCESS)
            ->and(implode("\n", $io->output()))->toContain('App\\Workers\\EmailWorker');
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
});

it('creates Foundation application artifacts from safe built-in stubs', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-create-artifacts-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);
    $io = new BufferedIO();
    $console = FoundationConsole::create(
        static fn(?string $profile) => Foundation::console([
            'base_path' => $basePath,
            'env' => $profile ?? 'testing',
            '_config_cache' => false,
        ]),
    )->withIO($io);
    $artifacts = [
        ['create:class', 'Services/ReportBuilder', 'app/Services/ReportBuilder.php', 'final class ReportBuilder'],
        ['create:command', 'Reports/Daily', 'app/Console/Commands/Reports/DailyCommand.php', "->name('reports:daily')"],
        ['create:controller', 'Admin/User', 'app/Http/Controllers/Admin/UserController.php', 'final readonly class UserController'],
        ['create:enum', 'OrderStatus', 'app/Enums/OrderStatus.php', 'enum OrderStatus'],
        ['create:event', 'UserRegistered', 'app/Events/UserRegisteredEvent.php', 'final readonly class UserRegisteredEvent'],
        ['create:exception', 'BillingFailed', 'app/Exceptions/BillingFailedException.php', 'extends \RuntimeException'],
        ['create:interface', 'BillingGateway', 'app/Contracts/BillingGatewayInterface.php', 'interface BillingGatewayInterface'],
        ['create:job', 'Billing/SendReceipt', 'app/Jobs/Billing/SendReceiptJob.php', 'final readonly class SendReceiptJob'],
        ['create:listener', 'SendWelcomeEmail', 'app/Listeners/SendWelcomeEmailListener.php', 'public function __invoke(object $event): void'],
        ['create:middleware', 'EnsureTenant', 'app/Http/Middleware/EnsureTenantMiddleware.php', 'final readonly class EnsureTenantMiddleware'],
        ['create:policy', 'Invoice', 'app/Policies/InvoicePolicy.php', 'implements PolicyInterface'],
        ['create:provider', 'Billing', 'app/Providers/BillingServiceProvider.php', 'final class BillingServiceProvider'],
        ['create:repository', 'User', 'app/Repositories/UserRepository.php', "return 'users';"],
        ['create:service', 'Billing', 'app/Services/BillingService.php', 'final class BillingService'],
        ['create:test', 'Http/UserAccess', 'tests/Feature/Http/UserAccessTest.php', "it('user access')->todo()"],
        ['create:trait', 'FormatsMoney', 'app/Concerns/FormatsMoneyTrait.php', 'trait FormatsMoneyTrait'],
        ['create:worker', 'Queue', 'app/Console/Workers/QueueWorker.php', 'implements WorkerProvider, WorkloadProbe'],
    ];

    try {
        foreach ($artifacts as [$command, $name, $relative, $expected]) {
            expect($console->run(['foundation', $command, $name]))->toBe(ExitCode::SUCCESS);
            $path = $basePath . '/' . $relative;
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unable to read generated artifact: ' . $path);
            }

            expect($path)->toBeFile()
                ->and($contents)->toContain($expected);
            token_get_all($contents, TOKEN_PARSE);
        }

        expect(implode("\n", $io->output()))
            ->toContain('Register App\\Console\\Commands\\Reports\\DailyCommand in routes/console.php.')
            ->toContain('Assign App\\Providers\\BillingServiceProvider to common, web, or console')
            ->toContain('Map a worker name to App\\Console\\Workers\\QueueWorker in routes/workers.php.');
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
});

it('protects generated artifacts from traversal and accidental replacement', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-create-safety-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);
    $console = FoundationConsole::create(
        static fn(?string $profile) => Foundation::console([
            'base_path' => $basePath,
            'env' => $profile ?? 'testing',
            '_config_cache' => false,
        ]),
    )->withIO(new BufferedIO());

    try {
        expect($console->run(['foundation', 'create:controller', 'Health']))
            ->toBe(ExitCode::SUCCESS);
        $path = $basePath . '/app/Http/Controllers/HealthController.php';
        file_put_contents($path, 'preserve');

        expect($console->run(['foundation', 'create:controller', 'Health']))
            ->toBe(ExitCode::INVALID_USAGE)
            ->and(file_get_contents($path))->toBe('preserve')
            ->and($console->run(['foundation', 'create:controller', 'Health', '--force']))
            ->toBe(ExitCode::SUCCESS)
            ->and(file_get_contents($path))->toContain('final readonly class HealthController')
            ->and($console->run(['foundation', 'create:service', 'MailService']))
            ->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/app/Services/MailService.php')->toBeFile()
            ->and($console->run(['foundation', 'create:class', '../Outside']))
            ->toBe(ExitCode::INVALID_USAGE)
            ->and($console->run(['foundation', 'create:repository', 'Person', '--table=invalid-name']))
            ->toBe(ExitCode::INVALID_USAGE)
            ->and($console->run(['foundation', 'create:repository', 'Person', '--table=reporting..people']))
            ->toBe(ExitCode::INVALID_USAGE)
            ->and($basePath . '/Outside.php')->not->toBeFile();
    } finally {
        foundationConsoleRemoveDirectory($basePath);
    }
});

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

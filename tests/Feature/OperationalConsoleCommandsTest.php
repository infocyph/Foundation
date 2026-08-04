<?php

declare(strict_types=1);

use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\IO\BufferedIO;
use Infocyph\Console\Application as ConsoleApplication;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\FoundationConsole;
use Infocyph\Foundation\Foundation;

it('reports application environment and redacted configuration', function (): void {
    $basePath = operationalConsoleDirectory('inspect');

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => [
                'name' => 'Operations Test',
                'env' => 'staging',
                'debug' => false,
                'load_env' => false,
            ],
            'auth' => [
                'token_secret' => 'never-print-this',
                'token_ttl' => 900,
            ],
            'router' => ['files' => []],
        ]);

        [$about, $aboutIo] = operationalConsole($application);
        expect($about->run(['foundation', 'about', '--json=true']))->toBe(ExitCode::SUCCESS)
            ->and($aboutIo->outputText())->toContain('Operations Test', 'staging', 'foundation');

        [$environment, $environmentIo] = operationalConsole($application);
        expect($environment->run(['foundation', 'env:show', '--json=true']))->toBe(ExitCode::SUCCESS)
            ->and($environmentIo->outputText())->toContain('staging', 'configuration_cached');

        [$configuration, $configurationIo] = operationalConsole($application);
        expect($configuration->run(['foundation', 'config:show', 'auth']))->toBe(ExitCode::SUCCESS)
            ->and($configurationIo->outputText())->toContain('[REDACTED]', 'token_ttl', '900')
            ->not->toContain('never-print-this');
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('loads project routes only for route inspection', function (): void {
    $basePath = operationalConsoleDirectory('routes');
    mkdir($basePath . '/routes', 0775, true);
    file_put_contents($basePath . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/operations', static fn(): array => ['ok' => true], [
    'name' => 'operations.index',
    'middleware' => ['audit'],
]);
PHP);

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
            'router' => ['files' => ['api.php']],
        ]);
        [$console, $io] = operationalConsole($application);

        expect($console->run(['foundation', 'route:list', '--json=true']))->toBe(ExitCode::SUCCESS)
            ->and($io->outputText())->toContain(
                '/operations',
                'operations.index',
                'audit',
            );
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('clears only the selected application cache store', function (): void {
    $basePath = operationalConsoleDirectory('cache');

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
            'cache' => [
                'default' => 'first',
                'stores' => [
                    'first' => ['driver' => 'memory'],
                    'second' => ['driver' => 'memory'],
                ],
            ],
        ]);
        $application->cache()->store('first')->set('selected', true);
        $application->cache()->store('second')->set('preserved', true);
        [$console] = operationalConsole($application);

        expect($console->run(['foundation', 'cache:clear', '--store=first']))->toBe(ExitCode::SUCCESS)
            ->and($application->cache()->store('first')->has('selected'))->toBeFalse()
            ->and($application->cache()->store('second')->get('preserved'))->toBeTrue();
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('inspects SQLite tables columns indexes and foreign keys', function (): void {
    $basePath = operationalConsoleDirectory('database');
    mkdir($basePath . '/database', 0775, true);
    $application = Foundation::console([
        'base_path' => $basePath,
        '_config_cache' => false,
        'app' => ['load_env' => false],
        'database' => [
            'default' => 'testing',
            'connections' => [
                'testing' => [
                    'driver' => 'sqlite',
                    'database' => 'database/testing.sqlite',
                ],
            ],
        ],
    ]);

    try {
        $schema = new SchemaManager($application->db()->connection());
        $schema->create('parents', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
        $schema->create('children', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->string('name');
            $table->index('name');
            $table->foreign('parent_id')->references('id')->on('parents')->onDelete('cascade');
        });

        [$database, $databaseIo] = operationalConsole($application);
        expect($database->run(['foundation', 'db:show', '--json=true']))->toBe(ExitCode::SUCCESS)
            ->and($databaseIo->outputText())->toContain('sqlite', 'parents', 'children');

        [$table, $tableIo] = operationalConsole($application);
        expect($table->run(['foundation', 'db:table', 'children']))->toBe(ExitCode::SUCCESS)
            ->and($tableIo->outputText())->toContain(
                'parent_id',
                'children_name_index',
                'parents',
                'CASCADE',
            );
    } finally {
        $application->db()->purge();
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('rotates the authentication secret atomically without displaying it', function (): void {
    $basePath = operationalConsoleDirectory('secret');
    mkdir($basePath . '/bootstrap/cache/config', 0775, true);
    file_put_contents($basePath . '/bootstrap/cache/config/__manifest.php', '<?php return [];');
    file_put_contents($basePath . '/.env', "APP_NAME=Example\nAUTH_TOKEN_SECRET=old-secret\n");

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
        ]);
        [$console, $io] = operationalConsole($application);

        expect($console->run(['foundation', 'secret:generate']))->toBe(ExitCode::INVALID_USAGE)
            ->and(file_get_contents($basePath . '/.env'))->toContain('old-secret')
            ->and($console->run(['foundation', 'secret:generate', '--force']))->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->not->toBeFile();

        $contents = file_get_contents($basePath . '/.env');
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the generated environment file.');
        }
        preg_match('/^AUTH_TOKEN_SECRET=([a-f0-9]{64})$/m', $contents, $matches);
        $secret = $matches[1] ?? null;
        if (!is_string($secret)) {
            throw new RuntimeException('The generated authentication secret is invalid.');
        }

        expect($secret)->not->toBe('old-secret')
            ->and($io->outputText())->not->toContain($secret)
            ->and(fileperms($basePath . '/.env') & 0777)->toBe(0600);
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('installs the application environment securely and idempotently', function (): void {
    $basePath = operationalConsoleDirectory('install');
    mkdir($basePath . '/bootstrap/cache/config', 0775, true);
    file_put_contents($basePath . '/bootstrap/cache/config/__manifest.php', '<?php return [];');
    file_put_contents(
        $basePath . '/.env.example',
        "APP_NAME=Example\nAUTH_TOKEN_SECRET=development-placeholder\n",
    );

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
        ]);
        [$console, $io] = operationalConsole($application);

        expect($console->run(['foundation', 'app:install']))->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/.env')->toBeFile()
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->not->toBeFile()
            ->and(fileperms($basePath . '/.env') & 0777)->toBe(0600);

        $contents = file_get_contents($basePath . '/.env');
        expect($contents)->toBeString()
            ->and($contents)->toMatch('/^AUTH_TOKEN_SECRET=[a-f0-9]{64}$/m')
            ->and($contents)->not->toContain('development-placeholder');

        $hash = hash_file('sha256', $basePath . '/.env');
        expect($console->run(['foundation', 'app:install']))->toBe(ExitCode::SUCCESS)
            ->and(hash_file('sha256', $basePath . '/.env'))->toBe($hash)
            ->and($io->outputText())->not->toContain('AUTH_TOKEN_SECRET=');
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('preserves an existing application secret during installation', function (): void {
    $basePath = operationalConsoleDirectory('install-existing');
    $secret = str_repeat('a', 64);
    file_put_contents($basePath . '/.env', "APP_NAME=Example\nAUTH_TOKEN_SECRET={$secret}\n");

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
        ]);
        [$console] = operationalConsole($application);

        expect($console->run(['foundation', 'app:install']))->toBe(ExitCode::SUCCESS)
            ->and(file_get_contents($basePath . '/.env'))->toContain($secret)
            ->and(fileperms($basePath . '/.env') & 0777)->toBe(0600);
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('rejects application installation without an environment source', function (): void {
    $basePath = operationalConsoleDirectory('install-missing');

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
        ]);
        [$console, $io] = operationalConsole($application);

        expect($console->run(['foundation', 'app:install']))->toBe(ExitCode::INVALID_USAGE)
            ->and($basePath . '/.env')->not->toBeFile()
            ->and($io->errorText())->toContain('Unable to read environment example file');
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

it('creates configured storage links idempotently and rejects conflicts', function (): void {
    $basePath = operationalConsoleDirectory('storage');
    mkdir($basePath . '/public', 0775, true);
    mkdir($basePath . '/storage', 0775, true);

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['load_env' => false],
            'filesystem' => [
                'links' => [
                    $basePath . '/public/storage' => $basePath . '/storage/app/public',
                ],
            ],
        ]);
        [$console, $io] = operationalConsole($application);

        expect($console->run(['foundation', 'storage:link']))->toBe(ExitCode::SUCCESS)
            ->and(is_link($basePath . '/public/storage'))->toBeTrue()
            ->and(realpath($basePath . '/public/storage'))->toBe(realpath($basePath . '/storage/app/public'))
            ->and($console->run(['foundation', 'storage:link']))->toBe(ExitCode::SUCCESS)
            ->and($io->outputText())->toContain('Already linked');

        unlink($basePath . '/public/storage');
        file_put_contents($basePath . '/public/storage', 'conflict');
        expect($console->run(['foundation', 'storage:link']))->toBe(ExitCode::INVALID_USAGE);
    } finally {
        operationalConsoleRemoveDirectory($basePath);
    }
});

/** @return array{ConsoleApplication,BufferedIO} */
function operationalConsole(Application $application): array
{
    $io = new BufferedIO();
    $console = FoundationConsole::create(
        static function (?string $profile) use ($application): Application {
            if ($profile !== null) {
                throw new LogicException('The prebuilt test application cannot switch profiles.');
            }

            return $application;
        },
        name: 'foundation',
    )->withIO($io);

    return [$console, $io];
}

function operationalConsoleDirectory(string $name): string
{
    $directory = sys_get_temp_dir() . '/foundation-' . $name . '-' . bin2hex(random_bytes(5));
    mkdir($directory, 0775, true);

    return $directory;
}

function operationalConsoleRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isLink() || !$file->isDir()
            ? unlink($file->getPathname())
            : rmdir($file->getPathname());
    }

    rmdir($directory);
}

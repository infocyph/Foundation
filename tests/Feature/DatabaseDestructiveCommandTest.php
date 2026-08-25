<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;

final class FoundationDestructiveCommandMigration implements Migration
{
    public function id(): string
    {
        return '20260825000300_create_foundation_destructive_probe';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->create('foundation_destructive_probe', static function (Blueprint $table): void {
            $table->increments();
            $table->string('value');
        });
    }

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->dropIfExists('foundation_destructive_probe');
    }
}

final class FoundationDestructiveCommandIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    public int $confirmations = 0;

    public function __construct(
        private readonly bool $interactiveMode = false,
        private readonly bool $confirmation = false,
    ) {}

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Choice input is not expected in this test IO.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->confirmations++;

        return $this->confirmation;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void {}

    public function interactive(): bool
    {
        return $this->interactiveMode;
    }

    public function json(mixed $value): void {}

    public function machineReadable(): bool
    {
        return false;
    }

    public function note(string $message): void {}

    public function password(string $question): string
    {
        throw new LogicException('Password input is not expected in this test IO.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Text input is not expected in this test IO.');
    }

    public function success(string $message): void {}

    public function table(array $headers, array $rows): void {}

    public function warning(string $message): void {}

    public function write(string $message): void {}

    public function writeln(string $message = ''): void {}
}

it('refuses db:wipe non-interactively without force and permits the forced operation', function (): void {
    [$basePath, $databasePath, $dispatcher] = foundationDestructiveCommandFixture('wipe');
    $pdo = foundationDestructiveCommandPdo($databasePath);
    $pdo->exec('CREATE TABLE foundation_wipe_probe (id INTEGER PRIMARY KEY)');
    unset($pdo);

    try {
        $denied = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun($dispatcher, ['infbyte', 'db:wipe', '--no-interaction'], $denied))
            ->toBe(ExitCode::FAILURE)
            ->and($denied->confirmations)->toBe(0)
            ->and($denied->errors)->toContain('db:wipe requires --force in non-interactive mode.')
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_wipe_probe'))->toBeTrue();

        $forced = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun(
            $dispatcher,
            ['infbyte', 'db:wipe', '--no-interaction', '--force'],
            $forced,
        ))->toBe(ExitCode::SUCCESS)
            ->and($forced->confirmations)->toBe(0)
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_wipe_probe'))->toBeFalse();
    } finally {
        DB::purge();
        foundationDestructiveCommandRemove($basePath);
    }
});

it('refuses migrate:reset non-interactively without force and permits the forced operation', function (): void {
    [$basePath, $databasePath, $dispatcher] = foundationDestructiveCommandFixture('reset');

    try {
        foundationDestructiveCommandMigrate($dispatcher);
        expect(foundationDestructiveCommandTableExists($databasePath, 'foundation_destructive_probe'))->toBeTrue();

        $denied = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun($dispatcher, ['infbyte', 'migrate:reset', '-n'], $denied))
            ->toBe(ExitCode::FAILURE)
            ->and($denied->confirmations)->toBe(0)
            ->and($denied->errors)->toContain('migrate:reset requires --force in non-interactive mode.')
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_destructive_probe'))->toBeTrue();

        $forced = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun(
            $dispatcher,
            ['infbyte', 'migrate:reset', '-n', '--force'],
            $forced,
        ))->toBe(ExitCode::SUCCESS)
            ->and($forced->confirmations)->toBe(0)
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_destructive_probe'))->toBeFalse();
    } finally {
        DB::purge();
        foundationDestructiveCommandRemove($basePath);
    }
});

it('refuses migrate:fresh non-interactively without force and only rebuilds after force', function (): void {
    [$basePath, $databasePath, $dispatcher] = foundationDestructiveCommandFixture('fresh');

    try {
        foundationDestructiveCommandMigrate($dispatcher);
        foundationDestructiveCommandInsertMarker($databasePath, 'fresh-marker');

        $denied = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun($dispatcher, ['infbyte', 'migrate:fresh', '-n'], $denied))
            ->toBe(ExitCode::FAILURE)
            ->and($denied->confirmations)->toBe(0)
            ->and($denied->errors)->toContain('migrate:fresh requires --force in non-interactive mode.')
            ->and(foundationDestructiveCommandMarkerCount($databasePath))->toBe(1);

        $forced = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun(
            $dispatcher,
            ['infbyte', 'migrate:fresh', '-n', '--force'],
            $forced,
        ))->toBe(ExitCode::SUCCESS)
            ->and($forced->confirmations)->toBe(0)
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_destructive_probe'))->toBeTrue()
            ->and(foundationDestructiveCommandMarkerCount($databasePath))->toBe(0);
    } finally {
        DB::purge();
        foundationDestructiveCommandRemove($basePath);
    }
});

it('refuses migrate:refresh non-interactively without force and only rebuilds after force', function (): void {
    [$basePath, $databasePath, $dispatcher] = foundationDestructiveCommandFixture('refresh');

    try {
        foundationDestructiveCommandMigrate($dispatcher);
        foundationDestructiveCommandInsertMarker($databasePath, 'refresh-marker');

        $denied = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun($dispatcher, ['infbyte', 'migrate:refresh', '-n'], $denied))
            ->toBe(ExitCode::FAILURE)
            ->and($denied->confirmations)->toBe(0)
            ->and($denied->errors)->toContain('migrate:refresh requires --force in non-interactive mode.')
            ->and(foundationDestructiveCommandMarkerCount($databasePath))->toBe(1);

        $forced = new FoundationDestructiveCommandIO();
        expect(foundationDestructiveCommandRun(
            $dispatcher,
            ['infbyte', 'migrate:refresh', '-n', '--force'],
            $forced,
        ))->toBe(ExitCode::SUCCESS)
            ->and($forced->confirmations)->toBe(0)
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_destructive_probe'))->toBeTrue()
            ->and(foundationDestructiveCommandMarkerCount($databasePath))->toBe(0);
    } finally {
        DB::purge();
        foundationDestructiveCommandRemove($basePath);
    }
});

it('requires force for destructive database commands in production even when confirmation would succeed', function (): void {
    [$basePath, $databasePath, $dispatcher] = foundationDestructiveCommandFixture('production', 'production');
    $pdo = foundationDestructiveCommandPdo($databasePath);
    $pdo->exec('CREATE TABLE foundation_production_probe (id INTEGER PRIMARY KEY)');
    unset($pdo);

    try {
        $io = new FoundationDestructiveCommandIO(interactiveMode: true, confirmation: true);
        expect(foundationDestructiveCommandRun($dispatcher, ['infbyte', 'db:wipe'], $io))
            ->toBe(ExitCode::FAILURE)
            ->and($io->confirmations)->toBe(0)
            ->and($io->errors)->toContain(
                'db:wipe is destructive in production; rerun with --force after explicit approval.',
            )
            ->and(foundationDestructiveCommandTableExists($databasePath, 'foundation_production_probe'))->toBeTrue();
    } finally {
        DB::purge();
        foundationDestructiveCommandRemove($basePath);
    }
});

/** @return array{string,string,CommandDispatcher} */
function foundationDestructiveCommandFixture(string $name, string $environment = 'testing'): array
{
    $basePath = sys_get_temp_dir() . '/foundation-destructive-' . $name . '-' . bin2hex(random_bytes(6));
    $databasePath = $basePath . '/database/app.sqlite';
    mkdir($basePath . '/database', 0775, true);

    $config = [
        'base_path' => $basePath,
        'app' => [
            'base_path' => $basePath,
            'env' => $environment,
        ],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/app.sqlite',
                ],
            ],
            'migrations' => [
                'classes' => [FoundationDestructiveCommandMigration::class],
                'table' => 'migrations',
                'lock_store' => null,
                'lock_wait_seconds' => 10.0,
                'lock_lease_seconds' => 300.0,
            ],
            'seeders' => [],
        ],
    ];

    return [
        $basePath,
        $databasePath,
        CommandDispatcher::project(
            $config,
            manifestPath: $basePath . '/bootstrap/cache/commands.php',
            routesPath: $basePath . '/routes/console.php',
        ),
    ];
}

function foundationDestructiveCommandInsertMarker(string $databasePath, string $value): void
{
    $statement = foundationDestructiveCommandPdo($databasePath)->prepare(
        'INSERT INTO foundation_destructive_probe (value) VALUES (?)',
    );
    $statement->execute([$value]);
}

function foundationDestructiveCommandMarkerCount(string $databasePath): int
{
    $count = foundationDestructiveCommandPdo($databasePath)
        ->query('SELECT COUNT(*) FROM foundation_destructive_probe')
        ?->fetchColumn();

    return is_int($count) ? $count : (int) $count;
}

function foundationDestructiveCommandMigrate(CommandDispatcher $dispatcher): void
{
    $io = new FoundationDestructiveCommandIO();
    $exit = foundationDestructiveCommandRun($dispatcher, ['infbyte', 'migrate', '-n'], $io);
    if ($exit !== ExitCode::SUCCESS) {
        throw new RuntimeException('Migration fixture setup failed: ' . implode('; ', $io->errors));
    }
}

function foundationDestructiveCommandPdo(string $databasePath): PDO
{
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function foundationDestructiveCommandRun(
    CommandDispatcher $dispatcher,
    array $argv,
    FoundationDestructiveCommandIO $io,
): int {
    try {
        return $dispatcher->run($argv, $io);
    } finally {
        DB::purge();
    }
}

function foundationDestructiveCommandTableExists(string $databasePath, string $table): bool
{
    $statement = foundationDestructiveCommandPdo($databasePath)->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
    );
    $statement->execute([$table]);

    return $statement->fetchColumn() !== false;
}

function foundationDestructiveCommandRemove(string $directory): void
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
            foundationDestructiveCommandRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

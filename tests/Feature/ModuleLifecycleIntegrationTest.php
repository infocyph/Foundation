<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;

final class FoundationModuleLifecycleIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $payloads = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in module lifecycle tests.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void
    {
        unset($message);
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->payloads[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in module lifecycle tests.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in module lifecycle tests.');
    }

    public function success(string $message): void
    {
        unset($message);
    }

    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    public function warning(string $message): void
    {
        unset($message);
    }

    public function write(string $message): void
    {
        unset($message);
    }

    public function writeln(string $message = ''): void
    {
        unset($message);
    }

    public function lastPayload(): mixed
    {
        return $this->payloads === [] ? null : $this->payloads[array_key_last($this->payloads)];
    }
}

it('publishes the three-group nine-feature public command inventory in version two', function (): void {
    $path = moduleLifecycleBasePath('public-inventory');
    try {
        $dispatcher = moduleLifecycleDispatcher($path);
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:list'], $io))->toBe(ExitCode::SUCCESS);

        $payload = $io->lastPayload();
        expect($payload['schema_version'])->toBe(2)
            ->and(array_column($payload['modules'], 'name'))->toBe(['data', 'security', 'execution'])
            ->and(array_merge(...array_column($payload['modules'], 'features')))->toBe([
                'data.database', 'data.filesystem', 'security.crypto', 'security.validation',
                'security.mfa', 'security.passkey', 'execution.runtime',
                'execution.messaging', 'execution.communication',
            ]);

        $details = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:show', 'data'], $details))->toBe(ExitCode::SUCCESS)
            ->and($details->lastPayload()['module'])->toBe('data')
            ->and($details->lastPayload()['schema_version'])->toBe(2)
            ->and(array_keys($details->lastPayload()['states']))->toBe(['data.database', 'data.filesystem']);
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

it('requires explicit qualified optional features for every planning or lifecycle mutation', function (): void {
    $path = moduleLifecycleBasePath('qualified-only');
    moduleLifecycleWriteComposer($path, []);
    try {
        $dispatcher = moduleLifecycleDispatcher($path);
        foreach ([
            ['module:install', 'data'],
            ['module:enable', 'security'],
            ['module:plan', 'execution'],
            ['module:remove', 'core.auth'],
            ['module:config:publish', 'qa.phpforge'],
            ['module:schema:install', 'session'],
            ['module:install', 'auth'],
        ] as [$command, $name]) {
            $io = new FoundationModuleLifecycleIO();
            expect(moduleLifecycleRun($dispatcher, ['infbyte', $command, $name], $io))
                ->toBe(ExitCode::FAILURE)
                ->and($io->errors)->not->toBeEmpty();
        }
        expect($path . '/config/modules.php')->not->toBeFile()
            ->and($path . '/commands.jsonl')->not->toBeFile();
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

it('plans optional specialist packages and conditional dependencies without activation', function (): void {
    $path = moduleLifecycleBasePath('v2-plan');
    moduleLifecycleWriteComposer($path, []);
    try {
        $dispatcher = moduleLifecycleDispatcher($path, [
            'messaging' => ['durable' => ['enabled' => true]],
        ]);
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:plan', 'execution.messaging'], $io))
            ->toBe(ExitCode::SUCCESS);
        $plan = $io->lastPayload();
        expect($plan['schema_version'])->toBe(2)
            ->and($plan['requested'])->toBe(['execution.messaging'])
            ->and($plan['required'])->toBe(['data.database', 'execution.messaging'])
            ->and($plan['effects']['require'])->toBe([
                'infocyph/dblayer' => '^6.1',
                'infocyph/omnibus' => '^3.0',
            ])
            ->and($plan['effects']['activate'])->toBe([]);
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

it('executes v2 installation dry runs and preserves Composer and activation state', function (): void {
    $path = moduleLifecycleBasePath('dry-run-v2');
    moduleLifecycleWriteComposer($path, []);
    try {
        $dispatcher = moduleLifecycleDispatcher($path);
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, [
            'infbyte', 'module:install', 'data', '--feature=filesystem', '--dry-run',
        ], $io))->toBe(ExitCode::SUCCESS)
            ->and($io->lastPayload()['schema_version'])->toBe(2)
            ->and($io->lastPayload()['status'])->toBe('planned')
            ->and($io->lastPayload()['features'])->toBe(['data.filesystem'])
            ->and($path . '/config/modules.php')->not->toBeFile()
            ->and($path . '/commands.jsonl')->not->toBeFile();
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

it('rejects enablement before the selected package is installed', function (): void {
    $path = moduleLifecycleBasePath('activation-v2');
    moduleLifecycleWriteComposer($path, []);
    try {
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun(moduleLifecycleDispatcher($path), [
            'infbyte', 'module:enable', 'data.database',
        ], $io))->toBe(ExitCode::FAILURE)
            ->and(implode(' ', $io->errors))->toContain('Install required package infocyph/dblayer')
            ->and($path . '/config/modules.php')->not->toBeFile();
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

it('keeps schema sync empty without selected specialist ownership', function (): void {
    $path = moduleLifecycleBasePath('core-only-schema');
    moduleLifecycleWriteComposer($path, []);
    try {
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun(moduleLifecycleDispatcher($path), [
            'infbyte', 'module:schema:sync',
        ], $io))->toBe(ExitCode::SUCCESS)
            ->and($io->lastPayload())->toBe(['schema_version' => 2, 'schemas' => []]);
    } finally {
        moduleLifecycleRemoveDirectory($path);
    }
});

function moduleLifecycleBasePath(string $name): string
{
    $basePath = sys_get_temp_dir() . '/foundation-module-lifecycle-' . $name . '-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    return $basePath;
}

/**
 * @param array<string,mixed> $config
 */
function moduleLifecycleDispatcher(string $basePath, array $config = []): CommandDispatcher
{
    $baseConfig = [
        'base_path' => $basePath,
        '_config_cache' => false,
        'app' => [
            'base_path' => $basePath,
            'env' => 'testing',
        ],
    ];

    return CommandDispatcher::project(
        array_replace_recursive($baseConfig, $config),
        manifestPath: $basePath . '/bootstrap/cache/commands.php',
        routesPath: $basePath . '/routes/console.php',
    );
}

/** @return array{Closure():void,string} */
function moduleLifecycleComposerStub(string $basePath): array
{
    $binPath = $basePath . '/bin';
    $commandLog = $basePath . '/commands.jsonl';
    $composerPath = $binPath . '/composer';
    $originalPath = getenv('PATH');
    $originalLog = getenv('FOUNDATION_MODULE_COMMAND_LOG');
    mkdir($binPath, 0775, true);
    file_put_contents($composerPath, <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$log = getenv('FOUNDATION_MODULE_COMMAND_LOG');
if (!is_string($log) || $log === '') {
    exit(2);
}
file_put_contents(
    $log,
    json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . PHP_EOL,
    FILE_APPEND,
);
PHP);
    chmod($composerPath, 0755);
    putenv('PATH=' . $binPath . PATH_SEPARATOR . (is_string($originalPath) ? $originalPath : ''));
    putenv('FOUNDATION_MODULE_COMMAND_LOG=' . $commandLog);

    $restore = static function () use ($originalPath, $originalLog): void {
        is_string($originalPath) ? putenv('PATH=' . $originalPath) : putenv('PATH');
        is_string($originalLog)
            ? putenv('FOUNDATION_MODULE_COMMAND_LOG=' . $originalLog)
            : putenv('FOUNDATION_MODULE_COMMAND_LOG');
    };

    return [$restore, $commandLog];
}

/** @return list<list<string>> */
function moduleLifecycleCommands(string $commandLog): array
{
    return array_map(
        static function (string $command): array {
            $decoded = json_decode($command, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_any($decoded, static fn(mixed $value): bool => !is_string($value))) {
                throw new RuntimeException('Invalid module lifecycle command log entry.');
            }

            return array_values($decoded);
        },
        file($commandLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );
}

/** @param array<string,string> $requirements */
function moduleLifecycleWriteComposer(string $basePath, array $requirements): void
{
    file_put_contents($basePath . '/composer.json', json_encode([
        'name' => 'example/application',
        'require' => $requirements,
    ], JSON_THROW_ON_ERROR));
}

/** @param list<string> $argv */
function moduleLifecycleRun(
    CommandDispatcher $dispatcher,
    array $argv,
    FoundationModuleLifecycleIO $io,
): int {
    try {
        return $dispatcher->run($argv, $io);
    } finally {
        DB::purge();
    }
}

function moduleLifecycleScalar(string $databasePath, string $sql): mixed
{
    $statement = new PDO('sqlite:' . $databasePath)->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Unable to execute module lifecycle scalar query.');
    }

    return $statement->fetchColumn();
}

function moduleLifecycleTableExists(string $databasePath, string $table): bool
{
    if (!is_file($databasePath)) {
        return false;
    }
    $statement = new PDO('sqlite:' . $databasePath)->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
    );
    if ($statement === false) {
        throw new RuntimeException('Unable to prepare module lifecycle table probe.');
    }
    $statement->execute([$table]);

    return $statement->fetchColumn() !== false;
}

function moduleLifecycleRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

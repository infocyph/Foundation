<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Module\ModuleCatalog;

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

it('exposes canonical module list and alias-aware module details through the command dispatcher', function (): void {
    $basePath = moduleLifecycleBasePath('listing');

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath);
        $list = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:list'], $list))->toBe(ExitCode::SUCCESS);

        $modules = $list->lastPayload();
        expect($modules)->toBeArray();
        $database = is_array($modules)
            ? array_find($modules, static fn(mixed $module): bool => is_array($module) && ($module['name'] ?? null) === 'database')
            : null;
        expect($database)->toBeArray()
            ->and($database['schema_version'] ?? null)->toBe(1)
            ->and($database['installed'] ?? null)->toBeFalse()
            ->and($database['ownership_unknown'] ?? null)->toBeTrue()
            ->and($database['enabled'] ?? null)->toBeTrue()
            ->and($database['activation_explicit'] ?? null)->toBeFalse()
            ->and($database['configured'] ?? null)->toBeTrue()
            ->and($database['config_published'] ?? null)->toBeFalse()
            ->and($database['packages']['infocyph/dblayer']['constraint'] ?? null)->toBe('^5.1');

        $show = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:show', 'db'], $show))->toBe(ExitCode::SUCCESS);
        $details = $show->lastPayload();
        expect($details)->toBeArray()
            ->and($details['schema_version'] ?? null)->toBe(1)
            ->and($details['name'] ?? null)->toBe('database')
            ->and($details['requested'] ?? null)->toBe('db')
            ->and($details['ownership_unknown'] ?? null)->toBeTrue()
            ->and($details['enabled'] ?? null)->toBeTrue()
            ->and($details['configured'] ?? null)->toBeTrue()
            ->and($details['config_published'] ?? null)->toBeFalse()
            ->and($details['packages']['infocyph/dblayer']['constraint'] ?? null)->toBe('^5.1')
            ->and($details['schema_status'] ?? null)->toBe([]);
    } finally {
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('explains module dependency plans without mutating the application', function (): void {
    $basePath = moduleLifecycleBasePath('plan');
    moduleLifecycleWriteComposer($basePath, ['infocyph/omnibus' => '^2.6']);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath, [
            'app' => ['capabilities' => ['messaging']],
            'messaging' => ['durable' => ['enabled' => true]],
        ]);
        $plan = new FoundationModuleLifecycleIO();

        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:plan', 'messaging'], $plan))
            ->toBe(ExitCode::SUCCESS);

        $payload = $plan->lastPayload();
        expect($payload)->toBeArray()
            ->and($payload['schema_version'] ?? null)->toBe(1)
            ->and($payload['module'] ?? null)->toBe('messaging')
            ->and($payload['packages_to_add'] ?? null)->toBe([])
            ->and($payload['dependencies']['active'][0]['target'] ?? null)->toBe('database')
            ->and($payload['dependencies']['active'][0]['satisfied'] ?? true)->toBeFalse()
            ->and($payload['config'] ?? null)->toBe(['messaging.php'])
            ->and($payload['schemas'] ?? null)->toBe(['messaging']);
    } finally {
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('keeps CacheLayer core-owned and outside the module catalog', function (): void {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $catalog = new ModuleCatalog();

    expect($composer['require']['infocyph/cachelayer'] ?? null)->toBe('^3.4')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('infocyph/cachelayer')
        ->and($composer['suggest'] ?? [])->not->toHaveKey('infocyph/cachelayer')
        ->and(array_keys($catalog->all()))->not->toContain('cache')
        ->and(fn() => $catalog->resolve('cache'))
        ->toThrow(InvalidArgumentException::class, 'Unknown module or feature "cache".')
        ->and(fn() => $catalog->resolve('cachelayer'))
        ->toThrow(InvalidArgumentException::class, 'Unknown module or feature "cachelayer".');
});

it('persists explicit module activation without rewriting application capability config', function (): void {
    $basePath = moduleLifecycleBasePath('activation');
    moduleLifecycleWriteComposer($basePath, ['infocyph/dblayer' => '^5.1']);

    try {
        $enableDispatcher = moduleLifecycleDispatcher($basePath, [
            'app' => ['capabilities' => []],
        ]);
        $enable = new FoundationModuleLifecycleIO();

        expect(moduleLifecycleRun($enableDispatcher, ['infbyte', 'module:enable', 'database'], $enable))
            ->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/config/modules.php')->toBeFile();

        $enabledDispatcher = moduleLifecycleDispatcher($basePath, [
            'app' => ['capabilities' => []],
        ]);
        $showEnabled = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($enabledDispatcher, ['infbyte', 'module:show', 'database'], $showEnabled))
            ->toBe(ExitCode::SUCCESS);

        $enabled = $showEnabled->lastPayload();
        expect($enabled)->toBeArray()
            ->and($enabled['enabled'] ?? false)->toBeTrue()
            ->and($enabled['activation_explicit'] ?? false)->toBeTrue();

        $disable = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($enabledDispatcher, ['infbyte', 'module:disable', 'database'], $disable))
            ->toBe(ExitCode::SUCCESS);

        $disabledDispatcher = moduleLifecycleDispatcher($basePath, [
            'app' => ['capabilities' => []],
        ]);
        $showDisabled = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($disabledDispatcher, ['infbyte', 'module:show', 'database'], $showDisabled))
            ->toBe(ExitCode::SUCCESS);

        $disabled = $showDisabled->lastPayload();
        expect($disabled)->toBeArray()
            ->and($disabled['enabled'] ?? true)->toBeFalse()
            ->and($disabled['activation_explicit'] ?? false)->toBeTrue();
    } finally {
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('runs module install and direct-package removal dry-runs and refuses built-in removal', function (): void {
    $basePath = moduleLifecycleBasePath('composer');
    [$restoreEnvironment, $commandLog] = moduleLifecycleComposerStub($basePath);
    moduleLifecycleWriteComposer($basePath, [
        'infocyph/dblayer' => '^5.0',
        'infocyph/omnibus' => '^2.6',
    ]);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath, ['app' => ['capabilities' => []]]);
        $install = new FoundationModuleLifecycleIO();
        $remove = new FoundationModuleLifecycleIO();
        $builtIn = new FoundationModuleLifecycleIO();

        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:install', 'db', '--dry-run'], $install))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'db', '--dry-run'], $remove))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'session', '--dry-run'], $builtIn))
            ->toBe(ExitCode::FAILURE)
            ->and($builtIn->errors)->toContain('Module "session" is built into Foundation.');

        moduleLifecycleWriteComposer($basePath, ['infocyph/omnibus' => '^2.6']);
        $notDirect = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'db', '--dry-run'], $notDirect))
            ->toBe(ExitCode::SUCCESS);

        expect(moduleLifecycleCommands($commandLog))->toBe([
            ['require', 'infocyph/dblayer:^5.1', '--with-all-dependencies', '--no-interaction', '--dry-run'],
            ['remove', 'infocyph/dblayer', '--with-all-dependencies', '--no-interaction', '--dry-run'],
        ]);
    } finally {
        $restoreEnvironment();
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('installs and removes auth features without broadening shared package ownership', function (): void {
    $basePath = moduleLifecycleBasePath('auth-features');
    [$restoreEnvironment, $commandLog] = moduleLifecycleComposerStub($basePath);
    moduleLifecycleWriteComposer($basePath, []);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath);

        $core = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:install', 'auth', '--dry-run'], $core))
            ->toBe(ExitCode::SUCCESS)
            ->and($core->lastPayload()['package_action'] ?? null)->toBe('none')
            ->and(is_file($commandLog))->toBeFalse();

        $otp = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun(
            $dispatcher,
            ['infbyte', 'module:install', 'auth', '--feature=otp', '--dry-run'],
            $otp,
        ))->toBe(ExitCode::SUCCESS)
            ->and($otp->lastPayload()['features'] ?? null)->toBe(['otp']);

        $passkey = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:install', 'passkeys', '--dry-run'], $passkey))
            ->toBe(ExitCode::SUCCESS)
            ->and($passkey->lastPayload()['features'] ?? null)->toBe(['passkey']);

        $both = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun(
            $dispatcher,
            [
                'infbyte',
                'module:install',
                'auth',
                '--feature=otp',
                '--feature=passkey',
                '--dry-run',
            ],
            $both,
        ))->toBe(ExitCode::SUCCESS)
            ->and($both->lastPayload()['features'] ?? null)->toBe(['otp', 'passkey']);

        moduleLifecycleWriteComposer($basePath, [
            'infocyph/otp' => '^6.1',
            'web-auth/webauthn-lib' => '^5.3.5',
        ]);
        $removePasskey = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun(
            $dispatcher,
            ['infbyte', 'module:remove', 'auth', '--feature=passkey', '--dry-run'],
            $removePasskey,
        ))->toBe(ExitCode::SUCCESS)
            ->and($removePasskey->lastPayload()['features'] ?? null)->toBe(['passkey']);

        moduleLifecycleWriteComposer($basePath, ['infocyph/otp' => '^6.1']);
        $removeOtp = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'otp', '--dry-run'], $removeOtp))
            ->toBe(ExitCode::SUCCESS)
            ->and($removeOtp->lastPayload()['features'] ?? null)->toBe(['otp']);

        expect(moduleLifecycleCommands($commandLog))->toBe([
            ['require', 'infocyph/otp:^6.1', '--with-all-dependencies', '--update-no-dev', '--dry-run'],
            [
                'require',
                'infocyph/otp:^6.1',
                'web-auth/webauthn-lib:^5.3.5',
                '--with-all-dependencies',
                '--update-no-dev',
                '--dry-run',
            ],
            [
                'require',
                'infocyph/otp:^6.1',
                'web-auth/webauthn-lib:^5.3.5',
                '--with-all-dependencies',
                '--update-no-dev',
                '--dry-run',
            ],
            [
                'remove',
                'web-auth/webauthn-lib',
                '--with-all-dependencies',
                '--update-no-dev',
                '--dry-run',
            ],
            [
                'remove',
                'infocyph/otp',
                '--with-all-dependencies',
                '--update-no-dev',
                '--dry-run',
            ],
        ]);
    } finally {
        $restoreEnvironment();
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('refuses module removal when direct Composer ownership is unknown', function (): void {
    $basePath = moduleLifecycleBasePath('unknown-remove');
    [$restoreEnvironment, $commandLog] = moduleLifecycleComposerStub($basePath);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath);
        $io = new FoundationModuleLifecycleIO();

        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'db', '--dry-run'], $io))
            ->toBe(ExitCode::FAILURE)
            ->and($io->errors)->toContain(
                'Unable to determine direct Composer ownership: Application composer.json is missing or unreadable.',
            )
            ->and(is_file($commandLog))->toBeFalse();
    } finally {
        $restoreEnvironment();
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('publishes module config through the command boundary and preserves duplicate application-owned config', function (): void {
    $basePath = moduleLifecycleBasePath('publish');

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath);
        $first = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:config:publish', 'db'], $first))
            ->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/config/database.php')->toBeFile();

        $firstPayload = $first->lastPayload();
        expect($firstPayload)->toBeArray()
            ->and($firstPayload['module'] ?? null)->toBe('database')
            ->and($firstPayload['requested'] ?? null)->toBe('db')
            ->and($firstPayload['published'] ?? null)->toBe([$basePath . '/config/database.php']);

        $owned = "<?php\n\nreturn ['owned' => true];\n";
        file_put_contents($basePath . '/config/database.php', $owned);
        $second = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:config:publish', 'database'], $second))
            ->toBe(ExitCode::SUCCESS)
            ->and(file_get_contents($basePath . '/config/database.php'))->toBe($owned);

        $secondPayload = $second->lastPayload();
        expect($secondPayload)->toBeArray()
            ->and($secondPayload['published'] ?? null)->toBe([])
            ->and($secondPayload['existing'] ?? null)->toBe([$basePath . '/config/database.php']);
    } finally {
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('preserves application config and database data when an optional module is removed', function (): void {
    $basePath = moduleLifecycleBasePath('preserve');
    [$restoreEnvironment, $commandLog] = moduleLifecycleComposerStub($basePath);
    moduleLifecycleWriteComposer($basePath, ['infocyph/dblayer' => '^5.0']);
    mkdir($basePath . '/config', 0775, true);
    mkdir($basePath . '/database', 0775, true);
    $configPath = $basePath . '/config/preserve.php';
    $databasePath = $basePath . '/database/application.sqlite';
    file_put_contents($configPath, "<?php\nreturn ['marker' => 'keep'];\n");
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->exec('CREATE TABLE application_records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO application_records (id, value) VALUES (?, ?)');
    if ($statement === false) {
        throw new RuntimeException('Unable to prepare module preservation fixture.');
    }
    $statement->execute([1, 'keep']);
    unset($statement, $pdo);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath);
        $io = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:remove', 'database'], $io))
            ->toBe(ExitCode::SUCCESS)
            ->and(file_get_contents($configPath))->toBe("<?php\nreturn ['marker' => 'keep'];\n")
            ->and(moduleLifecycleScalar($databasePath, 'SELECT value FROM application_records WHERE id = 1'))
            ->toBe('keep')
            ->and(moduleLifecycleCommands($commandLog))->toBe([
                ['remove', 'infocyph/dblayer', '--with-all-dependencies', '--update-no-dev'],
            ]);
    } finally {
        $restoreEnvironment();
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('repairs a module after Composer ownership already succeeded', function (): void {
    $basePath = moduleLifecycleBasePath('repair');
    moduleLifecycleWriteComposer($basePath, ['infocyph/dblayer' => '^5.1']);

    try {
        $dispatcher = moduleLifecycleDispatcher($basePath, ['app' => ['capabilities' => []]]);
        $repair = new FoundationModuleLifecycleIO();

        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:repair', 'database'], $repair))
            ->toBe(ExitCode::SUCCESS)
            ->and($basePath . '/config/database.php')->toBeFile();

        $payload = $repair->lastPayload();
        expect($payload)->toBeArray()
            ->and($payload['phases']['composer'] ?? null)->toBe('skipped')
            ->and($payload['phases']['config'] ?? null)->toBe('completed')
            ->and($payload['phases']['runtime_invalidation'] ?? null)->toBe('completed')
            ->and($payload['phases']['schemas'] ?? null)->toBe('completed');
    } finally {
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('reports and installs the database session schema through module commands', function (): void {
    $basePath = moduleLifecycleBasePath('session-schema');
    mkdir($basePath . '/database', 0775, true);
    $databasePath = $basePath . '/database/app.sqlite';
    $dispatcher = moduleLifecycleDispatcher($basePath, [
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/app.sqlite',
                ],
            ],
        ],
        'session' => [
            'driver' => 'database',
            'stores' => [
                'database' => [
                    'connection' => 'main',
                    'table' => 'sessions',
                ],
            ],
        ],
    ]);

    try {
        $before = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:status', 'session'], $before))
            ->toBe(ExitCode::FAILURE);
        $beforePayload = $before->lastPayload();
        expect($beforePayload)->toBeArray()
            ->and($beforePayload['schemas'][0]['state'] ?? null)->toBe('pending')
            ->and($beforePayload['schemas'][0]['installed'] ?? null)->toBeFalse();

        $install = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:install', 'session'], $install))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleTableExists($databasePath, 'sessions'))->toBeTrue();
        $installPayload = $install->lastPayload();
        expect($installPayload)->toBeArray()
            ->and($installPayload['schemas'][0]['state'] ?? null)->toBe('installed')
            ->and($installPayload['schemas'][0]['installed'] ?? null)->toBeTrue();

        $after = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:status', 'session'], $after))
            ->toBe(ExitCode::SUCCESS);
    } finally {
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('keeps aggregate schema sync inside active capability topology while allowing targeted install', function (): void {
    $basePath = moduleLifecycleBasePath('inactive-schema');
    mkdir($basePath . '/database', 0775, true);
    $databasePath = $basePath . '/database/messaging.sqlite';
    $dispatcher = moduleLifecycleDispatcher($basePath, [
        'app' => ['capabilities' => []],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/messaging.sqlite',
                ],
            ],
        ],
        'messaging' => [
            'durable' => [
                'enabled' => true,
                'connection' => 'main',
                'failure_store' => 'database',
            ],
            'consumer' => ['transport' => 'memory'],
            'workers' => [],
        ],
    ]);

    try {
        $status = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:status', 'messaging'], $status))
            ->toBe(ExitCode::SUCCESS);
        $payload = $status->lastPayload();
        expect($payload['schemas'][0]['state'] ?? null)->toBe('not-applicable');

        $sync = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:sync'], $sync))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_messages'))->toBeFalse();

        $install = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:install', 'messaging'], $install))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_messages'))->toBeTrue();
    } finally {
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
    }
});

it('reports and installs the Omnibus durable messaging schema through module commands', function (): void {
    $basePath = moduleLifecycleBasePath('messaging-schema');
    mkdir($basePath . '/database', 0775, true);
    $databasePath = $basePath . '/database/messaging.sqlite';
    $dispatcher = moduleLifecycleDispatcher($basePath, [
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/messaging.sqlite',
                ],
            ],
        ],
        'messaging' => [
            'durable' => [
                'enabled' => true,
                'connection' => 'main',
                'failure_store' => 'database',
            ],
            'consumer' => ['transport' => 'memory'],
            'workers' => [],
        ],
    ]);

    try {
        $before = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:status', 'messaging'], $before))
            ->toBe(ExitCode::FAILURE);
        $beforePayload = $before->lastPayload();
        expect($beforePayload)->toBeArray()
            ->and($beforePayload['schemas'][0]['state'] ?? null)->toBe('pending')
            ->and($beforePayload['schemas'][0]['installed'] ?? null)->toBeFalse();

        $install = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:install', 'messaging'], $install))
            ->toBe(ExitCode::SUCCESS)
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_messages'))->toBeTrue()
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_failures'))->toBeTrue()
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_workflows'))->toBeTrue()
            ->and(moduleLifecycleTableExists($databasePath, 'omnibus_workflow_items'))->toBeTrue();

        $after = new FoundationModuleLifecycleIO();
        expect(moduleLifecycleRun($dispatcher, ['infbyte', 'module:schema:status', 'messaging'], $after))
            ->toBe(ExitCode::SUCCESS);
    } finally {
        DB::purge();
        moduleLifecycleRemoveDirectory($basePath);
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

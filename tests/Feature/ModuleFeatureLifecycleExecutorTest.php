<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleFeatureLifecycleExecutor;
use Infocyph\Foundation\Module\ModuleFeatureSchemaExecutor;

function moduleB3Dir(): string
{
    $path = sys_get_temp_dir() . '/foundation-b3-' . bin2hex(random_bytes(7));
    mkdir($path . '/bin', 0775, true);
    file_put_contents($path . '/composer.json', '{"name":"test/app","require":{}}');

    return $path;
}

/** @return array<string,mixed> */
function moduleB3Context(): array
{
    return [
        'root_known' => true, 'root_requirements' => [], 'installed' => [],
        'enabled' => [], 'selected_features' => [], 'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'hash', 'mbstring', 'ctype'],
        'published_configs' => [],
    ];
}

function moduleB3Cleanup(string $path): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

/** @return Closure():void */
function moduleB3ComposerStub(string $path): Closure
{
    file_put_contents($path . '/bin/composer', <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
file_put_contents(getenv('FOUNDATION_B3_LOG'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
exit((int) (getenv('FOUNDATION_B3_EXIT') ?: 0));
PHP);
    chmod($path . '/bin/composer', 0755);
    $oldPath = getenv('PATH');
    $oldLog = getenv('FOUNDATION_B3_LOG');
    $oldExit = getenv('FOUNDATION_B3_EXIT');
    putenv('PATH=' . $path . '/bin' . PATH_SEPARATOR . (is_string($oldPath) ? $oldPath : ''));
    putenv('FOUNDATION_B3_LOG=' . $path . '/commands.jsonl');
    putenv('FOUNDATION_B3_EXIT=0');

    return static function () use ($oldPath, $oldLog, $oldExit): void {
        is_string($oldPath) ? putenv('PATH=' . $oldPath) : putenv('PATH');
        is_string($oldLog) ? putenv('FOUNDATION_B3_LOG=' . $oldLog) : putenv('FOUNDATION_B3_LOG');
        is_string($oldExit) ? putenv('FOUNDATION_B3_EXIT=' . $oldExit) : putenv('FOUNDATION_B3_EXIT');
    };
}

it('runs only dry-run planning and never writes Composer, config or schema', function (): void {
    $path = moduleB3Dir();
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        $result = $runner->apply('install', ['execution.messaging'], moduleB3Context(), dryRun: true);
        expect($result['status'])->toBe('planned')
            ->and($result['phases'][0]['name'])->toBe('plan')
            ->and($path . '/config')->not->toBeDirectory()
            ->and($path . '/commands.jsonl')->not->toBeFile();
    } finally {
        moduleB3Cleanup($path);
    }
});

it('stops after a failed Composer phase and reports deterministic repair steps', function (): void {
    $path = moduleB3Dir();
    $restore = moduleB3ComposerStub($path);
    try {
        putenv('FOUNDATION_B3_EXIT=17');
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        $result = $runner->apply('install', ['data.filesystem'], moduleB3Context(), static fn(): array => moduleB3Context());
        expect($result['status'])->toBe('partial')
            ->and($result['phases'][0]['status'])->toBe('failed')
            ->and($result['phases'][0]['name'])->toBe('composer')
            ->and($result['recovery'])->toContain('repair')
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        $restore();
        moduleB3Cleanup($path);
    }
});

it('halts when a successful Composer command has not reconciled live root state', function (): void {
    $path = moduleB3Dir();
    $restore = moduleB3ComposerStub($path);
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        $result = $runner->apply('repair', ['data.filesystem'], moduleB3Context(), static fn(): array => moduleB3Context());
        expect($result['status'])->toBe('partial')
            ->and($result['phases'][1]['name'])->toBe('reconcile')
            ->and($result['recovery'])->toContain('repair')
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        $restore();
        moduleB3Cleanup($path);
    }
});

it('can repeat repair after Composer resolution without re-installing direct packages', function (): void {
    $path = moduleB3Dir();
    $restore = moduleB3ComposerStub($path);
    $context = moduleB3Context();
    $context['root_requirements'] = ['infocyph/pathwise' => '^4.2'];
    $context['installed'] = ['infocyph/pathwise' => '4.2.0'];
    file_put_contents($path . '/composer.json', '{"name":"test/app","require":{"infocyph/pathwise":"^4.2"}}');
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        $result = $runner->apply('repair', ['data.filesystem'], $context, static fn(): array => $context);
        expect($result['status'])->toBe('complete')
            ->and(array_column($result['phases'], 'name'))->toBe(['composer', 'config', 'schema'])
            ->and($path . '/config/filesystem.php')->toBeFile()
            ->and($path . '/commands.jsonl')->not->toBeFile();
        $again = $runner->apply('repair', ['data.filesystem'], $context, static fn(): array => $context);
        expect($again['status'])->toBe('complete');
    } finally {
        $restore();
        moduleB3Cleanup($path);
    }
});

it('blocks unknown ownership on removal and retains all database and configuration state', function (): void {
    $path = moduleB3Dir();
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        $context = moduleB3Context();
        unset($context['selected_features']);
        expect(fn() => $runner->apply('remove', ['security.mfa'], $context))
            ->toThrow(RuntimeException::class, 'Selected feature ownership is unknown');
        $context['selected_features'] = ['security.passkey'];
        $result = $runner->apply('remove', ['security.mfa'], $context);
        expect($result['status'])->toBe('complete')
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        moduleB3Cleanup($path);
    }
});

it('never runs unrelated Core schema or optional messaging schema without durable selection', function (): void {
    $path = moduleB3Dir();
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $schema = new ModuleFeatureSchemaExecutor($app);
        $context = moduleB3Context();
        expect($schema->install(['data.filesystem'], $context))->toBe([])
            ->and($schema->install(['execution.messaging'], $context))->toBe([]);
        $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];
        expect(fn() => $schema->install(['execution.messaging'], $context))
            ->toThrow(RuntimeException::class, 'Install direct required package');
    } finally {
        moduleB3Cleanup($path);
    }
});

it('removes only unshared feature packages and never deletes persisted application data', function (): void {
    $path = moduleB3Dir();
    $restore = moduleB3ComposerStub($path);
    $context = moduleB3Context();
    $context['root_requirements'] = [
        'infocyph/otp' => '^7.0',
        'web-auth/webauthn-lib' => '^5.3.9',
    ];
    $context['installed'] = [
        'infocyph/otp' => '7.0.0',
        'web-auth/webauthn-lib' => '5.3.9',
    ];
    $context['selected_features'] = ['security.mfa', 'security.passkey'];
    file_put_contents($path . '/composer.json', json_encode([
        'name' => 'test/app', 'require' => $context['root_requirements'],
    ], JSON_THROW_ON_ERROR));
    $data = $path . '/existing-messages.sqlite';
    file_put_contents($data, 'keep-data');

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $result = new ModuleFeatureLifecycleExecutor($app)->apply('remove', ['security.passkey'], $context);
        $commands = file($path . '/commands.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        expect($result['status'])->toBe('complete')
            ->and($result['phases'][0]['name'])->toBe('composer')
            ->and($result['phases'][0]['detail'])->toContain('schemas and data retained')
            ->and(json_decode($commands[0], true))->toBe([
                'remove', 'web-auth/webauthn-lib', '--with-all-dependencies', '--no-interaction',
            ])
            ->and(file_get_contents($data))->toBe('keep-data')
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        $restore();
        moduleB3Cleanup($path);
    }
});

it('never permits Core or QA features to enter the optional lifecycle', function (): void {
    $path = moduleB3Dir();
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        expect(fn() => $runner->apply('remove', ['core.auth'], moduleB3Context()))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => $runner->apply('install', ['qa.phpforge'], moduleB3Context(), dryRun: true))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        moduleB3Cleanup($path);
    }
});

it('recovers a partially failed config phase with a repeatable repair', function (): void {
    $path = moduleB3Dir();
    $context = moduleB3Context();
    $context['root_requirements'] = ['infocyph/pathwise' => '^4.2'];
    $context['installed'] = ['infocyph/pathwise' => '4.2.1'];
    file_put_contents($path . '/composer.json', '{"name":"test/app","require":{"infocyph/pathwise":"^4.2"}}');

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $runner = new ModuleFeatureLifecycleExecutor($app);
        file_put_contents($path . '/config', 'not a directory');

        $first = $runner->apply('repair', ['data.filesystem'], $context, static fn(): array => $context);
        expect($first['status'])->toBe('partial')
            ->and(array_column($first['phases'], 'name'))->toBe(['composer', 'reconcile'])
            ->and($first['recovery'])->toContain('repair')
            ->and(file_get_contents($path . '/config'))->toBe('not a directory');

        unlink($path . '/config');
        $second = $runner->apply('repair', ['data.filesystem'], $context, static fn(): array => $context);
        expect($second['status'])->toBe('complete')
            ->and($path . '/config/filesystem.php')->toBeFile()
            ->and($path . '/commands.jsonl')->not->toBeFile();
    } finally {
        moduleB3Cleanup($path);
    }
});

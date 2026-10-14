<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleFeatureComposerExecutor;

function f4ComposerFixture(array $requirements = []): string
{
    $root = sys_get_temp_dir() . '/foundation-feature-composer-' . bin2hex(random_bytes(6));
    mkdir($root . '/bin', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'require' => $requirements,
    ], JSON_THROW_ON_ERROR));

    return $root;
}

/** @param array<string,string> $requirements */
function f4ComposerContext(array $requirements = []): array
{
    return [
        'root_known' => true,
        'root_requirements' => $requirements,
        'installed' => [],
        'enabled' => [],
        'selected_features' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'hash', 'mbstring', 'ctype'],
        'published_configs' => [],
    ];
}

/** @return Closure():void */
function f4ComposerStub(string $root): Closure
{
    file_put_contents($root . '/bin/composer', <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

file_put_contents(
    getenv('FOUNDATION_FEATURE_COMPOSER_LOG'),
    json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . PHP_EOL,
    FILE_APPEND,
);
exit((int) (getenv('FOUNDATION_FEATURE_COMPOSER_EXIT') ?: 0));
PHP);
    chmod($root . '/bin/composer', 0755);
    $path = getenv('PATH');
    $log = getenv('FOUNDATION_FEATURE_COMPOSER_LOG');
    $exit = getenv('FOUNDATION_FEATURE_COMPOSER_EXIT');
    putenv('PATH=' . $root . '/bin' . PATH_SEPARATOR . (is_string($path) ? $path : ''));
    putenv('FOUNDATION_FEATURE_COMPOSER_LOG=' . $root . '/commands.jsonl');
    putenv('FOUNDATION_FEATURE_COMPOSER_EXIT=0');

    return static function () use ($path, $log, $exit): void {
        is_string($path) ? putenv('PATH=' . $path) : putenv('PATH');
        is_string($log) ? putenv('FOUNDATION_FEATURE_COMPOSER_LOG=' . $log) : putenv('FOUNDATION_FEATURE_COMPOSER_LOG');
        is_string($exit) ? putenv('FOUNDATION_FEATURE_COMPOSER_EXIT=' . $exit) : putenv('FOUNDATION_FEATURE_COMPOSER_EXIT');
    };
}

/** @return list<list<string>> */
function f4ComposerCommands(string $root): array
{
    return array_map(
        static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($root . '/commands.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );
}

function f4ComposerRemoveFixture(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

it('executes only selected passkey packages in a Composer dry-run without application mutation', function (): void {
    $root = f4ComposerFixture();
    $restore = f4ComposerStub($root);

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);
        $composerBefore = file_get_contents($root . '/composer.json');

        $result = $executor->applyPackages('install', ['security.passkey'], f4ComposerContext(), true);

        expect($result->successful())->toBeTrue()
            ->and(f4ComposerCommands($root))->toBe([[
                'require', 'infocyph/otp:^7.0', 'web-auth/webauthn-lib:^5.3.9',
                '--with-all-dependencies', '--no-interaction', '--dry-run',
            ]])
            ->and(file_get_contents($root . '/composer.json'))->toBe($composerBefore)
            ->and($root . '/config')->not->toBeDirectory()
            ->and($root . '/.foundation-module-composer.lock')->not->toBeFile();
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

it('respects shared feature ownership when removing packages and skips Composer for no-op', function (): void {
    $requirements = ['infocyph/otp' => '^7.0', 'web-auth/webauthn-lib' => '^5.3.9'];
    $root = f4ComposerFixture($requirements);
    $restore = f4ComposerStub($root);

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);
        $context = f4ComposerContext($requirements);
        $context['installed'] = ['infocyph/otp' => '7.0.0', 'web-auth/webauthn-lib' => '5.3.9'];
        $context['selected_features'] = ['security.passkey'];

        expect($executor->applyPackages('remove', ['security.mfa'], $context)->successful())->toBeTrue()
            ->and(f4ComposerCommands($root))->toBe([]);

        $context['selected_features'] = ['security.mfa', 'security.passkey'];
        expect($executor->applyPackages('remove', ['security.passkey', 'security.mfa'], $context)->successful())
            ->toBeTrue()
            ->and(f4ComposerCommands($root))->toBe([[
                'remove', 'infocyph/otp', 'web-auth/webauthn-lib', '--with-all-dependencies', '--no-interaction',
            ]])
            ->and($root . '/.foundation-module-composer.lock')->toBeFile();
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

it('refuses stale root ownership and invalid operations before executing Composer', function (): void {
    $root = f4ComposerFixture(['infocyph/otp' => '^7.0']);
    $restore = f4ComposerStub($root);

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);

        expect(fn() => $executor->applyPackages('install', ['data.filesystem'], f4ComposerContext()))
            ->toThrow(RuntimeException::class, 'ownership changed')
            ->and(fn() => $executor->applyPackages('enable', ['data.filesystem'], f4ComposerContext()))
            ->toThrow(InvalidArgumentException::class, 'Only Composer feature lifecycle operations')
            ->and(f4ComposerCommands($root))->toBe([]);
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

it('fails closed for missing prerequisite extensions and never launches Composer', function (): void {
    $root = f4ComposerFixture();
    $restore = f4ComposerStub($root);

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);
        $context = f4ComposerContext();
        $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];
        $context['extensions'] = ['fileinfo'];

        expect(fn() => $executor->applyPackages('install', ['execution.messaging'], $context))
            ->toThrow(RuntimeException::class, 'Extension pdo is required for data.database')
            ->and(f4ComposerCommands($root))->toBe([]);
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

it('reports Composer phase failure without modifying feature activation or attempting rollback', function (): void {
    $root = f4ComposerFixture();
    $restore = f4ComposerStub($root);

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);
        putenv('FOUNDATION_FEATURE_COMPOSER_EXIT=19');

        $result = $executor->applyPackages('install', ['data.filesystem'], f4ComposerContext());

        expect($result->successful())->toBeFalse()
            ->and($result->exitCode)->toBe(19)
            ->and(count(f4ComposerCommands($root)))->toBe(1)
            ->and($root . '/config')->not->toBeDirectory();
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

it('blocks symbolic-linked Composer lock files without changing their targets', function (): void {
    $root = f4ComposerFixture();
    $restore = f4ComposerStub($root);
    $target = $root . '/protected.txt';
    file_put_contents($target, 'protected');
    symlink($target, $root . '/.foundation-module-composer.lock');

    try {
        $application = Foundation::cli(['base_path' => $root, '_config_cache' => false]);
        $executor = new ModuleFeatureComposerExecutor($application);
        expect(fn() => $executor->applyPackages('install', ['data.filesystem'], f4ComposerContext()))
            ->toThrow(RuntimeException::class, 'Refusing symbolic-linked module Composer lock.')
            ->and(file_get_contents($target))->toBe('protected')
            ->and(f4ComposerCommands($root))->toBe([]);
    } finally {
        $restore();
        f4ComposerRemoveFixture($root);
    }
});

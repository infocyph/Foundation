<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleStateResolver;

it('separates direct module ownership from transitive package availability', function (): void {
    $basePath = moduleStateBasePath('ownership');

    try {
        moduleStateWriteComposer($basePath, ['infocyph/dblayer' => '^5.1']);
        $directApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $direct = moduleStateFind(
            (new ModuleStateResolver($directApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($direct['installed'])->toBeTrue()
            ->and($direct['installed_by_module'])->toBeTrue()
            ->and($direct['direct'])->toBeTrue()
            ->and($direct['transitive'])->toBeFalse()
            ->and($direct['ownership_unknown'])->toBeFalse()
            ->and($direct['packages']['infocyph/dblayer']['direct'] ?? null)->toBeTrue()
            ->and($direct['packages']['infocyph/dblayer']['transitive'] ?? null)->toBeFalse()
            ->and($direct['packages']['infocyph/dblayer']['compatible'] ?? null)->toBeTrue();

        moduleStateWriteComposer($basePath, []);
        $transitiveApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $transitive = moduleStateFind(
            (new ModuleStateResolver($transitiveApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($transitive['package_available'])->toBeTrue()
            ->and($transitive['installed'])->toBeFalse()
            ->and($transitive['direct'])->toBeFalse()
            ->and($transitive['transitive'])->toBeTrue()
            ->and($transitive['packages']['infocyph/dblayer']['available'] ?? null)->toBeTrue()
            ->and($transitive['packages']['infocyph/dblayer']['direct'] ?? null)->toBeFalse()
            ->and($transitive['packages']['infocyph/dblayer']['transitive'] ?? null)->toBeTrue()
            ->and($transitive['warnings'])->not->toBe([]);
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('reports unknown Composer ownership instead of silently treating packages as transitive', function (): void {
    $basePath = moduleStateBasePath('invalid-composer');
    file_put_contents($basePath . '/composer.json', '{');

    try {
        $application = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $database = moduleStateFind(
            (new ModuleStateResolver($application, new ModuleCatalog()))->all(),
            'database',
        );

        expect($database['installed'])->toBeFalse()
            ->and($database['direct'])->toBeFalse()
            ->and($database['transitive'])->toBeFalse()
            ->and($database['ownership_unknown'])->toBeTrue()
            ->and($database['packages']['infocyph/dblayer']['ownership_unknown'] ?? null)->toBeTrue()
            ->and(implode(' ', $database['blockers']))->toContain('composer.json is invalid');
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('separates effective configuration publication and explicit activation state', function (): void {
    $basePath = moduleStateBasePath('activation');
    moduleStateWriteComposer($basePath, ['infocyph/dblayer' => '^5.1']);

    try {
        $inferredApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        mkdir($basePath . '/config', 0775, true);
        file_put_contents($basePath . '/config/database.php', "<?php\nreturn [];\n");

        $inferred = moduleStateFind(
            (new ModuleStateResolver($inferredApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($inferred['configured'])->toBeTrue()
            ->and($inferred['config_published'])->toBeTrue()
            ->and($inferred['enabled'])->toBeTrue()
            ->and($inferred['activation_explicit'])->toBeFalse()
            ->and($inferred['ready'])->toBeTrue();

        $explicitApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => []],
        ]);
        $explicit = moduleStateFind(
            (new ModuleStateResolver($explicitApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($explicit['configured'])->toBeTrue()
            ->and($explicit['config_published'])->toBeTrue()
            ->and($explicit['enabled'])->toBeFalse()
            ->and($explicit['activation_explicit'])->toBeTrue()
            ->and($explicit['ready'])->toBeFalse();
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('keeps specialist catalog package floors aligned with the tested dependency set', function (): void {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dev = $composer['require-dev'] ?? [];

    foreach ((new ModuleCatalog())->all() as $name => $definition) {
        if (($definition['built_in'] ?? false) === true) {
            continue;
        }

        foreach ($definition['packages'] as $package => $constraint) {
            expect(
                $dev[$package] ?? null,
                sprintf('Catalog floor drift for module %s package %s.', $name, $package),
            )->toBe($constraint);
        }
    }

    expect($composer['require']['infocyph/cachelayer'] ?? null)->toBe('^3.4');
});

/**
 * @param list<array<string,mixed>> $modules
 * @return array<string,mixed>
 */
function moduleStateFind(array $modules, string $name): array
{
    $module = array_find(
        $modules,
        static fn(array $candidate): bool => ($candidate['name'] ?? null) === $name,
    );

    if (!is_array($module)) {
        throw new RuntimeException(sprintf('Module %s was not resolved.', $name));
    }

    return $module;
}

/** @param array<string,string> $requirements */
function moduleStateWriteComposer(string $basePath, array $requirements): void
{
    file_put_contents($basePath . '/composer.json', json_encode([
        'name' => 'example/module-state-app',
        'require' => $requirements,
    ], JSON_THROW_ON_ERROR));
}

function moduleStateBasePath(string $name): string
{
    $basePath = sys_get_temp_dir() . '/foundation-module-state-' . $name . '-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    return $basePath;
}

function moduleStateRemoveDirectory(string $directory): void
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

<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\Internal\ModulePlatformResolver;
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

it('rejects direct root constraints that can fall below the supported module floor', function (): void {
    $basePath = moduleStateBasePath('constraint');
    moduleStateWriteComposer($basePath, ['infocyph/dblayer' => '^5.0']);

    try {
        $application = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $database = moduleStateFind(
            (new ModuleStateResolver($application, new ModuleCatalog()))->all(),
            'database',
        );
        $package = $database['packages']['infocyph/dblayer'] ?? null;

        expect($package)->toBeArray()
            ->and($package['available'] ?? null)->toBeTrue()
            ->and($package['direct'] ?? null)->toBeTrue()
            ->and($package['catalog_compatible'] ?? null)->toBeTrue()
            ->and($package['direct_constraint_compatible'] ?? null)->toBeFalse()
            ->and($package['compatible'] ?? null)->toBeFalse()
            ->and($database['installed'])->toBeFalse()
            ->and($database['ready'])->toBeFalse()
            ->and(implode(' ', $database['blockers']))
            ->toContain('outside the supported module range ^5.1');
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('accepts exact supported pins and fails closed on unsupported consumer constraint syntax', function (): void {
    $version = InstalledVersions::getVersion('infocyph/dblayer');
    expect($version)->toBeString();
    preg_match('/^(\d+\.\d+\.\d+)/', (string) $version, $match);
    $exact = $match[1] ?? throw new RuntimeException('Unable to derive the installed DBLayer release.');

    $cases = [
        'exact' => [$exact, true],
        'tilde' => ['~5.1', null],
        'range' => ['>=5.1 <6.0', null],
        'union' => ['^5.1 || ^6.0', null],
        'alias' => ['dev-main as 5.1.x-dev', null],
    ];

    foreach ($cases as $name => [$constraint, $expected]) {
        $basePath = moduleStateBasePath('constraint-' . $name);
        moduleStateWriteComposer($basePath, ['infocyph/dblayer' => $constraint]);

        try {
            $application = Foundation::cli([
                'base_path' => $basePath,
                '_config_cache' => false,
                'app' => ['capabilities' => ['database']],
            ]);
            $database = moduleStateFind(
                (new ModuleStateResolver($application, new ModuleCatalog()))->all(),
                'database',
            );
            $package = $database['packages']['infocyph/dblayer'] ?? null;

            expect($package)->toBeArray()
                ->and(array_key_exists('direct_constraint_compatible', $package))->toBeTrue()
                ->and($package['direct_constraint_compatible'])->toBe($expected);

            if ($expected === true) {
                expect($database['installed'])->toBeTrue()
                    ->and($database['ready'])->toBeTrue();
            } else {
                expect(array_key_exists('compatible', $package))->toBeTrue()
                    ->and($package['compatible'])->toBeNull()
                    ->and($database['installed'])->toBeFalse()
                    ->and($database['ready'])->toBeFalse()
                    ->and(implode(' ', $database['blockers']))->toContain('Unable to verify direct Composer constraint');

                $readiness = new ReadinessReport($application)->generate();
                expect($readiness['checks']['module:database']['ready'] ?? true)->toBeFalse()
                    ->and($readiness['checks']['module:database']['detail'] ?? '')
                    ->toContain('Unable to verify direct Composer constraint');
            }
        } finally {
            moduleStateRemoveDirectory($basePath);
        }
    }
});

it('reports unknown Composer ownership instead of silently treating packages as transitive', function (): void {
    $basePath = moduleStateBasePath('unknown-composer');

    try {
        $missingApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $missing = moduleStateFind(
            (new ModuleStateResolver($missingApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($missing['installed'])->toBeFalse()
            ->and($missing['direct'])->toBeFalse()
            ->and($missing['transitive'])->toBeFalse()
            ->and($missing['ownership_unknown'])->toBeTrue()
            ->and(implode(' ', $missing['blockers']))->toContain('composer.json is missing or unreadable');

        file_put_contents($basePath . '/composer.json', '{');
        $invalidApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $invalid = moduleStateFind(
            (new ModuleStateResolver($invalidApp, new ModuleCatalog()))->all(),
            'database',
        );

        expect($invalid['installed'])->toBeFalse()
            ->and($invalid['direct'])->toBeFalse()
            ->and($invalid['transitive'])->toBeFalse()
            ->and($invalid['ownership_unknown'])->toBeTrue()
            ->and($invalid['packages']['infocyph/dblayer']['ownership_unknown'] ?? null)->toBeTrue()
            ->and(implode(' ', $invalid['blockers']))->toContain('composer.json is invalid');
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

it('keeps core auth ready while tracking OTP and passkey feature state independently', function (): void {
    $basePath = moduleStateBasePath('auth-features');

    try {
        moduleStateWriteComposer($basePath, ['infocyph/otp' => '^6.1']);
        $otpApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'auth' => [
                'drivers' => [
                    'mfa' => 'otp',
                    'passkey' => 'disabled',
                ],
            ],
        ]);
        $otp = moduleStateFind(
            (new ModuleStateResolver($otpApp, new ModuleCatalog()))->all(),
            'auth',
        );

        expect($otp['core_backed'])->toBeTrue()
            ->and($otp['installed'])->toBeTrue()
            ->and($otp['packages'])->toBe([])
            ->and($otp['features']['otp']['selected'] ?? null)->toBeTrue()
            ->and($otp['features']['otp']['installed'] ?? null)->toBeTrue()
            ->and($otp['features']['otp']['ready'] ?? null)->toBeTrue()
            ->and($otp['features']['passkey']['selected'] ?? null)->toBeFalse()
            ->and($otp['features']['passkey']['installed'] ?? null)->toBeFalse();

        moduleStateWriteComposer($basePath, [
            'infocyph/otp' => '^6.1',
            'web-auth/webauthn-lib' => '^5.3.9',
        ]);
        $passkeyApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'auth' => [
                'drivers' => [
                    'mfa' => 'simple',
                    'passkey' => 'webauthn',
                ],
            ],
        ]);
        $passkey = moduleStateFind(
            (new ModuleStateResolver($passkeyApp, new ModuleCatalog()))->all(),
            'auth',
        );

        expect($passkey['features']['otp']['selected'] ?? null)->toBeFalse()
            ->and($passkey['features']['otp']['installed'] ?? null)->toBeTrue()
            ->and($passkey['features']['passkey']['selected'] ?? null)->toBeTrue()
            ->and($passkey['features']['passkey']['installed'] ?? null)->toBeTrue()
            ->and($passkey['features']['passkey']['ready'] ?? null)->toBeTrue();
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('reports optional integrations separately from managed module ownership', function (): void {
    $basePath = moduleStateBasePath('optional-integrations');
    moduleStateWriteComposer($basePath, ['infocyph/talkingbytes' => '^2.2']);

    try {
        $application = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $communication = moduleStateFind(
            (new ModuleStateResolver($application, new ModuleCatalog()))->all(),
            'communication',
        );

        expect($communication['packages'])->toHaveKey('infocyph/talkingbytes')
            ->and($communication['packages'])->not->toHaveKey('grpc/grpc')
            ->and($communication['optional_integrations'])->toHaveKey('grpc/grpc')
            ->and($communication['optional_integrations']['grpc/grpc']['available'] ?? null)->toBeBool()
            ->and($communication['optional_integrations']['grpc/grpc']['features'] ?? null)->toBe(['grpc'])
            ->and($communication['features'])->toHaveKey('grpc')
            ->and($communication['platform']['extensions'] ?? null)->toBe(['curl', 'fileinfo', 'openssl']);
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('evaluates conditional specialist module dependencies against explicit topology', function (): void {
    $basePath = moduleStateBasePath('dependencies');
    moduleStateWriteComposer($basePath, [
        'infocyph/omnibus' => '^2.6',
        'infocyph/dblayer' => '^5.1',
    ]);

    try {
        $blockedApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => []],
            'messaging' => ['durable' => ['enabled' => true]],
        ]);
        $blocked = moduleStateFind(
            (new ModuleStateResolver($blockedApp, new ModuleCatalog()))->all(),
            'messaging',
        );

        expect($blocked['dependencies_satisfied'])->toBeFalse()
            ->and($blocked['dependencies']['active'][0]['type'] ?? null)->toBe('module')
            ->and($blocked['dependencies']['active'][0]['target'] ?? null)->toBe('database')
            ->and($blocked['dependencies']['active'][0]['satisfied'] ?? true)->toBeFalse()
            ->and(implode(' ', $blocked['blockers']))->toContain('Durable database-backed messaging requires DBLayer.');

        $readyApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => ['database']],
            'messaging' => ['durable' => ['enabled' => true]],
        ]);
        $ready = moduleStateFind(
            (new ModuleStateResolver($readyApp, new ModuleCatalog()))->all(),
            'messaging',
        );

        expect($ready['dependencies_satisfied'])->toBeTrue()
            ->and($ready['dependencies']['active'][0]['satisfied'] ?? false)->toBeTrue();
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('evaluates selected auth feature dependencies against core capabilities', function (): void {
    $basePath = moduleStateBasePath('core-dependencies');
    moduleStateWriteComposer($basePath, ['infocyph/otp' => '^6.1']);

    try {
        $blockedApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => ['auth']],
            'auth' => ['drivers' => ['mfa' => 'otp', 'passkey' => 'disabled']],
        ]);
        $blocked = moduleStateFind(
            (new ModuleStateResolver($blockedApp, new ModuleCatalog()))->all(),
            'auth',
        );

        expect($blocked['dependencies_satisfied'])->toBeFalse()
            ->and($blocked['features']['otp']['dependencies_satisfied'] ?? true)->toBeFalse()
            ->and($blocked['features']['otp']['ready'] ?? true)->toBeFalse()
            ->and($blocked['dependencies']['active'][0]['type'] ?? null)->toBe('capability')
            ->and($blocked['dependencies']['active'][0]['target'] ?? null)->toBe('cache')
            ->and($blocked['dependencies']['active'][0]['satisfied'] ?? true)->toBeFalse();

        $readyApp = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => ['auth', 'cache']],
            'auth' => ['drivers' => ['mfa' => 'otp', 'passkey' => 'disabled']],
        ]);
        $ready = moduleStateFind(
            (new ModuleStateResolver($readyApp, new ModuleCatalog()))->all(),
            'auth',
        );

        expect($ready['dependencies_satisfied'])->toBeTrue()
            ->and($ready['features']['otp']['dependencies_satisfied'] ?? false)->toBeTrue()
            ->and($ready['features']['otp']['ready'] ?? false)->toBeTrue();
    } finally {
        moduleStateRemoveDirectory($basePath);
    }
});

it('reports runtime platform readiness and hard-blocks missing required extensions', function (): void {
    $basePath = moduleStateBasePath('platform');
    moduleStateWriteComposer($basePath, ['infocyph/dblayer' => '^5.1']);

    try {
        $application = Foundation::cli([
            'base_path' => $basePath,
            '_config_cache' => false,
            'app' => ['capabilities' => ['database']],
        ]);
        $database = moduleStateFind(
            (new ModuleStateResolver($application, new ModuleCatalog()))->all(),
            'database',
        );

        expect($database['platform_ready'])->toBeTrue()
            ->and($database['platform_status']['required_extensions']['pdo'] ?? false)->toBeTrue()
            ->and($database['platform_status']['required_extensions']['pdo_sqlite'] ?? false)->toBeTrue();

        $definition = (new ModuleCatalog())->resolve('database');
        $definition['platform']['extensions'][] = 'foundation_extension_that_does_not_exist';
        $resolution = (new ModulePlatformResolver($application))->resolve(
            'database',
            $definition,
            [],
            true,
        );

        expect($resolution['ready'])->toBeFalse()
            ->and(implode(' ', $resolution['blockers']))
            ->toContain('ext-foundation_extension_that_does_not_exist');
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

    $catalog = new ModuleCatalog();

    foreach ($catalog->all() as $name => $definition) {
        if (($definition['built_in'] ?? false) === true) {
            continue;
        }

        foreach ($catalog->managedPackages($definition) as $package => $constraint) {
            expect($dev[$package] ?? null)->toBe($constraint);
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

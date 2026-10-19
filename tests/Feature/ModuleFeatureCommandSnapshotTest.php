<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleFeatureCommandSnapshot;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;

function foundationB6Project(): string
{
    $path = sys_get_temp_dir() . '/foundation-b6-catalog-' . bin2hex(random_bytes(5));
    mkdir($path . '/vendor/composer', 0775, true);
    mkdir($path . '/config', 0775, true);
    file_put_contents($path . '/composer.json', json_encode([
        'name' => 'test/application',
        'require' => ['infocyph/dblayer' => '^6.1.2'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($path . '/vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'infocyph/dblayer', 'version' => '6.1.2.0', 'pretty_version' => '6.1.2'],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($path . '/config/database.php', "<?php return [];\n");

    return $path;
}

function foundationB6Clean(string $path): void
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

it('reads a fresh root Composer and selected-feature project snapshot for the v2 planner', function (): void {
    $path = foundationB6Project();
    try {
        $app = Foundation::cli([
            'base_path' => $path,
            '_config_cache' => false,
            'modules' => ['features' => ['data.filesystem' => false, 'data.database' => true]],
        ]);
        $state = new ModuleFeatureCommandSnapshot($app)->capture();
        $plan = new ModuleFeaturePlanner()->plan('install', ['data.database'], $state);

        expect($state['root_known'])->toBeTrue()
            ->and($state['root_requirements'])->toBe(['infocyph/dblayer' => '^6.1.2'])
            ->and($state['installed']['infocyph/dblayer'] ?? null)->toBe('6.1.2.0')
            ->and($state['enabled'])->toBe(['data.database'])
            ->and($state['selected_features'])->toBe(['data.database', 'data.filesystem'])
            ->and($state['published_configs'])->toBe(['database.php'])
            ->and($plan['effects']['require'])->toBe([])
            ->and($plan['applicable'])->toBeTrue();
    } finally {
        foundationB6Clean($path);
    }
});

it('fails closed on unsafe or missing root ownership rather than guessing direct Composer state', function (): void {
    $path = foundationB6Project();
    try {
        unlink($path . '/composer.json');
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $snapshot = new ModuleFeatureCommandSnapshot($app)->capture();

        expect($snapshot['root_known'])->toBeFalse()
            ->and($snapshot['root_requirements'])->toBe([])
            ->and(new ModuleFeaturePlanner()->plan('install', ['data.database'], $snapshot)['applicable'])->toBeFalse();
    } finally {
        foundationB6Clean($path);
    }
});


it('preserves explicitly configured disabled feature ownership for shared package removal', function (): void {
    $path = foundationB6Project();
    try {
        $app = Foundation::cli([
            'base_path' => $path,
            '_config_cache' => false,
            'modules' => ['features' => [
                'security.mfa' => false,
                'security.passkey' => false,
            ]],
        ]);
        $snapshot = new ModuleFeatureCommandSnapshot($app)->capture();
        expect($snapshot['enabled'])->toBe([])
            ->and($snapshot['selected_features'])->toBe(['security.mfa', 'security.passkey']);
    } finally {
        foundationB6Clean($path);
    }
});

it('accepts real short installed Composer versions without false incompatibility', function (): void {
    $path = foundationB6Project();
    try {
        $manifest = $path . '/vendor/composer/installed.json';
        file_put_contents($manifest, json_encode(['packages' => [[
            'name' => 'infocyph/dblayer', 'version_normalized' => '6.2.0.0',
            'version' => '6.2', 'pretty_version' => '6.2',
        ]]], JSON_THROW_ON_ERROR));
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $snapshot = new ModuleFeatureCommandSnapshot($app)->capture();
        expect($snapshot['installed']['infocyph/dblayer'])->toBe('6.2.0.0')
            ->and((new ModuleFeaturePlanner()->plan('install', ['data.database'], $snapshot))['applicable'])->toBeTrue();

        file_put_contents($manifest, json_encode(['packages' => [[
            'name' => 'infocyph/dblayer', 'pretty_version' => '6.2',
        ]]], JSON_THROW_ON_ERROR));
        $fallback = new ModuleFeatureCommandSnapshot($app)->capture();
        expect($fallback['installed']['infocyph/dblayer'])->toBe('6.2')
            ->and((new ModuleFeaturePlanner()->plan('install', ['data.database'], $fallback))['applicable'])->toBeTrue();
    } finally {
        foundationB6Clean($path);
    }
});

it('rejects Composer metadata changed after the v2 plan before locked activation', function (): void {
    $path = foundationB6Project();
    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $snapshot = new ModuleFeatureCommandSnapshot($app)->capture();
        $activation = new \Infocyph\Foundation\Module\ModuleActivationManager($app);
        $manifest = $path . '/vendor/composer/installed.json';
        $contents = file_get_contents($manifest);
        expect($contents)->toBeString();
        file_put_contents($manifest, str_replace('6.1.2.0', '6.2.0.0', (string) $contents));

        expect(fn() => $activation->applyFeatures('enable', ['data.database'], $snapshot))
            ->toThrow(RuntimeException::class, 'Composer metadata changed')
            ->and(is_file($path . '/config/modules.php'))->toBeFalse();
    } finally {
        foundationB6Clean($path);
    }
});

it('reloads changed application policy and rejects a plan captured before that change', function (): void {
    $path = foundationB6Project();
    try {
        file_put_contents($path . '/config/auth.php', "<?php return ['drivers' => ['passwords' => 'native']];\n");
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $snapshots = new ModuleFeatureCommandSnapshot($app);
        $old = $snapshots->capture();
        file_put_contents($path . '/config/auth.php', "<?php return ['drivers' => ['passwords' => 'security']];\n");

        expect(fn() => $snapshots->assertCurrent($old))->toThrow(RuntimeException::class, 'Feature configuration changed')
            ->and($snapshots->capture()['config']['auth']['drivers']['passwords'])->toBe('security');
    } finally {
        foundationB6Clean($path);
    }
});

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
        'require' => ['infocyph/dblayer' => '^6.1'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($path . '/vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'infocyph/dblayer', 'version' => '6.1.0.0', 'pretty_version' => '6.1.0'],
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
            ->and($state['root_requirements'])->toBe(['infocyph/dblayer' => '^6.1'])
            ->and($state['installed']['infocyph/dblayer'] ?? null)->toBe('6.1.0')
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

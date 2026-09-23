<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandCatalog;
use Infocyph\Foundation\Foundation;

it('keeps core application installation independent of optional Epicrypt', function (): void {
    expect(class_exists(Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator::class))->toBeTrue();

    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . '/foundation-core-install-' . bin2hex(random_bytes(6));
    mkdir($project, 0775, true);
    file_put_contents(
        $project . '/.env.example',
        "APP_ENV=local\nAUTH_TOKEN_SECRET=\n",
    );

    try {
        $app = Foundation::cli([
            'base_path' => $project,
            '_config_cache' => false,
            'app' => ['capabilities' => []],
        ])->boot();

        $manager = new Infocyph\Foundation\Security\EnvironmentSecretManager(
            $app,
            new Infocyph\Foundation\Config\ConfigCacheManager($app),
        );
        $path = $manager->install();
        $contents = (string) file_get_contents($path);

        expect($path)->toBe($project . '/.env')
            ->and($contents)->toMatch('/^AUTH_TOKEN_SECRET=[a-f0-9]{64}$/m')
            ->and(fileperms($path) & 0777)->toBe(0600);
    } finally {
        foundationCoreInstallRemove($project);
    }
});

it('keeps module inspection and schema commands observational without forcing database capability', function (): void {
    $commands = new CommandCatalog()->all();

    foreach (['module:show', 'module:schema:install', 'module:schema:status', 'module:schema:sync'] as $name) {
        expect($commands[$name]->capabilities())->not->toContain('db');
    }
});

function foundationCoreInstallRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

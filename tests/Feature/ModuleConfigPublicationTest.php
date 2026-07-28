<?php

declare(strict_types=1);

use Infocyph\Console\Process\ProcessRunner;
use Infocyph\Foundation\Console\Support\ModuleCatalog;
use Infocyph\Foundation\Console\Support\ModuleManager;
use Infocyph\Foundation\Foundation;

it('publishes module config without replacing application-owned files', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-config-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);
    file_put_contents($basePath . '/config/notifications.php', "<?php\n\nreturn ['owned' => true];\n");

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $result = $manager->publishConfig('communication');

        expect($result['published'])->toBe([$basePath . '/config/communication.php'])
            ->and($result['existing'])->toBe([$basePath . '/config/notifications.php'])
            ->and($basePath . '/config/communication.php')->toBeFile()
            ->and(file_get_contents($basePath . '/config/notifications.php'))
            ->toBe("<?php\n\nreturn ['owned' => true];\n");
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('invalidates compiled configuration after publishing a module template', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-cache-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/bootstrap/cache/config', 0775, true);
    file_put_contents($basePath . '/bootstrap/cache/config/__manifest.php', '<?php return [];');
    file_put_contents($basePath . '/bootstrap/cache/config/app.php', '<?php return [];');

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $result = $manager->publishConfig('db');

        expect($result['published'])->toBe([$basePath . '/config/database.php'])
            ->and($basePath . '/config/database.php')->toBeFile()
            ->and($basePath . '/bootstrap/cache/config/__manifest.php')->not->toBeFile()
            ->and($basePath . '/bootstrap/cache/config/app.php')->not->toBeFile();
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('publishes the complete security configuration only with the crypto module', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-crypto-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);

    try {
        $application = Foundation::console([
            'base_path' => $basePath,
            '_config_cache' => false,
        ]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $result = $manager->publishConfig('crypto');
        $configuration = require $basePath . '/config/security.php';

        expect($result['published'])->toBe([$basePath . '/config/security.php'])
            ->and($configuration)->toHaveKeys([
                'password.algorithm',
                'jwt.leeway_seconds',
                'integrity.algorithm',
                'key_rings',
            ])
            ->and($configuration)->not->toHaveKeys([
                'csrf',
                'signed_urls',
                'tokens',
            ]);
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

function moduleConfigRemoveDirectory(string $directory): void
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

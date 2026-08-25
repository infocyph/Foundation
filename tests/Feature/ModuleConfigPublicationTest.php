<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;
use Infocyph\Foundation\Process\ProcessRunner;

it('publishes module config without replacing application-owned files', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-config-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);
    file_put_contents($basePath . '/config/notifications.php', "<?php\n\nreturn ['owned' => true];\n");

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
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
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
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

it('publishes only Foundation authentication policy with the crypto module', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-crypto-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $result = $manager->publishConfig('crypto');
        $configuration = require $basePath . '/config/security.php';

        expect($result['published'])->toBe([$basePath . '/config/security.php'])
            ->and($configuration)->toHaveKeys([
                'password.algorithm',
                'password.cost',
                'jwt.algorithm',
                'jwt.leeway_seconds',
            ])
            ->and($configuration)->not->toHaveKeys([
                'csrf',
                'signed_urls',
                'tokens',
                'integrity',
                'key_rings',
            ]);
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('publishes the built-in browser session configuration without Composer', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-session-' . bin2hex(random_bytes(5));

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $install = $manager->install('session');
        $result = $manager->publishConfig('session');

        expect($install->successful())->toBeTrue()
            ->and($result['published'])->toBe([$basePath . '/config/session.php'])
            ->and(require $basePath . '/config/session.php')->toHaveKeys([
                'driver', 'lifetime', 'cookie', 'stores', 'lock', 'csrf',
            ]);
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('force-publishes atomically and removes Foundation-owned backups after commit', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-force-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);
    file_put_contents($basePath . '/config/database.php', "<?php\nreturn ['owned' => true];\n");

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());
        $result = $manager->publishConfig('db', true);

        expect($result['published'])->toBe([$basePath . '/config/database.php'])
            ->and(glob($basePath . '/config/*.foundation-*.bak') ?: [])->toBe([])
            ->and(glob($basePath . '/config/.foundation-config-*') ?: [])->toBe([])
            ->and(require $basePath . '/config/database.php')->toHaveKey('connections');
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('keeps development dependencies out of module composer operations', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-composer-' . bin2hex(random_bytes(5));
    $binPath = $basePath . '/bin';
    $commandLog = $basePath . '/commands.jsonl';
    $composerPath = $binPath . '/composer';
    $originalPath = getenv('PATH');
    $originalLog = getenv('FOUNDATION_MODULE_COMMAND_LOG');

    mkdir($binPath, 0775, true);
    file_put_contents($basePath . '/composer.json', '{"name":"example/application"}');
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

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog(), new ProcessRunner());

        expect($manager->install('db', true)->successful())->toBeTrue()
            ->and($manager->remove('db', true)->successful())->toBeTrue()
            ->and($manager->install('messaging', true)->successful())->toBeTrue();

        $commands = array_map(
            static fn(string $command): array => json_decode($command, true, flags: JSON_THROW_ON_ERROR),
            file($commandLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );

        expect($commands)->toBe([
            ['require', 'infocyph/dblayer:^5.0', '--with-all-dependencies', '--update-no-dev', '--dry-run'],
            ['require', 'infocyph/omnibus:^2.5', '--with-all-dependencies', '--update-no-dev', '--dry-run'],
        ]);
    } finally {
        is_string($originalPath) ? putenv('PATH=' . $originalPath) : putenv('PATH');
        is_string($originalLog)
            ? putenv('FOUNDATION_MODULE_COMMAND_LOG=' . $originalLog)
            : putenv('FOUNDATION_MODULE_COMMAND_LOG');
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

<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;

it('publishes module config without replacing application-owned files', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-config-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config', 0775, true);
    file_put_contents($basePath . '/config/notifications.php', "<?php\n\nreturn ['owned' => true];\n");

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog());
        $result = $manager->publishConfig('communication');

        expect($result['published'])->toBe([$basePath . '/config/communication.php'])
            ->and($result['existing'])->toBe([])
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
        $manager = new ModuleManager($application, new ModuleCatalog());
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
        $manager = new ModuleManager($application, new ModuleCatalog());
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
        $manager = new ModuleManager($application, new ModuleCatalog());
        $result = $manager->publishConfig('session');

        expect($result['published'])->toBe([$basePath . '/config/session.php'])
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
        $manager = new ModuleManager($application, new ModuleCatalog());
        $result = $manager->publishConfig('db', true);

        expect($result['published'])->toBe([$basePath . '/config/database.php'])
            ->and(glob($basePath . '/config/*.foundation-*.bak') ?: [])->toBe([])
            ->and(glob($basePath . '/config/.foundation-config-*') ?: [])->toBe([])
            ->and(require $basePath . '/config/database.php')->toHaveKey('connections');
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});

it('keeps native notifications config outside communication publication failures', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-module-rollback-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/config/communication.php', 0775, true);
    file_put_contents($basePath . '/config/notifications.php', "<?php\nreturn ['native' => true];\n");

    try {
        $application = Foundation::cli(['base_path' => $basePath, '_config_cache' => false]);
        $manager = new ModuleManager($application, new ModuleCatalog());
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            expect(fn() => $manager->publishConfig('communication', true))
                ->toThrow(RuntimeException::class, 'Unable to publish config template "communication.php".');
        } finally {
            restore_error_handler();
        }

        expect($basePath . '/config/communication.php')->toBeDirectory()
            ->and(file_get_contents($basePath . '/config/notifications.php'))
            ->toBe("<?php\nreturn ['native' => true];\n")
            ->and(glob($basePath . '/config/.foundation-config-*') ?: [])->toBe([])
            ->and(glob($basePath . '/config/*.foundation-*.bak') ?: [])->toBe([]);
    } finally {
        moduleConfigRemoveDirectory($basePath);
    }
});


<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

it('loads env files before project config files are evaluated', function (): void {
    $project = foundationTestProject([
        '.env' => <<<ENV
APP_NAME="Env Driven App"
APP_ENV=local
APP_DEBUG=false
ENV,
        'config/app.php' => <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'missing',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'debug' => ($_ENV['APP_DEBUG'] ?? 'true') === 'true',
];
PHP,
    ]);

    $snapshot = foundationSnapshotEnvironment(['APP_NAME', 'APP_ENV', 'APP_DEBUG']);

    try {
        $app = Foundation::web([
            'base_path' => $project,
        ]);

        expect($app->config()->get('app.name'))->toBe('Env Driven App');
        expect($app->config()->get('app.env'))->toBe('local');
        expect($app->config()->get('app.debug'))->toBeFalse();
    } finally {
        foundationRestoreEnvironment($snapshot);
        foundationRemoveDirectory($project);
    }
});

it('lets .env.local override earlier loaded env values', function (): void {
    $project = foundationTestProject([
        '.env' => "APP_NAME=\"Base Name\"\n",
        '.env.local' => "APP_NAME=\"Local Name\"\n",
        'config/app.php' => <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'missing',
];
PHP,
    ]);

    $snapshot = foundationSnapshotEnvironment(['APP_NAME']);

    try {
        $app = Foundation::web([
            'base_path' => $project,
        ]);

        expect($app->config()->get('app.name'))->toBe('Local Name');
    } finally {
        foundationRestoreEnvironment($snapshot);
        foundationRemoveDirectory($project);
    }
});

it('does not override existing process environment values', function (): void {
    $project = foundationTestProject([
        '.env' => "APP_NAME=\"File Name\"\n",
        'config/app.php' => <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'missing',
];
PHP,
    ]);

    $snapshot = foundationSnapshotEnvironment(['APP_NAME']);
    foundationSetEnvironmentValue('APP_NAME', 'Process Name');

    try {
        $app = Foundation::web([
            'base_path' => $project,
        ]);

        expect($app->config()->get('app.name'))->toBe('Process Name');
    } finally {
        foundationRestoreEnvironment($snapshot);
        foundationRemoveDirectory($project);
    }
});

it('can disable env loading for a project root', function (): void {
    $project = foundationTestProject([
        '.env' => "APP_NAME=File Name\n",
        'config/app.php' => <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'missing',
];
PHP,
    ]);

    $snapshot = foundationSnapshotEnvironment(['APP_NAME']);

    try {
        $app = Foundation::web([
            'base_path' => $project,
            'app' => [
                'load_env' => false,
            ],
        ]);

        expect($app->config()->get('app.name'))->toBe('missing');
    } finally {
        foundationRestoreEnvironment($snapshot);
        foundationRemoveDirectory($project);
    }
});

/**
 * @param array<string, string> $files
 */
function foundationTestProject(array $files): string
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'foundation-env-'
        . bin2hex(random_bytes(5));

    foreach ($files as $path => $contents) {
        $target = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($target, $contents);
    }

    return $root;
}

/**
 * @param list<string> $keys
 * @return array<string, array{exists: bool, value: mixed, server_exists: bool, server_value: mixed, getenv_exists: bool, getenv_value: string}>
 */
function foundationSnapshotEnvironment(array $keys): array
{
    $snapshot = [];

    foreach ($keys as $key) {
        $getenv = getenv($key);

        $snapshot[$key] = [
            'exists' => array_key_exists($key, $_ENV),
            'value' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'server_value' => $_SERVER[$key] ?? null,
            'getenv_exists' => $getenv !== false,
            'getenv_value' => $getenv === false ? '' : $getenv,
        ];
    }

    return $snapshot;
}

/**
 * @param array<string, array{exists: bool, value: mixed, server_exists: bool, server_value: mixed, getenv_exists: bool, getenv_value: string}> $snapshot
 */
function foundationRestoreEnvironment(array $snapshot): void
{
    foreach ($snapshot as $key => $state) {
        if ($state['exists']) {
            $_ENV[$key] = $state['value'];
        } else {
            unset($_ENV[$key]);
        }

        if ($state['server_exists']) {
            $_SERVER[$key] = $state['server_value'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($state['getenv_exists']) {
            putenv($key . '=' . $state['getenv_value']);
        } else {
            putenv($key);
        }
    }
}

function foundationSetEnvironmentValue(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

function foundationRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            foundationRemoveDirectory($path);

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}

<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\EnvironmentLoader;
use UnexpectedValueException;

it('delegates layered config precedence and structural shadowing to ArrayKit 5.2', function (): void {
    $project = arrayKit52ConfigProject([
        'config/app.php' => <<<'PHP'
<?php

return [
    'servers' => ['source-a', 'source-b'],
    'cache' => ['driver' => 'redis'],
    'source_only' => 'source',
    'shared' => ['source' => true],
];
PHP,
    ]);

    try {
        $config = ConfigRepository::fromLazyFiles(
            directory: $project . '/config',
            cacheDirectory: null,
            fallback: [
                'app' => [
                    'fallback_only' => 'fallback',
                    'shared' => ['fallback' => true],
                ],
            ],
            overrides: [
                'app' => [
                    'servers' => ['override'],
                    'cache' => false,
                    'shared' => ['override' => true],
                ],
            ],
            namespaces: ['app'],
        );

        expect($config->get('app.servers'))->toBe(['override'])
            ->and($config->get('app.servers.0'))->toBe('override')
            ->and($config->get('app.servers.1', 'missing'))->toBe('missing')
            ->and($config->get('app.cache'))->toBeFalse()
            ->and($config->get('app.cache.driver', 'missing'))->toBe('missing')
            ->and($config->get('app.fallback_only'))->toBe('fallback')
            ->and($config->get('app.source_only'))->toBe('source')
            ->and($config->get('app.shared'))->toBe([
                'fallback' => true,
                'source' => true,
                'override' => true,
            ]);
    } finally {
        arrayKit52ConfigRemove($project);
    }
});

it('preserves configured environment file order and ArrayKit interpolation semantics', function (): void {
    $keys = [
        'FOUNDATION_AK52_BASE',
        'FOUNDATION_AK52_NAME',
        'FOUNDATION_AK52_LITERAL',
        'FOUNDATION_AK52_ORDER',
    ];
    $snapshot = arrayKit52EnvironmentSnapshot($keys);
    arrayKit52EnvironmentUnset($keys);
    $project = arrayKit52ConfigProject([
        '.env.first' => <<<'ENV'
FOUNDATION_AK52_BASE=base
FOUNDATION_AK52_NAME=${FOUNDATION_AK52_BASE}-name
FOUNDATION_AK52_LITERAL=\$FOUNDATION_AK52_BASE
FOUNDATION_AK52_ORDER=first
ENV,
        '.env.second' => "FOUNDATION_AK52_ORDER=second\n",
    ]);

    try {
        new EnvironmentLoader()->load($project, [
            'app' => ['env_files' => ['.env.first', '.env.second']],
        ]);

        expect($_ENV['FOUNDATION_AK52_NAME'] ?? null)->toBe('base-name')
            ->and($_ENV['FOUNDATION_AK52_LITERAL'] ?? null)->toBe('$FOUNDATION_AK52_BASE')
            ->and($_ENV['FOUNDATION_AK52_ORDER'] ?? null)->toBe('second');
    } finally {
        arrayKit52EnvironmentRestore($snapshot);
        arrayKit52ConfigRemove($project);
    }
});

it('protects process-only host environment values from env-file hydration', function (): void {
    $key = 'FOUNDATION_AK52_PROCESS_ONLY';
    $snapshot = arrayKit52EnvironmentSnapshot([$key]);
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key . '=process');
    $project = arrayKit52ConfigProject([
        '.env' => $key . "=file\n",
    ]);

    try {
        new EnvironmentLoader()->load($project);

        expect(getenv($key))->toBe('process')
            ->and(array_key_exists($key, $_ENV))->toBeFalse()
            ->and(array_key_exists($key, $_SERVER))->toBeFalse();
    } finally {
        arrayKit52EnvironmentRestore($snapshot);
        arrayKit52ConfigRemove($project);
    }
});

it('propagates ArrayKit BOM and NUL rejection through the Foundation environment load path', function (): void {
    $bom = arrayKit52ConfigProject([
        '.env' => "\xEF\xBB\xBFFOUNDATION_AK52_SAFE=value\n",
    ]);
    $nul = arrayKit52ConfigProject([
        '.env' => "FOUNDATION_AK52_SAFE=value\0bad\n",
    ]);

    try {
        expect(fn() => new EnvironmentLoader()->load($bom))
            ->toThrow(UnexpectedValueException::class);
        expect(fn() => new EnvironmentLoader()->load($nul))
            ->toThrow(UnexpectedValueException::class);
    } finally {
        arrayKit52ConfigRemove($bom);
        arrayKit52ConfigRemove($nul);
    }
});

it('skips missing configured environment files while loading later sources', function (): void {
    $key = 'FOUNDATION_AK52_OPTIONAL';
    $snapshot = arrayKit52EnvironmentSnapshot([$key]);
    arrayKit52EnvironmentUnset([$key]);
    $project = arrayKit52ConfigProject([
        '.env.present' => $key . "=loaded\n",
    ]);

    try {
        new EnvironmentLoader()->load($project, [
            'app' => ['env_files' => ['.env.missing', '.env.present']],
        ]);

        expect($_ENV[$key] ?? null)->toBe('loaded');
    } finally {
        arrayKit52EnvironmentRestore($snapshot);
        arrayKit52ConfigRemove($project);
    }
});

/** @param array<string, string> $files */
function arrayKit52ConfigProject(array $files): string
{
    $root = sys_get_temp_dir() . '/foundation-arraykit52-' . bin2hex(random_bytes(6));

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
 * @return array<string, array{env_exists:bool,env:mixed,server_exists:bool,server:mixed,process:string|false}>
 */
function arrayKit52EnvironmentSnapshot(array $keys): array
{
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = [
            'env_exists' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
            'process' => getenv($key),
        ];
    }

    return $snapshot;
}

/** @param list<string> $keys */
function arrayKit52EnvironmentUnset(array $keys): void
{
    foreach ($keys as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}

/** @param array<string, array{env_exists:bool,env:mixed,server_exists:bool,server:mixed,process:string|false}> $snapshot */
function arrayKit52EnvironmentRestore(array $snapshot): void
{
    foreach ($snapshot as $key => $state) {
        if ($state['env_exists']) {
            $_ENV[$key] = $state['env'];
        } else {
            unset($_ENV[$key]);
        }

        if ($state['server_exists']) {
            $_SERVER[$key] = $state['server'];
        } else {
            unset($_SERVER[$key]);
        }

        putenv($state['process'] === false ? $key : $key . '=' . $state['process']);
    }
}

function arrayKit52ConfigRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            arrayKit52ConfigRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

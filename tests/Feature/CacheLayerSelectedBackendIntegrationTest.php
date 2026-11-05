<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;

it('qualifies local and SQLite native CacheLayer operations through Foundation configuration', function (): void {
    $root = sys_get_temp_dir() . '/foundation-cache-selected-' . bin2hex(random_bytes(8));
    mkdir($root . '/storage/cache', 0700, true);

    try {
        $config = new ConfigRepository([
            'app' => ['base_path' => $root],
            'cache' => [
                'prefix' => 'foundation:selected:' . bin2hex(random_bytes(6)) . ':',
                'stores' => [
                    'local' => ['driver' => 'local', 'path' => 'storage/cache'],
                    'sqlite' => ['driver' => 'sqlite', 'file' => 'storage/cache/selected.sqlite'],
                ],
            ],
        ]);
        $factory = new CacheLayerFactory(
            $config,
            new PathManager($root, ['cache' => 'storage/cache']),
            static fn(?string $name = null): never => throw new LogicException('Local cache must not resolve DBLayer.'),
        );

        foreach (['local', 'sqlite'] as $name) {
            $first = $factory->make($name);
            $second = $factory->make($name);
            $key = 'selected-' . $name;
            expect($first)->not->toBe($second)
                ->and($first->get($key))->toBeNull()
                ->and($first->set($key, $name, 60))->toBeTrue()
                ->and($second->get($key))->toBe($name)
                ->and($second->delete($key))->toBeTrue()
                ->and($first->get($key))->toBeNull();
        }
    } finally {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($root);
    }
});

it('qualifies selected Redis, Valkey and Memcached stores against live service dependencies', function (
    string $driver,
    string $hostEnv,
    string $portEnv,
    ?string $passwordEnv,
): void {
    $host = getenv($hostEnv);
    $port = getenv($portEnv);
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        throw new RuntimeException(sprintf('Selected cache backend requires %s and %s.', $hostEnv, $portEnv));
    }

    $client = $driver === 'memcache' ? new Memcached() : new Redis();
    if ($client instanceof Memcached) {
        if (!$client->addServer($host, (int) $port)) {
            throw new RuntimeException('Unable to configure live Memcached service.');
        }
    } else {
        if (!$client->connect($host, (int) $port, 1.0)) {
            throw new RuntimeException('Unable to connect to live Redis-compatible service.');
        }
        $password = $passwordEnv === null ? false : getenv($passwordEnv);
        if (is_string($password) && $password !== '' && !$client->auth($password)) {
            throw new RuntimeException('Unable to authenticate with live Redis-compatible service.');
        }
    }

    try {
        $namespace = 'foundation-selected-' . bin2hex(random_bytes(8));
        $factory = new CacheLayerFactory(
            new ConfigRepository([
                'cache' => [
                    'stores' => [
                        'selected' => [
                            'driver' => $driver,
                            'namespace' => $namespace,
                            'client' => $client,
                            'fail_open' => false,
                        ],
                    ],
                ],
            ]),
            new PathManager(sys_get_temp_dir(), ['cache' => 'cache']),
            static fn(?string $name = null): never => throw new LogicException('Remote cache must not resolve DBLayer.'),
        );
        $producer = $factory->make('selected');
        $consumer = $factory->make('selected');
        $key = 'selected-' . bin2hex(random_bytes(6));
        expect($producer)->not->toBe($consumer)
            ->and($producer->get($key))->toBeNull()
            ->and($producer->set($key, 'native-operation', 60))->toBeTrue()
            ->and($consumer->get($key))->toBe('native-operation')
            ->and($consumer->delete($key))->toBeTrue()
            ->and($producer->get($key))->toBeNull();
    } finally {
        if ($client instanceof Redis) {
            $client->close();
        } else {
            $client->quit();
        }
    }
})->with([
    'redis' => ['redis', 'IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
    'valkey' => ['valkey', 'IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
    'memcached' => ['memcache', 'IC_MEMCACHED_HOST', 'IC_MEMCACHED_PORT', null],
]);

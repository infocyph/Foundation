<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;

it('preserves native CacheLayer 4 history across two nodes and fails closed when history changes', function (
    string $hostEnv,
    string $portEnv,
    string $passwordEnv,
): void {
    $host = getenv($hostEnv);
    $port = getenv($portEnv);
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        throw new RuntimeException(sprintf('Native cache cluster test requires %s and %s.', $hostEnv, $portEnv));
    }

    $connect = static function () use ($host, $port, $passwordEnv): Redis {
        $client = new Redis();
        if (!$client->connect($host, (int) $port, 1.0)) {
            throw new RuntimeException('Unable to connect to the configured Redis-compatible service.');
        }

        $password = getenv($passwordEnv);
        if (is_string($password) && $password !== '' && !$client->auth($password)) {
            throw new RuntimeException('Unable to authenticate with the configured Redis-compatible service.');
        }

        return $client;
    };

    $producerClient = $connect();
    $consumerClient = $connect();
    $root = sys_get_temp_dir() . '/foundation-cluster-history-' . bin2hex(random_bytes(8));
    $cluster = 'catalog-' . bin2hex(random_bytes(8));
    $prefix = 'foundation:test:cluster:' . bin2hex(random_bytes(8)) . ':';
    $identity = 'history-' . bin2hex(random_bytes(8));
    $build = static function (
        string $node,
        Redis $client,
        string $transportIdentity,
    ) use ($root, $cluster, $prefix): CacheLayerFactory {
        $base = $root . '/' . $node;
        if (!is_dir($base . '/storage/cache') && !mkdir($base . '/storage/cache', 0775, true)) {
            throw new RuntimeException('Unable to create isolated node cache directory.');
        }

        return new CacheLayerFactory(
            new ConfigRepository([
                'app' => ['base_path' => $base],
                'cache' => [
                    'stores' => [
                        'node' => [
                            'driver' => 'node',
                            'namespace' => 'catalog',
                            'sqlite_file' => 'storage/cache/node.sqlite',
                            'apcu_enabled' => false,
                        ],
                    ],
                    'transports' => [
                        'stream' => [
                            'driver' => 'redis_stream',
                            'client' => $client,
                            'prefix' => $prefix,
                        ],
                    ],
                    'clusters' => [
                        'catalog' => [
                            'store' => 'node',
                            'cluster' => $cluster,
                            'node_id' => $node,
                            'transport' => 'stream',
                            'transport_identity' => $transportIdentity,
                        ],
                    ],
                ],
            ]),
            new PathManager($base),
            static fn(?string $name = null): never => throw new LogicException(
                'Native Redis Stream cluster must not require a DBLayer connection.',
            ),
        );
    };

    try {
        $producer = $build('node-a', $producerClient, $identity)->cluster('catalog');
        $consumer = $build('node-b', $consumerClient, $identity)->cluster('catalog');

        $consumer->cache()->set('product.42', 'stale');
        $producer->invalidateKey('product.42');

        expect($consumer->consume())->toBe(1)
            ->and($consumer->cache()->get('product.42'))->toBeNull();

        $consumer->cache()->set('product.43', 'stale-after-reset');
        $restarted = $build('node-b', $consumerClient, $identity . '-reset')->cluster('catalog');

        expect($restarted->cache()->get('product.43'))->toBeNull();
    } finally {
        $producerClient->del($prefix . $cluster);
        $producerClient->close();
        $consumerClient->close();

        foreach (['node-a', 'node-b'] as $node) {
            $directory = $root . '/' . $node . '/storage/cache';
            if (is_dir($directory)) {
                foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
                    if ($entry->isFile() && !$entry->isLink()) {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($directory);
                rmdir(dirname($directory));
                rmdir(dirname(dirname($directory)));
            }
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
})->with([
    'redis-stream' => ['IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
    'valkey-stream' => ['IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
]);

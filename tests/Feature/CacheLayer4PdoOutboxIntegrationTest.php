<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\Outbox\ClusterOutbox;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;

it('keeps native PDO outbox events inside the same DBLayer transaction across nodes', function (
    string $dsnName,
    string $usernameName,
    string $passwordName,
    string $driver,
): void {
    $dsn = getenv($dsnName);
    if (!is_string($dsn) || !str_starts_with($dsn, $driver . ':')) {
        throw new RuntimeException(sprintf('Native PDO outbox test requires %s.', $dsnName));
    }

    $properties = [];
    foreach (explode(';', substr($dsn, strlen($driver) + 1)) as $piece) {
        if (str_contains($piece, '=')) {
            [$key, $value] = explode('=', $piece, 2);
            $properties[$key] = $value;
        }
    }
    $databaseName = $properties['dbname'] ?? null;
    if (!is_string($databaseName) || $databaseName === '') {
        throw new RuntimeException('Shared test database must have a database name.');
    }

    $username = getenv($usernameName);
    $password = getenv($passwordName);
    $connectionConfig = ConnectionConfig::fromArray([
        'driver' => $driver,
        'host' => $properties['host'] ?? '127.0.0.1',
        'port' => (int) ($properties['port'] ?? ($driver === 'mysql' ? '3306' : '5432')),
        'database' => $databaseName,
        'username' => is_string($username) ? $username : '',
        'password' => is_string($password) ? $password : '',
    ]);
    $writer = new Connection($connectionConfig, 'foundation-outbox-writer');
    $reader = new Connection($connectionConfig, 'foundation-outbox-reader');
    $root = sys_get_temp_dir() . '/foundation-pdo-outbox-' . bin2hex(random_bytes(8));
    $clusterName = 'foundation-outbox-' . bin2hex(random_bytes(8));
    $identity = 'history-' . bin2hex(random_bytes(8));

    $factory = static function (string $node, Connection $connection) use (
        $clusterName,
        $identity,
        $root,
    ): CacheLayerFactory {
        $base = $root . '/' . $node;
        if (!is_dir($base . '/storage/cache') && !mkdir($base . '/storage/cache', 0775, true)) {
            throw new RuntimeException('Unable to prepare the cluster node cache directory.');
        }

        return new CacheLayerFactory(
            new ConfigRepository([
                'app' => ['base_path' => $base],
                'cache' => [
                    'stores' => [
                        'node' => [
                            'driver' => 'node',
                            'sqlite_file' => 'storage/cache/node.sqlite',
                            'namespace' => 'catalog',
                            'apcu_enabled' => false,
                        ],
                    ],
                    'transports' => [
                        'events' => ['driver' => 'pdo', 'connection' => 'primary'],
                    ],
                    'clusters' => [
                        'events' => [
                            'store' => 'node',
                            'cluster' => $clusterName,
                            'node_id' => $node,
                            'transport' => 'events',
                            'transport_identity' => $identity,
                        ],
                    ],
                ],
            ]),
            new PathManager($base, ['cache' => 'storage/cache']),
            static function (?string $name = null) use ($connection): Connection {
                if ($name !== 'primary') {
                    throw new LogicException('Unexpected PDO outbox connection name.');
                }

                return $connection;
            },
        );
    };

    $schemaReady = false;
    try {
        $sourceFactory = $factory('node-a', $writer);
        $targetFactory = $factory('node-b', $reader);
        $source = $sourceFactory->cluster('events');
        $target = $targetFactory->cluster('events');
        $schemaReady = true;
        $manager = new CacheManager(
            $sourceFactory,
            static function (?string $name = null) use ($writer): Connection {
                if ($name !== null && $name !== 'primary') {
                    throw new LogicException('Unexpected transactional outbox database connection name.');
                }

                return $writer;
            },
            $sourceFactory,
        );
        $source->cache()->set('product.42', 'source');
        $target->cache()->set('product.42', 'target');

        expect(static function () use ($manager): void {
            $manager->transactionalInvalidation(
                'events',
                static function (Connection $connection, ClusterOutbox $outbox): void {
                    expect($connection->getPdo()->inTransaction())->toBeTrue();
                    $outbox->invalidateKey('product.42');
                    throw new RuntimeException('reject transaction');
                },
            );
        })->toThrow(TransactionException::class);

        expect($source->cache()->get('product.42'))->toBe('source')
            ->and($target->cache()->get('product.42'))->toBe('target')
            ->and($target->status()->pendingEventCount)->toBe(0);

        $result = $manager->transactionalInvalidation(
            'events',
            static function (Connection $connection, ClusterOutbox $outbox): string {
                expect($connection->getPdo()->inTransaction())->toBeTrue();
                $outbox->invalidateKey('product.42');

                return 'committed';
            },
        );

        expect($result)->toBe('committed')
            ->and($source->cache()->get('product.42'))->toBeNull()
            ->and($target->cache()->get('product.42'))->toBe('target')
            ->and($target->consume())->toBe(1)
            ->and($target->cache()->get('product.42'))->toBeNull()
            ->and($target->consume())->toBe(0);
    } finally {
        if ($schemaReady) {
            $pdo = $writer->getPdo();
            $delete = $pdo->prepare('DELETE FROM cachelayer_invalidation_events WHERE cluster_name = ?');
            $delete->execute([$clusterName]);
            $delete = $pdo->prepare('DELETE FROM cachelayer_invalidation_clusters WHERE cluster_name = ?');
            $delete->execute([$clusterName]);
        }
        $writer->disconnect();
        $reader->disconnect();

        foreach (['node-a', 'node-b'] as $node) {
            $directory = $root . '/' . $node . '/storage/cache';
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isFile() && !$entry->isLink()) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir(dirname(dirname($directory)));
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
})->with([
    'mysql' => ['IC_MYSQL_DSN', 'IC_MYSQL_USER', 'IC_MYSQL_PASSWORD', 'mysql'],
    'postgresql' => ['IC_POSTGRES_DSN', 'IC_POSTGRES_USER', 'IC_POSTGRES_PASSWORD', 'pgsql'],
]);

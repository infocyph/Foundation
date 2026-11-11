<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;

require dirname(__DIR__) . '/vendor/autoload.php';

$rawSeeds = getenv('FOUNDATION_REDIS_CLUSTER_SEEDS');
if (!is_string($rawSeeds) || trim($rawSeeds) === '' || !class_exists(RedisCluster::class)) {
    throw new RuntimeException('A real phpredis Redis Cluster and FOUNDATION_REDIS_CLUSTER_SEEDS are required.');
}

$seeds = array_values(array_filter(array_map(trim(...), explode(',', $rawSeeds))));
if (count($seeds) < 3) {
    throw new RuntimeException('Cluster integration requires at least three independent Redis Cluster node seeds.');
}

$namespace = 'foundation-live-cluster-' . bin2hex(random_bytes(8));
$config = [
    'app' => ['base_path' => dirname(__DIR__), 'env' => 'local'],
    '_config_cache' => false,
    'cache' => [
        'default' => 'cluster',
        'stores' => [
            'cluster' => [
                'driver' => 'redis_cluster',
                'namespace' => $namespace,
                'seeds' => $seeds,
                'timeout' => 2.0,
                'read_timeout' => 2.0,
                'persistent' => false,
            ],
        ],
    ],
];

$writer = Foundation::cli($config)->boot()->make(CacheManager::class)->store();
$reader = Foundation::cli($config)->boot()->make(CacheManager::class)->store();
if (!$writer instanceof Cache || !$reader instanceof Cache) {
    throw new RuntimeException('Foundation must delegate Redis Cluster stores to native CacheLayer.');
}

$assert = static function (bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException($reason);
    }
};

try {
    $values = [];
    for ($i = 0; $i < 64; ++$i) {
        $values['slot-' . $i] = 'value-' . $i;
    }

    $assert($writer->setMultiple($values, 90), 'Cluster cross-slot bulk write failed.');
    $assert($reader->getMultiple(array_keys($values)) === $values, 'Independent Foundation instance did not read every cluster slot.');

    $atomic = $writer->atomic();
    $assert($atomic instanceof AtomicCacheInterface, 'Native Redis Cluster atomic API is unavailable.');
    if (!$atomic instanceof AtomicCacheInterface) {
        throw new RuntimeException('Native Redis Cluster atomic API is unavailable.');
    }
    $assert($atomic->setIfAbsent('claim', 'original', 90), 'First Redis Cluster claim failed.');
    $assert(!$atomic->setIfAbsent('claim', 'duplicate', 90), 'Duplicate Redis Cluster claim was accepted.');
    $assert($atomic->compareAndSet('claim', 'original', 'updated', 90), 'Redis Cluster conditional update failed.');
    $assert($atomic->getAndDelete('claim', 'missing') === 'updated', 'Native atomic consumption failed.');
    $assert($atomic->getAndDelete('claim', 'missing') === 'missing', 'Native atomic consumption was not single-use.');

    $assert($writer->setTagged('tagged', 'value', ['catalog'], 90), 'Tagged Redis Cluster write failed.');
    $assert($writer->invalidateTag('catalog'), 'Redis Cluster tag invalidation failed.');
    $assert($reader->get('tagged') === null, 'Redis Cluster stale tag survived invalidation.');
} finally {
    $writer->clear();
}

fwrite(STDOUT, "Foundation native Redis Cluster integration passed.\n");

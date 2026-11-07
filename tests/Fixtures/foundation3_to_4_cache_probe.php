<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;

if ($argc !== 5) {
    throw new InvalidArgumentException('Expected phase, project, versioned autoloader, and report directory.');
}

[$phase, $project, $autoload, $reports] = array_slice($argv, 1);
if (!in_array($phase, ['old-seed', 'new-read', 'old-restore', 'new-rollforward'], true)) {
    throw new InvalidArgumentException('Unknown cross-major Redis cache phase.');
}

require $autoload;

$config = new ConfigRepository([
    'app' => ['base_path' => $project],
    'cache' => [
        'default' => 'retained',
        'stores' => [
            'retained' => [
                'driver' => 'redis',
                'dsn' => 'redis://127.0.0.1:6379',
                'namespace' => 'foundation-major-cutover',
            ],
        ],
    ],
]);
$factory = new CacheLayerFactory(
    $config,
    new PathManager($project, ['cache' => 'storage/cache']),
    static function (?string $name = null): Connection {
        unset($name);
        throw new LogicException('Native Redis cache verification must not resolve a SQL connection.');
    },
);
$cache = $factory->make('retained');
if (!$cache instanceof CacheInterface) {
    throw new RuntimeException('Selected CacheLayer Redis store is unavailable.');
}

$redis = new Redis();
if ($redis->connect('127.0.0.1', 6379, 2.0) !== true) {
    throw new RuntimeException('Dedicated Redis snapshot service is unavailable.');
}

$expected = ['session' => 'legacy-session', 'replay_denied' => true, 'account' => 'legacy-account'];
$snapshot = $reports . '/original-cache-snapshot.json';
try {
    if ($phase === 'old-seed') {
        if (!$cache->set('replay-state', $expected, 3600)) {
            throw new RuntimeException('Original native CacheLayer could not persist replay-denial state.');
        }
        if ($cache->get('replay-state') !== $expected) {
            throw new RuntimeException('Original native CacheLayer could not read its persisted state.');
        }

        $keys = $redis->keys('*');
        if (!is_array($keys) || $keys === []) {
            throw new RuntimeException('Native Redis contains no persisted original-source cache entries.');
        }

        $entries = [];
        foreach ($keys as $key) {
            $bytes = $redis->dump($key);
            $ttl = $redis->pttl($key);
            if (!is_string($bytes) || !is_int($ttl) || $ttl < -1) {
                throw new RuntimeException('Unable to snapshot raw Redis cache entry.');
            }
            $entries[$key] = ['dump' => base64_encode($bytes), 'ttl_ms' => $ttl];
        }
        ksort($entries, SORT_STRING);
        if (file_put_contents($snapshot, json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
            throw new RuntimeException('Unable to preserve dedicated Redis state snapshot.');
        }
    } elseif ($phase === 'old-restore') {
        $entries = json_decode((string) file_get_contents($snapshot), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($entries) || $entries === []) {
            throw new RuntimeException('Dedicated Redis recovery snapshot is missing.');
        }

        $keys = $redis->keys('*');
        if (!is_array($keys)) {
            throw new RuntimeException('Unable to enumerate isolated Redis recovery keys.');
        }
        if ($keys !== []) {
            $redis->del(...$keys);
        }
        foreach ($entries as $key => $entry) {
            $bytes = base64_decode($entry['dump'], true);
            if (!is_string($bytes) || $redis->restore($key, max(0, (int) $entry['ttl_ms']), $bytes) !== true) {
                throw new RuntimeException('Native Redis recovery failed for stored cache entry.');
            }
        }
    } elseif ($phase === 'new-read') {
        if (!$cache->set('candidate-only', 'must-not-survive-rollback', 3600)) {
            throw new RuntimeException('New source cannot write to selected Redis cache.');
        }
    }

    if ($cache->get('replay-state') !== $expected) {
        throw new RuntimeException('Original replay-denial/session cache state changed across versions or rollback.');
    }
    if ($phase === 'new-rollforward' && $cache->get('candidate-only') !== null) {
        throw new RuntimeException('Uncommitted candidate cache writes survived the coherent old-source restore.');
    }

    $report = [
        'phase' => $phase,
        'source_file' => (new ReflectionClass(CacheLayerFactory::class))->getFileName(),
        'retained_sha256' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)),
        'redis_entries' => count($redis->keys('*')),
    ];
    if (file_put_contents($reports . '/cache-' . $phase . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
        throw new RuntimeException('Cannot write Redis cutover evidence.');
    }
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $redis->close();
}

<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Cache\FoundationCacheKey;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array<string,mixed> */
function cacheLayer33Measure(
    string $name,
    string $type,
    callable $operation,
    int $operations,
    int $repetitions,
    int $warmup,
): array {
    $samples = [];
    $totalNanoseconds = 0;

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($iteration = 0; $iteration < $warmup; ++$iteration) {
            $operation();
        }

        $started = hrtime(true);
        for ($iteration = 0; $iteration < $operations; ++$iteration) {
            $operation();
        }
        $elapsed = max(1, hrtime(true) - $started);
        $totalNanoseconds += $elapsed;
        $samples[] = $elapsed / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];
    $minimum = $samples[0];
    $maximum = $samples[count($samples) - 1];
    $attempted = $operations * $repetitions;

    return [
        'name' => $name,
        'type' => $type,
        'metadata' => [
            'operations_per_repetition' => $operations,
            'median_ns' => round($median, 2),
            'minimum_ns' => round($minimum, 2),
            'maximum_ns' => round($maximum, 2),
        ],
        'repetitions' => $repetitions,
        'warmup_operations' => $warmup,
        'duration_seconds' => $totalNanoseconds / 1_000_000_000,
        'concurrency' => 1,
        'result' => [
            'attempted_operations' => $attempted,
            'successful_operations' => $attempted,
            'failed_operations' => 0,
            'timeouts' => 0,
            'successful_rpm' => 60_000_000_000 / max(0.000001, $median),
            'error_rate' => 0.0,
            'latency_ms' => [
                'minimum' => $minimum / 1_000_000,
                'average' => $median / 1_000_000,
                'p50' => $median / 1_000_000,
                'p95' => cacheLayer33Percentile($samples, 0.95) / 1_000_000,
                'p99' => cacheLayer33Percentile($samples, 0.99) / 1_000_000,
                'maximum' => $maximum / 1_000_000,
            ],
            'cpu' => ['average_percent' => null, 'peak_percent' => null],
            'memory' => [
                'average_mb' => null,
                'peak_mb' => memory_get_peak_usage(true) / 1_048_576,
                'growth_mb' => null,
            ],
            'stability' => [
                'status' => 'unverified',
                'spread_percent' => (($maximum - $minimum) / max(0.000001, $median)) * 100,
            ],
        ],
    ];
}

/** @param list<float> $samples */
function cacheLayer33Percentile(array $samples, float $percentile): float
{
    $index = (int) ceil($percentile * count($samples)) - 1;

    return $samples[max(0, min(count($samples) - 1, $index))];
}

/** @return array<string,mixed> */
function cacheLayer33Environment(string $cacheLayerVersion): array
{
    $runtime = [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'operating_system' => php_uname(),
        'memory_limit' => ini_get('memory_limit') ?: 'unknown',
        'opcache' => extension_loaded('Zend OPcache'),
        'jit' => ini_get('opcache.jit') ?: false,
        'xdebug' => extension_loaded('xdebug'),
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'cachelayer' => $cacheLayerVersion,
    ];

    return [
        'stable' => false,
        'fingerprint' => hash('sha3-256', json_encode($runtime, JSON_THROW_ON_ERROR)),
        ...$runtime,
    ];
}

function cacheLayer33RedisDsn(): string
{
    $explicit = getenv('CACHELAYER_BENCH_REDIS_DSN');
    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $host = getenv('IC_REDIS_HOST') ?: '127.0.0.1';
    $port = getenv('IC_REDIS_PORT') ?: '6379';
    $password = getenv('IC_REDIS_PASSWORD');
    $credentials = is_string($password) && $password !== ''
        ? ':' . rawurlencode($password) . '@'
        : '';

    return sprintf('redis://%s%s:%s', $credentials, $host, $port);
}

$operations = max(1_000, (int) (getenv('CACHELAYER_BENCH_OPERATIONS') ?: 10_000));
$repetitions = max(3, (int) (getenv('CACHELAYER_BENCH_REPETITIONS') ?: 7));
$warmup = max(100, (int) (getenv('CACHELAYER_BENCH_WARMUP') ?: 500));
$options = new CacheOptions(
    integrityKey: 'foundation-cachelayer-33-benchmark-integrity',
    allowClosures: false,
    allowObjects: false,
    failOpen: false,
);
$cache = Cache::memory(
    namespace: 'foundation-cachelayer-33-benchmark',
    options: $options,
);
$nonAtomic = Cache::nullStore($options);
$atomic = $cache->atomic() ?? throw new RuntimeException('Memory cache must expose CacheLayer 3.3 atomic capability.');
$ttl = new CacheLayerTtlStore($cache);
$logical = 'mfa:challenge:' . str_repeat('attacker-controlled-segment:', 8);
$material = "foundation.auth.ttl.v1\0" . $logical;
$directSequence = 0;
$foundationSequence = 0;
$oldClaimSequence = 0;
$newClaimSequence = 0;
$oldConsumeSequence = 0;
$newConsumeSequence = 0;

$benchmarkConfig = new ConfigRepository([
    'app' => ['base_path' => dirname(__DIR__)],
    'cache' => [
        'default' => 'bench',
        'prefix' => 'foundation.',
        'stores' => [
            'bench' => [
                'driver' => 'memory',
                'fail_open' => false,
            ],
        ],
    ],
]);
$database = static fn(?string $connection = null): never => throw new RuntimeException(sprintf(
    'Benchmark cache path unexpectedly requested database connection %s.',
    $connection ?? '<default>',
));
$factory = new CacheLayerFactory($benchmarkConfig, new PathManager(dirname(__DIR__)), $database);
$warmManager = new CacheManager($factory, $database);
$warmManager->store('bench');

$batch = [];
for ($i = 0; $i < 32; ++$i) {
    $batch['bulk-' . $i] = $i;
}
$batchKeys = array_keys($batch);

$workloads = [
    cacheLayer33Measure(
        'physical-key-xxh128',
        'component',
        static fn(): string => hash('xxh128', $material),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'physical-key-sha256-comparison',
        'comparison',
        static fn(): string => hash('sha256', $material),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'physical-key-sha3-256-comparison',
        'comparison',
        static fn(): string => hash('sha3-256', $material),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'foundation-cache-key-xxh128-fingerprint-boundary',
        'component',
        static fn(): string => FoundationCacheKey::fingerprint('fp', 'foundation.cache.fingerprint.v1', $logical),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'foundation-cache-key-sha3-security-boundary',
        'component',
        static fn(): string => FoundationCacheKey::security('at', 'foundation.auth.ttl.v1', $logical),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'atomic-capability-present-discovery',
        'optional-capability',
        static fn(): bool => $cache->atomic() !== null,
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'atomic-capability-absent-discovery',
        'optional-capability',
        static fn(): bool => $nonAtomic->atomic() === null,
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'foundation-named-store-first-construction',
        'composition',
        static function () use ($factory, $database): object {
            return (new CacheManager($factory, $database))->store('bench');
        },
        max(500, intdiv($operations, 10)),
        $repetitions,
        max(50, intdiv($warmup, 10)),
    ),
    cacheLayer33Measure(
        'foundation-named-store-warm-lookup',
        'composition',
        static fn(): object => $warmManager->store('bench'),
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'direct-cachelayer-set-get',
        'boundary',
        static function () use ($cache, &$directSequence): mixed {
            $key = 'direct-' . (++$directSequence % 1024);
            $cache->set($key, 1, 60);

            return $cache->get($key);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'foundation-ttl-set-get',
        'boundary',
        static function () use ($ttl, &$foundationSequence): mixed {
            $key = 'mfa:challenge:' . (++$foundationSequence % 1024);
            $ttl->put($key, 1, 60);

            return $ttl->get($key);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'legacy-replay-has-set-cycle',
        'comparison',
        static function () use ($cache, &$oldClaimSequence): bool {
            $key = 'legacy-claim-' . (++$oldClaimSequence % 1024);
            $cache->delete($key);
            if ($cache->has($key)) {
                return false;
            }

            return $cache->set($key, 1, 60);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'atomic-set-if-absent-cycle',
        'component',
        static function () use ($cache, $atomic, &$newClaimSequence): bool {
            $key = 'atomic-claim-' . (++$newClaimSequence % 1024);
            $cache->delete($key);

            return $atomic->setIfAbsent($key, 1, 60);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'legacy-consume-get-delete-cycle',
        'comparison',
        static function () use ($cache, &$oldConsumeSequence): mixed {
            $key = 'legacy-consume-' . (++$oldConsumeSequence % 1024);
            $cache->set($key, 1, 60);
            $value = $cache->get($key);
            $cache->delete($key);

            return $value;
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'atomic-get-and-delete-cycle',
        'component',
        static function () use ($cache, $atomic, &$newConsumeSequence): mixed {
            $key = 'atomic-consume-' . (++$newConsumeSequence % 1024);
            $cache->set($key, 1, 60);

            return $atomic->getAndDelete($key);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'atomic-compare-and-set-cycle',
        'component',
        static function () use ($cache, $atomic): bool {
            $cache->set('cas-cycle', 0, 60);

            return $atomic->compareAndSet('cas-cycle', 0, 1, 60);
        },
        $operations,
        $repetitions,
        $warmup,
    ),
    cacheLayer33Measure(
        'bulk-native-32-keys',
        'component',
        static function () use ($cache, $batch, $batchKeys): array {
            $cache->setMultiple($batch, 60);
            $result = [];
            foreach ($cache->getMultiple($batchKeys) as $key => $value) {
                $result[(string) $key] = $value;
            }

            return $result;
        },
        max(100, intdiv($operations, 32)),
        $repetitions,
        max(20, intdiv($warmup, 32)),
    ),
    cacheLayer33Measure(
        'bulk-loop-32-keys-comparison',
        'comparison',
        static function () use ($cache, $batch): array {
            $result = [];
            foreach ($batch as $key => $value) {
                $cache->set($key, $value, 60);
            }
            foreach ($batch as $key => $_) {
                $result[$key] = $cache->get($key);
            }

            return $result;
        },
        max(100, intdiv($operations, 32)),
        $repetitions,
        max(20, intdiv($warmup, 32)),
    ),
];

if (class_exists(Redis::class)) {
    $counterNamespace = 'foundation-cachelayer-33-benchmark-' . bin2hex(random_bytes(4));
    $nativeCounters = AtomicCounters::redis($counterNamespace, cacheLayer33RedisDsn());
    $foundationCounters = new AtomicCounterStore($nativeCounters);
    $nativeCounters->delete('direct-counter');
    $foundationCounters->reset('auth:counter');

    $workloads[] = cacheLayer33Measure(
        'redis-native-atomic-counter',
        'component',
        static fn(): int => $nativeCounters->increment('direct-counter', 1, 300)->value,
        max(1_000, intdiv($operations, 4)),
        $repetitions,
        max(100, intdiv($warmup, 4)),
    );
    $workloads[] = cacheLayer33Measure(
        'foundation-auth-counter-boundary',
        'boundary',
        static fn(): int => $foundationCounters->increment('auth:counter', 1, 300),
        max(1_000, intdiv($operations, 4)),
        $repetitions,
        max(100, intdiv($warmup, 4)),
    );
    $nativeCounters->delete('direct-counter');
    $foundationCounters->reset('auth:counter');
}

$soakOperations = max(10_000, $operations);
$before = memory_get_usage(true);
$soakStarted = hrtime(true);
for ($i = 0; $i < $soakOperations; ++$i) {
    $key = 'soak:' . ($i % 256);
    $ttl->put($key, $i, 60);
    $ttl->pull($key);
}
$soakSeconds = max(1, hrtime(true) - $soakStarted) / 1_000_000_000;
$after = memory_get_usage(true);
$workloads[] = [
    'name' => 'persistent-auth-state-memory-soak',
    'type' => 'persistent-worker',
    'metadata' => [
        'operations' => $soakOperations,
        'bounded_key_cardinality' => 256,
    ],
    'repetitions' => 1,
    'warmup_operations' => 0,
    'duration_seconds' => $soakSeconds,
    'concurrency' => 1,
    'result' => [
        'attempted_operations' => $soakOperations,
        'successful_operations' => $soakOperations,
        'failed_operations' => 0,
        'timeouts' => 0,
        'successful_rpm' => ($soakOperations / $soakSeconds) * 60,
        'error_rate' => 0.0,
        'latency_ms' => null,
        'cpu' => ['average_percent' => null, 'peak_percent' => null],
        'memory' => [
            'average_mb' => null,
            'peak_mb' => memory_get_peak_usage(true) / 1_048_576,
            'growth_mb' => ($after - $before) / 1_048_576,
        ],
        'stability' => ['status' => 'bounded-cardinality-soak'],
    ],
];

$cacheLayerVersion = InstalledVersions::getPrettyVersion('infocyph/cachelayer') ?? 'unknown';
$result = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'environment' => cacheLayer33Environment($cacheLayerVersion),
    'metadata' => [
        'suite' => 'foundation-cachelayer-33-utilization',
        'cachelayer' => $cacheLayerVersion,
        'cachelayer_reference' => InstalledVersions::getReference('infocyph/cachelayer'),
        'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
        'boundary' => 'Foundation logical state/key policy over CacheLayer 3.3 native atomic and bulk capabilities',
        'key_policy' => 'SHA3-256/Base64URL is used for security-sensitive physical keys; XXH128 is used for non-security fingerprinting and compaction.',
    ],
    'workloads' => $workloads,
];

$buildDirectory = dirname(__DIR__) . '/build';
if (!is_dir($buildDirectory) && !mkdir($buildDirectory, 0777, true) && !is_dir($buildDirectory)) {
    throw new RuntimeException('Unable to create benchmark output directory.');
}

$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($buildDirectory . '/cachelayer-33-utilization.json', $encoded . PHP_EOL, LOCK_EX);
fwrite(STDOUT, $encoded . PHP_EOL);

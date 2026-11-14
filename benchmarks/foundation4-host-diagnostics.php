<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/Fixtures/ProbeInput.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport;
use Infocyph\Foundation\Tests\Fixtures\ProbeInput;

$arguments = ProbeInput::fromServer($_SERVER);
if (count($arguments) !== 2) {
    throw new InvalidArgumentException('A backend diagnostic output file is required.');
}
$environment = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5',
        $environment('FOUNDATION_HOST_PG_HOST', '127.0.0.1'),
        $environment('FOUNDATION_HOST_PG_PORT', '5432'),
        $environment('FOUNDATION_HOST_PG_DATABASE', 'foundation_host'),
    ),
    $environment('FOUNDATION_HOST_PG_USER', 'phpforge'),
    $environment('FOUNDATION_HOST_PG_PASSWORD', 'Phpforge_123!'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$statement = $pdo->query('SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit, tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted, conflicts, temp_files, temp_bytes, deadlocks, blk_read_time, blk_write_time FROM pg_stat_database WHERE datname = current_database()');
if ($statement === false || !is_array($postgres = $statement->fetch(PDO::FETCH_ASSOC))) {
    throw new RuntimeException('PostgreSQL activity counters are unavailable.');
}
$redisParts = parse_url($environment('FOUNDATION_HOST_REDIS_DSN', 'redis://127.0.0.1:6379'));
if (!is_array($redisParts) || !isset($redisParts['scheme'], $redisParts['host']) || !in_array($redisParts['scheme'], ['redis', 'rediss'], true)) {
    throw new InvalidArgumentException('Diagnostic Redis DSN must select the configured Redis TCP service.');
}
$redis = new Redis();
$redisHost = ($redisParts['scheme'] === 'rediss' ? 'tls://' : '') . $redisParts['host'];
if (!$redis->connect($redisHost, $redisParts['port'] ?? 6379, 5.0)) {
    throw new RuntimeException('Unable to connect to the diagnostic Redis service.');
}

try {
    $password = isset($redisParts['pass']) ? rawurldecode($redisParts['pass']) : null;
    if ($password !== null) {
        $authentication = isset($redisParts['user']) && $redisParts['user'] !== ''
            ? [rawurldecode($redisParts['user']), $password] : [$password];

        try {
            $authenticated = $redis->rawCommand('AUTH', ...$authentication);
        } catch (RedisException) {
            // Native command traces can expose AUTH arguments.
            throw new RuntimeException('Diagnostic Redis authentication failed.');
        }
        if ($authenticated !== true && $authenticated !== 'OK') {
            throw new RuntimeException('Diagnostic Redis authentication failed.');
        }
    }
    $info = BenchmarkSupport::objectMap($redis->info('stats'));
    $counters = [];
    foreach (['total_connections_received', 'total_commands_processed', 'total_net_input_bytes', 'total_net_output_bytes', 'rejected_connections', 'expired_keys', 'evicted_keys', 'keyspace_hits', 'keyspace_misses'] as $key) {
        $counters[$key] = $info[$key] ?? null;
    }
} finally {
    $redis->close();
}
$json = json_encode(['postgres' => $postgres, 'redis' => $counters], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
if (file_put_contents(ProbeInput::path($arguments[1]), $json) !== strlen($json)) {
    throw new RuntimeException('Unable to write backend diagnostic counters.');
}

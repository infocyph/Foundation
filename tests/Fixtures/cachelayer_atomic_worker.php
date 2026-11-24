<?php

declare(strict_types=1);

namespace Infocyph;

use InvalidArgumentException;

require_once __DIR__ . '/ProbeInput.php';
$argv = \Infocyph\Foundation\Tests\Fixtures\ProbeInput::fromServer($_SERVER);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskProofStore;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $mode, $namespace, $dsn, $arg1] = $argv + [null, null, null, null, null, null];
if (!is_string($mode) || !is_string($namespace) || !is_string($dsn) || !is_string($arg1)) {
    throw new InvalidArgumentException('Invalid CacheLayer atomic worker arguments.');
}

$cache = Cache::redis(
    namespace: $namespace,
    dsn: $dsn,
    options: new CacheOptions(
        integrityKey: in_array($mode, ['risk-proof', 'risk-clearance', 'risk-hash-slot'], true) ? str_repeat('i', 32) : null,
        failOpen: false,
    ),
);

fwrite(STDOUT, "ready\n");
if (fgets(STDIN) !== "go\n") {
    throw new InvalidArgumentException('CacheLayer worker did not receive its start signal.');
}

$result = match ($mode) {
    'claim' => (new CacheLayerWebhookReplayStore($cache))->claim('concurrency', $arg1, 30),
    'pull' => (new CacheLayerTtlStore($cache))->pull($arg1, '__miss__'),
    'cas' => $cache->atomic()?->compareAndSet($arg1, 0, 1, 30) ?? false,
    'counter' => (new AtomicCounterStore(AtomicCounters::redis($namespace, $dsn)))->increment($arg1, ttlSeconds: 30),
    'risk-failure' => new RiskTelemetry($cache, str_repeat('k', 32), shared: true)->recordFailure(
        RiskContext::fromTransport('concurrency', 'login', '192.0.2.1'),
        120,
        $arg1,
    ),
    'risk-proof' => new RiskProofStore(new RiskAuthority($cache, str_repeat('k', 32), shared: true), 'provider')->claimVerifiedProof($arg1),
    'risk-clearance' => new RiskProofStore(new RiskAuthority($cache, str_repeat('k', 32), shared: true), 'provider')->redeemClearance(
        RiskContext::fromTransport('concurrency', 'login', '192.0.2.1'),
        'account',
        $arg1,
        120,
    ),
    'risk-hash-slot' => new RiskHashBulkhead(new RiskAuthority($cache, str_repeat('k', 32), shared: true), $arg1, 2, nonEvictingIsolatedPool: true)->acquire() !== null,
    default => throw new InvalidArgumentException('Unsupported CacheLayer atomic worker mode.'),
};

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);

<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $mode, $namespace, $dsn, $arg1, $arg2] = $argv + [null, null, null, null, null, null];
if (!is_string($mode) || !is_string($namespace) || !is_string($dsn) || !is_string($arg1)) {
    throw new InvalidArgumentException('Invalid CacheLayer atomic worker arguments.');
}

$cache = Cache::redis(
    namespace: $namespace,
    dsn: $dsn,
    options: new CacheOptions(failOpen: false),
);

$result = match ($mode) {
    'claim' => (new CacheLayerWebhookReplayStore($cache))->claim('concurrency', $arg1, 30),
    'pull' => (new CacheLayerTtlStore($cache))->pull($arg1, '__miss__'),
    'cas' => $cache->atomic()?->compareAndSet($arg1, 0, 1, 30) ?? false,
    default => throw new InvalidArgumentException('Unsupported CacheLayer atomic worker mode.'),
};

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);

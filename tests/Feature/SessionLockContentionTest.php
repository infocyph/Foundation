<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Session\SessionConfig;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Foundation\Session\SessionPayload;
use Infocyph\Foundation\Session\SessionStoreInterface;
use Infocyph\Foundation\Session\Store\ArraySessionStore;

it('enforces browser-session contention through every configured shared lock backend', function (Closure $providers): void {
    $pair = $providers();
    if ($pair === null) {
        test()->markTestSkipped('The live lock backend is not configured in this environment.');
    }

    [$holder, $contender] = $pair;
    $id = bin2hex(random_bytes(32));
    $key = hash('sha256', 'foundation-session:' . $id);
    $held = $holder->acquire($key, 0.0, 5.0);
    expect($held)->not->toBeNull();

    $config = SessionConfig::fromRepository(new ConfigRepository([
        'session' => [
            'driver' => 'array',
            'lock' => [
                'enabled' => true,
                'wait' => 0.01,
                'lease' => 5.0,
            ],
        ],
    ]), sys_get_temp_dir() . '/foundation-browser-sessions');
    $store = new ArraySessionStore();
    $store->save($id, new SessionPayload(['value' => 1], [], time() + 60));
    $manager = new SessionManager(
        $config,
        static fn(): SessionStoreInterface => $store,
        static fn(): LockProviderInterface => $contender,
    );

    try {
        expect(fn() => $manager->open($id)->get('value'))
            ->toThrow(RuntimeException::class, 'Timed out while waiting for the browser session lock.');
    } finally {
        $holder->release($held);
    }

    $session = $manager->open($id);
    try {
        expect($session->get('value'))->toBe(1);
    } finally {
        $session->release();
    }
})->with([
    'redis' => [static fn(): ?array => sessionRedisLockProviders('IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD')],
    'valkey' => [static fn(): ?array => sessionRedisLockProviders('IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD')],
    'memcached' => [static fn(): ?array => sessionMemcachedLockProviders()],
    'mysql-pdo' => [static fn(): ?array => sessionPdoLockProviders('IC_MYSQL_DSN', 'IC_MYSQL_USER', 'IC_MYSQL_PASSWORD')],
    'postgres-pdo' => [static fn(): ?array => sessionPdoLockProviders('IC_POSTGRES_DSN', 'IC_POSTGRES_USER', 'IC_POSTGRES_PASSWORD')],
]);

/**
 * @return array{LockProviderInterface, LockProviderInterface}|null
 */
function sessionRedisLockProviders(string $hostKey, string $portKey, string $passwordKey): ?array
{
    $host = getenv($hostKey);
    $port = getenv($portKey);
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        return null;
    }
    if (!class_exists(Redis::class)) {
        throw new RuntimeException('A configured Redis-compatible lock service requires the phpredis extension.');
    }

    $connect = static function () use ($host, $port, $passwordKey): Redis {
        $client = new Redis();
        if (!$client->connect($host, (int) $port, 0.5)) {
            throw new RuntimeException(sprintf('Unable to connect to the lock service at %s:%s.', $host, $port));
        }

        $password = getenv($passwordKey);
        if (is_string($password) && $password !== '' && !$client->auth($password)) {
            throw new RuntimeException('Unable to authenticate with the configured lock service.');
        }

        return $client;
    };

    return [
        new RedisLockProvider($connect(), 'foundation:test:'),
        new RedisLockProvider($connect(), 'foundation:test:'),
    ];
}

/**
 * @return array{LockProviderInterface, LockProviderInterface}|null
 */
function sessionMemcachedLockProviders(): ?array
{
    $host = getenv('IC_MEMCACHED_HOST');
    $port = getenv('IC_MEMCACHED_PORT');
    if (!is_string($host) || $host === '' || !is_string($port) || $port === '') {
        return null;
    }
    if (!class_exists(Memcached::class)) {
        throw new RuntimeException('A configured Memcached lock service requires the Memcached extension.');
    }

    $connect = static function () use ($host, $port): Memcached {
        $client = new Memcached();
        if (!$client->addServer($host, (int) $port)) {
            throw new RuntimeException(sprintf('Unable to configure Memcached at %s:%s.', $host, $port));
        }

        return $client;
    };

    return [
        new MemcachedLockProvider($connect(), 'foundation:test:'),
        new MemcachedLockProvider($connect(), 'foundation:test:'),
    ];
}

/**
 * @return array{LockProviderInterface, LockProviderInterface}|null
 */
function sessionPdoLockProviders(string $dsnKey, string $userKey, string $passwordKey): ?array
{
    $dsn = getenv($dsnKey);
    if (!is_string($dsn) || $dsn === '') {
        return null;
    }

    $username = getenv($userKey);
    $password = getenv($passwordKey);
    $connect = static fn(): PDO => new PDO(
        $dsn,
        is_string($username) ? $username : '',
        is_string($password) ? $password : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    return [
        new PdoLockProvider($connect(), 'foundation:test:'),
        new PdoLockProvider($connect(), 'foundation:test:'),
    ];
}

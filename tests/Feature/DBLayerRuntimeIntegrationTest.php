<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerMfaFactorStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasskeyCredentialStore;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

function foundationDbLayerRuntimeConfig(string $database = ':memory:'): ConnectionConfig
{
    return ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => $database,
        'statement_cache_enabled' => true,
        'statement_cache_size' => 16,
    ]);
}

/**
 * @param array<string,mixed> $database
 * @return array{DBLayerFactory,RuntimeExecutionState}
 */
function foundationDbLayerRuntimeFactory(array $database): array
{
    $state = new RuntimeExecutionState();
    $container = new class ($state) implements ContainerInterface {
        public function __construct(private RuntimeExecutionState $state) {}

        /**
         * @throws \RuntimeException
         */
        #[\Override]
        public function get(string $id): mixed
        {
            if ($id === RuntimeExecutionState::class) {
                return $this->state;
            }

            throw new RuntimeException(sprintf('Unknown test service "%s".', $id));
        }

        #[\Override]
        public function has(string $id): bool
        {
            return $id === RuntimeExecutionState::class;
        }
    };

    $resolver = new DatabaseConnectionResolver(new ConfigRepository([
        'app' => ['base_path' => sys_get_temp_dir()],
        'database' => $database,
    ]));

    return [new DBLayerFactory($resolver, $container), $state];
}

it('owns a DBLayer lease per Foundation execution and delegates rollback sanitation to DBLayer', function (): void {
    $file = sys_get_temp_dir() . '/foundation-dblayer-51-lease-' . bin2hex(random_bytes(6)) . '.sqlite';
    $config = foundationDbLayerRuntimeConfig($file);
    $pool = new Pool([
        'min_connections' => 1,
        'max_connections' => 2,
        'idle_timeout' => 0,
        'max_lifetime' => 0,
        'health_check_interval' => 0,
    ]);
    $pool->addConfig('default', $config);
    $manager = new PoolManager($pool);

    try {
        $setup = $manager->checkout('default');
        $setup->connection()->statement('CREATE TABLE lease_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $setup->release();

        $first = new RuntimeExecutionState();
        $firstConnection = $first->leasedConnection('default', $manager);
        $firstConnection->beginTransaction();
        $firstConnection->statement('INSERT INTO lease_items (id, value) VALUES (?, ?)', [1, 'uncommitted']);
        $first->cleanup();

        $second = new RuntimeExecutionState();
        $secondConnection = $second->leasedConnection('default', $manager);

        $leaseCount = $secondConnection->scalar('SELECT COUNT(*) FROM lease_items');
        if (!is_numeric($leaseCount)) {
            throw new \UnexpectedValueException('Leased connection must return a numeric row count.');
        }
        expect((int) $leaseCount)->toBe(0)
            ->and($pool->getStats()['active_connections'])->toBe(1)
            ->and($pool->getStats()['total_connections'])->toBeLessThanOrEqual(1);

        $second->cleanup();

        expect($pool->getStats()['active_connections'])->toBe(0)
            ->and($pool->getStats()['idle_connections'])->toBe(1);
    } finally {
        $pool->closeAll();
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('never exposes one active pooled connection to two interleaved Foundation executions', function (): void {
    $config = foundationDbLayerRuntimeConfig();
    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => 2,
        'idle_timeout' => 0,
        'max_lifetime' => 0,
        'health_check_interval' => 0,
    ]);
    $pool->addConfig('default', $config);
    $manager = new PoolManager($pool);
    $firstState = new RuntimeExecutionState();
    $secondState = new RuntimeExecutionState();

    $firstFiber = new Fiber(static function () use ($firstState, $manager): void {
        $connection = $firstState->leasedConnection('default', $manager);
        Fiber::suspend(spl_object_id($connection));
        $firstState->cleanup();
    });
    $secondFiber = new Fiber(static function () use ($secondState, $manager): void {
        $connection = $secondState->leasedConnection('default', $manager);
        Fiber::suspend(spl_object_id($connection));
        $secondState->cleanup();
    });

    $firstId = $firstFiber->start();
    $secondId = $secondFiber->start();

    expect($firstId)->not->toBe($secondId)
        ->and($pool->getStats()['active_connections'])->toBe(2);

    $firstFiber->resume();
    $secondFiber->resume();

    expect($pool->getStats()['active_connections'])->toBe(0)
        ->and($pool->getStats()['idle_connections'])->toBe(2);

    $pool->closeAll();
});

it('keeps fresh and infrastructure database connections outside the pooled execution lease', function (): void {
    [$factory, $state] = foundationDbLayerRuntimeFactory([
        'default' => 'default',
        'pool' => [
            'enabled' => true,
            'min_connections' => 0,
            'max_connections' => 2,
            'idle_timeout' => 0,
            'max_lifetime' => 0,
            'health_check_interval' => 0,
        ],
        'connections' => [
            'default' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ],
    ]);

    $leased = $factory->connection();
    $fresh = $factory->connection(fresh: true);
    $infrastructure = $factory->infrastructureConnection();

    expect($fresh)->not->toBe($leased)
        ->and($infrastructure)->not->toBe($leased)
        ->and($infrastructure)->not->toBe($fresh)
        ->and($state->hasDatabaseConnections())->toBeTrue();

    $state->cleanup();
});

it('rejects stale MFA and passkey credential replacements through DBLayer single-statement revision checks', function (): void {
    [$factory, $state] = foundationDbLayerRuntimeFactory([
        'default' => 'default',
        'connections' => [
            'default' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ],
    ]);
    $tables = new AuthTables();
    $schema = new AuthSchema($tables);
    $runner = new MigrationRunner($factory->connection(), [$schema]);
    $runner->run();

    $clock = new readonly class implements ClockInterface {
        #[\Override]
        public function now(): int
        {
            return 1_700_000_000;
        }
    };
    $mfa = new DBLayerMfaFactorStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables);
    $passkeys = new DBLayerPasskeyCredentialStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, $clock);

    $factor = new MfaFactor(
        id: 'factor-1',
        accountId: 'account-1',
        type: 'totp',
        label: 'Authenticator',
        enabled: false,
        createdAt: 1_700_000_000,
    );
    expect($mfa->compareAndSwap(null, $factor))->toBeTrue();

    $factorV1 = $factor->activated();
    $factorStaleV1 = $factor->withMetadata(['stale' => true]);
    expect($mfa->compareAndSwap($factor, $factorV1))->toBeTrue()
        ->and($mfa->compareAndSwap($factor, $factorStaleV1))->toBeFalse();

    $credential = new PasskeyCredential(
        id: 'passkey-1',
        accountId: 'account-1',
        credentialId: 'credential-1',
        credentialRecordJson: null,
        publicKey: 'public-key',
        signCount: 0,
        transports: ['internal'],
        createdAt: 1_700_000_000,
    );
    expect($passkeys->compareAndSwap(null, $credential))->toBeTrue();

    $credentialV1 = $credential->used(1, 1_700_000_001);
    $credentialStaleV1 = $credential->used(2, 1_700_000_002);
    expect($passkeys->compareAndSwap($credential, $credentialV1))->toBeTrue()
        ->and($passkeys->compareAndSwap($credential, $credentialStaleV1))->toBeFalse()
        ->and($passkeys->findByCredentialId('credential-1')?->signCount)->toBe(1)
        ->and($passkeys->findByCredentialId('credential-1')?->revision)->toBe(1);

    $state->cleanup();
});

it('reuses a pooled DBLayer connection after failed SQL with retained exception and rolled-back work', function (): void {
    $file = sys_get_temp_dir() . '/foundation-dblayer-failure-' . bin2hex(random_bytes(6)) . '.sqlite';
    $pool = new Pool([
        'min_connections' => 1,
        'max_connections' => 1,
        'idle_timeout' => 0,
        'max_lifetime' => 0,
        'health_check_interval' => 0,
    ]);
    $pool->addConfig('default', foundationDbLayerRuntimeConfig($file));
    $manager = new PoolManager($pool);
    $failure = null;

    try {
        $setup = $manager->checkout('default');
        $setup->connection()->statement('CREATE TABLE rollback_probe (id INTEGER PRIMARY KEY, note TEXT)');
        $setup->release();

        $first = new RuntimeExecutionState();
        $connection = $first->leasedConnection('default', $manager);
        $connection->beginTransaction();
        $connection->statement('INSERT INTO rollback_probe (id, note) VALUES (?, ?)', [1, 'pending']);
        try {
            $connection->statement('INSERT INTO rollback_probe (id, note) VALUES (?, ?)', [1, 'duplicate']);
        } catch (\Infocyph\DBLayer\Exceptions\ConnectionException $exception) {
            $failure = $exception;
        }
        expect($failure)->toBeInstanceOf(\Infocyph\DBLayer\Exceptions\ConnectionException::class);
        $first->cleanup();

        $next = new RuntimeExecutionState();
        $reused = $next->leasedConnection('default', $manager);
        expect((int) $reused->scalar('SELECT COUNT(*) FROM rollback_probe'))->toBe(0)
            ->and($pool->getStats()['active_connections'])->toBe(1);

        $reused->statement('INSERT INTO rollback_probe (id, note) VALUES (?, ?)', [2, 'committed']);
        expect((int) $reused->scalar('SELECT COUNT(*) FROM rollback_probe'))->toBe(1);
        $next->cleanup();

        expect($pool->getStats()['active_connections'])->toBe(0)
            ->and($pool->getStats()['idle_connections'])->toBe(1)
            ->and($failure)->toBeInstanceOf(\Infocyph\DBLayer\Exceptions\ConnectionException::class);
    } finally {
        $pool->closeAll();
        if (is_file($file)) {
            unlink($file);
        }
    }
});

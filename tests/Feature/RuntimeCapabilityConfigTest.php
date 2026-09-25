<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Foundation;

it('validates migration, messaging, logging, and JsonDispatch configuration before runtime', function (): void {
    $app = Foundation::cli([
        'database' => [
            'migrations' => [
                'classes' => ['Missing\\Migration'],
                'table' => 'unsafe-name',
                'lock_wait_seconds' => -1,
                'lock_lease_seconds' => 0,
            ],
            'seeders' => ['Missing\\Seeder'],
        ],
        'logging' => [
            'driver' => 'file',
            'path' => '',
            'exceptions' => [
                'ignore' => ['Missing\\Exception'],
                'sample_rate' => 2,
                'throttle_seconds' => -1,
                'throttle_limit' => 0,
            ],
        ],
        'messaging' => [
            'default_route' => [
                'transport' => '',
                'queue' => '',
                'delay_seconds' => -1,
            ],
            'handlers' => 'invalid',
            'listeners' => 'invalid',
            'routes' => 'invalid',
            'scheduled_messages' => 'invalid',
            'consumer' => ['transport' => ''],
            'retry' => [
                'maximum_attempts' => 0,
                'initial_delay_seconds' => -1,
                'multiplier' => 0.5,
                'maximum_delay_seconds' => -1,
                'jitter_ratio' => 2,
            ],
            'forward_auth_events' => 'yes',
        ],
        'responses' => [
            'json_dispatch' => [
                'vendor' => 'Invalid Vendor',
                'application_version' => '',
                'tunnel_errors' => 'yes',
            ],
        ],
    ]);

    $issues = new ConfigValidator($app->config())->validate()->toArray()['issues'];
    $keys = array_column($issues, 'key');

    expect($keys)->toContain(
        'database.migrations.classes',
        'database.migrations.table',
        'database.migrations.lock_wait_seconds',
        'database.migrations.lock_lease_seconds',
        'database.seeders',
        'logging.path',
        'logging.exceptions.ignore',
        'logging.exceptions.sample_rate',
        'logging.exceptions.throttle_seconds',
        'logging.exceptions.throttle_limit',
        'messaging.default_route.transport',
        'messaging.default_route.queue',
        'messaging.default_route.delay_seconds',
        'messaging.handlers',
        'messaging.listeners',
        'messaging.routes',
        'messaging.scheduled_messages',
        'messaging.consumer.transport',
        'messaging.retry.maximum_attempts',
        'messaging.retry.initial_delay_seconds',
        'messaging.retry.multiplier',
        'messaging.retry.maximum_delay_seconds',
        'messaging.retry.jitter_ratio',
        'messaging.forward_auth_events',
        'responses.json_dispatch.vendor',
        'responses.json_dispatch.application_version',
        'responses.json_dispatch.tunnel_errors',
    );
});

it('accepts the default configuration for new runtime capabilities', function (): void {
    $application = Foundation::cli();
    $issueKeys = array_column(
        new ConfigValidator($application->config())->validate()->toArray()['issues'],
        'key',
    );
    $readiness = new ReadinessReport($application)->generate();

    expect($issueKeys)->not->toContain(
        'database.migrations.classes',
        'database.seeders',
        'logging.driver',
        'messaging.default_route',
        'responses.json_dispatch.vendor',
    )->and($readiness)->toHaveKeys(['ready', 'checks'])
        ->and($readiness['checks'])->toHaveKeys(['php', 'base_path', 'storage', 'runtime'])
        ->and($readiness['checks']['runtime']['detail'])->toBe('cli');
});

it('distinguishes inferred cache activation from an explicit cold topology', function (): void {
    $inferred = Foundation::cli();
    $explicit = Foundation::cli([
        'app' => [
            'capabilities' => [],
        ],
    ]);

    $inferredCapabilities = new ConfiguredCapabilities($inferred->config());
    $explicitCapabilities = new ConfiguredCapabilities($explicit->config());

    expect($inferredCapabilities->explicit())->toBeFalse()
        ->and($inferredCapabilities->enabled('cache'))->toBeTrue()
        ->and($explicitCapabilities->explicit())->toBeTrue()
        ->and($explicitCapabilities->enabled('cache'))->toBeFalse();
});

it('does not apply inactive optional auth production policy to an explicit lean topology', function (): void {
    $application = Foundation::cli([
        'app' => [
            'env' => 'production',
            'capabilities' => [],
        ],
    ]);

    $validation = new ConfigValidator($application->config())->validateForProduction();
    $readiness = new ReadinessReport($application)->generate();
    $keys = array_column($validation->toArray()['issues'], 'key');

    expect($keys)->not->toContain(
        'auth.drivers.tokens',
        'auth.drivers.storage',
        'auth.drivers.mfa',
        'auth.drivers.notifications',
        'auth.token_secret',
        'auth.drivers.cache',
        'cache.default_counter',
    )->and($readiness['checks']['configuration']['ready'])->toBeTrue()
        ->and(array_keys($readiness['checks']))->not->toContain(
            'module:auth',
            'schema:auth',
        );
});

it('preserves strict auth production policy when auth is explicitly selected', function (): void {
    $config = new ConfigRepository([
        'app' => [
            'env' => 'production',
            'capabilities' => ['auth'],
        ],
    ]);

    $keys = array_column(
        new ConfigValidator($config)->validateForProduction()->toArray()['issues'],
        'key',
    );

    expect($keys)->toContain(
        'auth.drivers.tokens',
        'auth.drivers.storage',
        'auth.drivers.mfa',
        'auth.drivers.notifications',
    );
});


it('requires an atomic counter when auth uses shared cache state', function (): void {
    $config = new ConfigRepository([
        'app' => [
            'capabilities' => ['auth', 'cache'],
        ],
        'auth' => [
            'drivers' => [
                'cache' => 'cache',
            ],
        ],
        'cache' => [
            'default' => 'memory',
            'stores' => [
                'memory' => [
                    'driver' => 'memory',
                ],
            ],
            'counters' => [],
        ],
    ]);

    $keys = array_column(
        new ConfigValidator($config)->validate()->toArray()['issues'],
        'key',
    );

    expect($keys)->toContain('cache.default_counter');
});


it('selects the atomic auth counter adapter when shared auth cache is configured', function (): void {
    $application = Foundation::cli([
        'app' => [
            'capabilities' => ['auth', 'cache'],
        ],
        'auth' => [
            'drivers' => [
                'cache' => 'cache',
            ],
        ],
        'cache' => [
            'default' => 'memory',
            'default_counter' => 'auth-lockouts',
            'stores' => [
                'memory' => [
                    'driver' => 'memory',
                ],
            ],
            'counters' => [
                'auth-lockouts' => [
                    'driver' => 'redis',
                    'client' => new Redis(),
                ],
            ],
        ],
    ]);

    expect($application->make(CounterStoreInterface::class))
        ->toBeInstanceOf(AtomicCounterStore::class);
});

it('fails composition when shared auth cache has no atomic counter selection', function (): void {
    expect(fn() => Foundation::cli([
        'app' => [
            'capabilities' => ['auth', 'cache'],
        ],
        'auth' => [
            'drivers' => [
                'cache' => 'cache',
            ],
        ],
        'cache' => [
            'default' => 'memory',
            'stores' => [
                'memory' => [
                    'driver' => 'memory',
                ],
            ],
            'counters' => [],
        ],
    ]))->toThrow(
        LogicException::class,
        'Cache-backed authentication requires cache.default_counter to select an atomic counter resource.',
    );
});

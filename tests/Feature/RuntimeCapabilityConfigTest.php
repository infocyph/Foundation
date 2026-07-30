<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

it('validates migration, messaging, logging, and JsonDispatch configuration before runtime', function (): void {
    $app = Foundation::console([
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

    $issues = $app->validateConfiguration()->toArray()['issues'];
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
    $application = Foundation::console();
    $issueKeys = array_column($application->validateConfiguration()->toArray()['issues'], 'key');
    $readiness = $application->readinessReport();

    expect($issueKeys)->not->toContain(
        'database.migrations.classes',
        'database.seeders',
        'logging.driver',
        'messaging.default_route',
        'responses.json_dispatch.vendor',
    )->and($readiness)->toHaveKeys([
        'logging',
        'messaging',
        'migrations',
        'modules',
        'optimization',
        'resources',
        'sessions',
    ])->and($readiness['messaging']['configured'])->toBeFalse()
        ->and($readiness['migrations']['pending'])->toBe([])
        ->and($readiness['sessions']['configured'])->toBeFalse();
});

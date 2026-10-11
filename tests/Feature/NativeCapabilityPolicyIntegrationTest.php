<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Session\SessionDatabaseSchema;
use Infocyph\Foundation\Session\SessionManager;

it('rejects cache-backed browser sessions when cache is outside the declared graph', function (): void {
    expect(static fn() => Foundation::web([
        'app' => ['capabilities' => ['session']],
        'session' => ['driver' => 'cache'],
    ]))->toThrow(LogicException::class, 'Cache-backed sessions require the Foundation cache capability.');
});

it('rejects locked browser sessions when cache is outside the declared graph', function (): void {
    expect(static fn() => Foundation::web([
        'app' => ['capabilities' => ['session']],
        'session' => ['driver' => 'array', 'lock' => ['enabled' => true]],
    ]))->toThrow(LogicException::class, 'Session locking requires the Foundation cache capability.');
});

it('rejects database-backed browser sessions when database is outside the declared graph', function (): void {
    expect(static fn() => Foundation::web([
        'app' => ['capabilities' => ['session']],
        'session' => ['driver' => 'database'],
    ]))->toThrow(LogicException::class, 'Database-backed sessions require the Foundation database capability.');
});

it('rejects protected inbound webhooks when the cache capability is disabled', function (): void {
    expect(static fn() => Foundation::web([
        'app' => ['capabilities' => ['communication']],
        'communication' => [
            'webhooks' => [
                'inbound' => [
                    'default' => ['replay' => ['enabled' => true]],
                ],
            ],
        ],
    ]))->toThrow(LogicException::class, 'Webhook replay protection requires the Foundation cache capability.');
});

it('allows browser database sessions with the database provider declared before session registration', function (): void {
    $app = Foundation::web([
        'app' => ['capabilities' => ['session', 'database']],
        'session' => ['driver' => 'database'],
        'database' => [
            'default' => 'primary',
            'connections' => ['primary' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ]);

    expect($app->make(SessionDatabaseSchema::class))->toBeInstanceOf(SessionDatabaseSchema::class)
        ->and($app->make(SessionManager::class))->toBeInstanceOf(SessionManager::class);
});

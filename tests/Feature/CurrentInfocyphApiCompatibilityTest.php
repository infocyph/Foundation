<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;

it('accepts DBLayer 4.1 SQL Server connection configuration', function (): void {
    if (!class_exists(ConnectionConfig::class)) {
        $this->markTestSkipped('DBLayer is an optional Foundation integration.');
    }

    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlsrv',
        'host' => '127.0.0.1',
        'port' => 1433,
        'database' => 'foundation',
        'username' => 'foundation',
        'password' => 'secret',
        'encrypt' => true,
        'trust_server_certificate' => false,
        'application_intent' => 'ReadWrite',
    ]);

    expect($config)->toBeInstanceOf(ConnectionConfig::class);
});

it('targets the current Omnibus worker lifecycle', function (): void {
    if (!class_exists(Worker::class)) {
        $this->markTestSkipped('Omnibus is an optional Foundation integration.');
    }

    expect(class_exists(WorkerOptions::class))->toBeTrue()
        ->and(class_exists(WorkerPool::class))->toBeTrue();

    $options = new WorkerOptions(
        queue: 'default',
        prefetch: 1,
        visibilitySeconds: 60.0,
        idleSleepSeconds: 0.01,
        maxIdleSleepSeconds: 0.1,
        idleJitterRatio: 0.0,
        maxMessages: 1,
        handleSignals: false,
    );

    expect($options->queue)->toBe('default')
        ->and($options->maxMessages)->toBe(1);
});

it('keeps Omnibus process-pool extensions optional', function (): void {
    if (!class_exists(WorkerPool::class)) {
        $this->markTestSkipped('The current Omnibus WorkerPool API is unavailable.');
    }

    $reflection = new ReflectionClass(WorkerPool::class);

    expect($reflection->getConstructor())->not->toBeNull();
});

<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\NativeWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\FailureRetryClaim;
use Infocyph\Runwire\Http\Client\ClientDestinationPolicy;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('accepts DBLayer 6 SQL Server connection configuration', function (): void {
    expect(class_exists(ConnectionConfig::class))->toBeTrue();

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

    expect($config::class)->toBe(ConnectionConfig::class);
});

it('targets DBLayer 6 bind sizing and monitoring surfaces', function (): void {
    expect(class_exists(Connection::class))->toBeTrue();

    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => ['max_params' => 7],
    ]));

    try {
        expect($connection->effectiveMaxBindParameters())->toBe(7)
            ->and($connection->safeBatchSize(parametersPerRow: 1, fixedBindings: 2, requested: 1_000))->toBe(5)
            ->and((new DatabaseMonitor($connection))->status())->toMatchArray([
                'driver' => 'sqlite',
                'database' => ':memory:',
            ]);
    } finally {
        $connection->disconnect();
    }
});

it('targets the Omnibus 3 worker and failure lifecycle', function (): void {
    expect(class_exists(Worker::class))->toBeTrue();

    expect(class_exists(WorkerOptions::class))->toBeTrue()
        ->and(class_exists(WorkerPool::class))->toBeTrue()
        ->and(class_exists(FailureManager::class))->toBeTrue()
        ->and(class_exists(FailureRetryClaim::class))->toBeTrue();

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

it('targets the Omnibus 3 pool backend and lifecycle surface', function (): void {
    expect(class_exists(WorkerPool::class))->toBeTrue()
        ->and(class_exists(NativeWorkerPoolBackend::class))->toBeTrue()
        ->and(interface_exists(WorkerLifecycle::class))->toBeTrue();

    $constructor = new ReflectionClass(WorkerPool::class)->getConstructor();
    if ($constructor === null) {
        throw new \RuntimeException('Omnibus WorkerPool constructor is missing.');
    }

    $parameters = array_map(
        static fn(ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor->getParameters(),
    );

    expect($parameters)->toContain('backend')
        ->and($parameters)->toContain('lifecycle')
        ->and($parameters)->toContain('lifecycleIntervalSeconds');
});

it('requires released Webrick 6.1 and Runwire 3.1 from the installed Composer graph', function (): void {
    $webrick = InstalledVersions::getVersion('infocyph/webrick');
    $runwire = InstalledVersions::getVersion('infocyph/runwire');

    expect(is_string($webrick))->toBeTrue()
        ->and(is_string($runwire))->toBeTrue();

    if (!is_string($webrick) || !is_string($runwire)) {
        throw new RuntimeException('Released runtime dependencies were not installed.');
    }

    expect(version_compare($webrick, '6.1', '>='))->toBeTrue()
        ->and(version_compare($webrick, '7.0', '<'))->toBeTrue()
        ->and(version_compare($runwire, '3.1', '>='))->toBeTrue()
        ->and(version_compare($runwire, '4.0', '<'))->toBeTrue();
});

it('keeps published risk admission opt-in and outbound challenge destinations exact', function (): void {
    $evaluations = 0;
    $middleware = new RiskAdmissionMiddleware(
        static function (Request $request) use (&$evaluations): RiskDecision {
            ++$evaluations;

            return RiskDecision::allow();
        },
    );
    $response = $middleware(
        Request::fake(),
        static fn(Request $request): Response => Response::plaintext('ok', 200),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($evaluations)->toBe(1);

    $uri = 'https://challenge.example.test/verify?tenant=alpha';
    $destinations = new ClientDestinationPolicy([$uri]);

    expect($destinations->authorize($uri))->toBe($uri);
    expect(static fn(): string => $destinations->authorize('https://challenge.example.test/verify?tenant=beta'))
        ->toThrow(InvalidArgumentException::class);
});

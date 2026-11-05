<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodec;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;

final readonly class FoundationOmnibusDurableMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationOmnibusDurableMessageCodec implements MessageCodec
{
    public function alias(): string
    {
        return 'foundation.omnibus26.durable-message.v1';
    }

    public function decode(array $payload): object
    {
        $value = $payload['value'] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException('Durable message value must be a string.');
        }

        return new FoundationOmnibusDurableMessage($value);
    }

    public function encode(object $message): array
    {
        if (!$message instanceof FoundationOmnibusDurableMessage) {
            throw new InvalidArgumentException('Unexpected durable message type.');
        }

        return ['value' => $message->value];
    }

    public function type(): string
    {
        return FoundationOmnibusDurableMessage::class;
    }
}

final class FoundationOmnibusDurableHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public static int $transientAttempts = 0;

    public static ?RequestContext $cancelDuringDelivery = null;

    public function __invoke(FoundationOmnibusDurableMessage $message): void
    {
        if ($message->value === 'cancel-inflight' && self::$cancelDuringDelivery !== null) {
            $request = self::$cancelDuringDelivery;
            self::$cancelDuringDelivery = null;
            $request->cancel(CancellationReason::HOST_CANCELLED);
            $request->cancellation->throwIfCancelled();
        }
        if ($message->value === 'transient' && ++self::$transientAttempts === 1) {
            throw new RuntimeException('expected transient durable handler failure');
        }
        if ($message->value === 'fail') {
            throw new RuntimeException('expected durable handler failure');
        }

        self::$handled[] = $message->value;
    }
}

beforeEach(function (): void {
    FoundationOmnibusDurableHandler::$handled = [];
    FoundationOmnibusDurableHandler::$transientAttempts = 0;
    FoundationOmnibusDurableHandler::$cancelDuringDelivery = null;
});

it('keeps the established serialized message alias readable after the library upgrade', function (): void {
    expect(new FoundationOmnibusDurableMessageCodec()->alias())->toBe('foundation.omnibus26.durable-message.v1');
});

it('composes current Omnibus DBLayer transport, failure store and schema only when enabled', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $schema = $app->make(MessagingDatabaseSchema::class);
        expect($schema->readiness()['installed'])->toBeFalse();

        $schema->install();

        expect($schema->readiness()['installed'])->toBeTrue()
            ->and($app->make(DBLayerTransport::class))->toBeInstanceOf(DBLayerTransport::class)
            ->and($app->make(DBLayerFailureStore::class))->toBeInstanceOf(DBLayerFailureStore::class)
            ->and($app->make(EnvelopeSerializer::class))->toBeInstanceOf(EnvelopeSerializer::class);

        $bus = $app->make(MessageBus::class);
        $transport = $app->make(DBLayerTransport::class);

        $bus->dispatch(new FoundationOmnibusDurableMessage('handled'));
        expect($transport->size('work'))->toBe(1);

        $app->make(ConsumerFactory::class)->make('database')->run('work');

        expect($transport->size('work'))->toBe(0)
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe(['handled']);

        $bus->dispatch(new FoundationOmnibusDurableMessage('fail'));
        $app->make(ConsumerFactory::class)->make('database')->run('work');

        $failures = $app->make(DBLayerFailureStore::class)->all();
        expect($failures)->toHaveCount(1)
            ->and($failures[0]->envelope?->message)->toEqual(
                new FoundationOmnibusDurableMessage('fail'),
            );
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('preserves a durable reservation for the next consumer after host cancellation', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);
        $consumer = $app->make(ConsumerFactory::class)->make('database');

        $app->make(MessageBus::class)->dispatch(new FoundationOmnibusDurableMessage('after-cancellation'));
        expect($transport->size('work'))->toBe(1);

        $runtime = RuntimeContext::standalone();
        $request = RequestContext::create($runtime);
        CacheRunwire::bind($runtime);
        try {
            CacheRunwire::share($request, null, static function () use ($consumer, $request): void {
                $request->cancel(CancellationReason::HOST_CANCELLED);
                expect(fn() => $consumer->run('work'))->toThrow(CancelledException::class);
            }, $runtime);
        } finally {
            $request->complete();
            CacheRunwire::release($runtime);
        }

        expect($transport->size('work'))->toBe(1)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe([]);

        $result = $consumer->run('work');

        expect($result->succeeded)->toBe(1)
            ->and($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe(['after-cancellation']);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('recovers an in-flight host-cancelled durable message after its original visibility lease expires', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);
        $connection = $app->make(DBLayerFactory::class)->infrastructureConnection('main');
        $consumer = $app->make(ConsumerFactory::class)->make('database');

        $app->make(MessageBus::class)->dispatch(new FoundationOmnibusDurableMessage('cancel-inflight'));
        $runtime = RuntimeContext::standalone();
        $request = RequestContext::create($runtime);
        FoundationOmnibusDurableHandler::$cancelDuringDelivery = $request;
        CacheRunwire::bind($runtime);

        try {
            CacheRunwire::share($request, null, static function () use ($consumer): void {
                expect(fn() => $consumer->run('work'))->toThrow(CancelledException::class);
            }, $runtime);
        } finally {
            FoundationOmnibusDurableHandler::$cancelDuringDelivery = null;
            $request->complete();
            CacheRunwire::release($runtime);
        }

        // Omnibus leaves an in-flight cancelled delivery leased until visibility
        // expiry. It must not acknowledge it or turn host cancellation into a
        // permanent delivery failure.
        expect((int) $connection->scalar('SELECT COUNT(*) FROM omnibus_messages'))->toBe(1)
            ->and((int) $connection->scalar('SELECT attempts FROM omnibus_messages'))->toBe(1)
            ->and($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe([]);

        $connection->update('UPDATE omnibus_messages SET reserved_until = 0 WHERE reserved_until IS NOT NULL');

        $recovered = $consumer->run('work');

        expect($recovered->received)->toBe(1)
            ->and($recovered->succeeded)->toBe(1)
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe(['cancel-inflight'])
            ->and($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and((int) $connection->scalar('SELECT COUNT(*) FROM omnibus_messages'))->toBe(0)
            ->and($consumer->run('work')->received)->toBe(0);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('keeps a configured durable worker on its selected transport and recovers after terminal host cancellation', function (
    CancellationReason $reason,
): void {
    [$app, $database] = foundationOmnibusDurableApp(workers: [
        'durable' => [
            'transport' => 'database',
            'queue' => 'work',
            'prefetch' => 1,
            'max_messages' => 1,
            'handle_signals' => false,
            'pool' => ['enabled' => false],
        ],
    ]);

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $factory = $app->make(OmnibusWorkerFactory::class);
        $worker = $factory->make('durable');
        $transport = $app->make(DBLayerTransport::class);

        expect($factory->transport('durable'))->toBe('database')
            ->and($factory->pool('durable')['enabled'])->toBeFalse()
            ->and($worker->runwireBinding()->request())->toBeNull();

        $app->make(MessageBus::class)->dispatch(new FoundationOmnibusDurableMessage('worker-delivery'));
        $runtime = RuntimeContext::standalone();
        $request = RequestContext::create($runtime);
        CacheRunwire::bind($runtime);

        try {
            CacheRunwire::share($request, null, static function () use ($worker, $request, $reason): void {
                expect($worker->runwireBinding()->request())->toBe($request);
                $request->cancel($reason);
                expect(fn() => $worker->run())->toThrow(CancelledException::class);
            }, $runtime);
        } finally {
            $request->complete();
            CacheRunwire::release($runtime);
        }

        expect($worker->runwireBinding()->request())->toBeNull()
            ->and($transport->size('work'))->toBe(1)
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe([])
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([]);

        // Reuse the same native Worker after the host-owned request has ended.
        $worker->run();

        expect(FoundationOmnibusDurableHandler::$handled)->toBe(['worker-delivery'])
            ->and($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and($worker->runwireBinding()->request())->toBeNull();
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
})->with([
    'host cancelled' => [CancellationReason::HOST_CANCELLED],
    'deadline exceeded' => [CancellationReason::DEADLINE_EXCEEDED],
]);

it('retries a transient durable delivery once without losing or duplicating the message', function (): void {
    [$app, $database] = foundationOmnibusDurableApp(retryMaximumAttempts: 2);

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);
        $connection = $app->make(DBLayerFactory::class)->infrastructureConnection('main');
        $consumer = $app->make(ConsumerFactory::class)->make('database');

        $app->make(MessageBus::class)->dispatch(new FoundationOmnibusDurableMessage('transient'));

        $first = $consumer->run('work');
        expect($first->received)->toBe(1)
            ->and($first->released)->toBe(1)
            ->and($first->succeeded)->toBe(0)
            ->and($first->failed)->toBe(0)
            ->and($transport->size('work'))->toBe(1)
            ->and((int) $connection->scalar('SELECT attempts FROM omnibus_messages'))->toBe(1)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe([]);

        $second = $consumer->run('work');
        expect($second->received)->toBe(1)
            ->and($second->succeeded)->toBe(1)
            ->and($second->released)->toBe(0)
            ->and($second->failed)->toBe(0)
            ->and(FoundationOmnibusDurableHandler::$transientAttempts)->toBe(2)
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe(['transient'])
            ->and($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([])
            ->and((int) $connection->scalar('SELECT COUNT(*) FROM omnibus_messages'))->toBe(0);

        expect($consumer->run('work')->received)->toBe(0)
            ->and(FoundationOmnibusDurableHandler::$handled)->toBe(['transient']);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('rejects a stale durable lease receipt after a newer consumer reclaims the message', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);
        $connection = $app->make(DBLayerFactory::class)->infrastructureConnection('main');

        $app->make(MessageBus::class)->dispatch(new FoundationOmnibusDurableMessage('reclaimed'));
        $stale = [...$transport->receive('work')][0];
        expect($transport->size('work'))->toBe(0);

        // Model lease expiration deterministically without sleeping or altering the receipt.
        [, $staleToken] = ReservationReceipt::decode($stale->receipt);
        $connection->update(
            'UPDATE omnibus_messages SET reserved_until = 0 WHERE receipt = ?',
            [$staleToken],
        );
        $current = [...$transport->receive('work')][0];

        expect($current->receipt)->not->toBe($stale->receipt)
            ->and($current->attempt)->toBe($stale->attempt + 1)
            ->and(fn() => $transport->acknowledge($stale))->toThrow(InvalidReservation::class)
            ->and(fn() => $transport->release($stale))->toThrow(InvalidReservation::class)
            ->and((int) $connection->scalar('SELECT COUNT(*) FROM omnibus_messages'))->toBe(1);

        $transport->acknowledge($current);

        expect((int) $connection->scalar('SELECT COUNT(*) FROM omnibus_messages'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toBe([]);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('reads existing serialized queue rows through the current DB transport', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $serializer = $app->make(EnvelopeSerializer::class);
        $payload = $serializer->encode(new Envelope(
            new FoundationOmnibusDurableMessage('legacy'),
        ));
        $connection = $app->make(DBLayerFactory::class)->infrastructureConnection('main');
        $connection->insert(
            'INSERT INTO omnibus_messages (id, message_id, queue_name, payload, available_at, attempts, reserved_until, receipt, created_at) VALUES (?, ?, ?, ?, ?, 0, NULL, NULL, ?)',
            [
                '01LEGACY000000000000000000',
                'legacy-message',
                'work',
                $payload,
                0,
                0,
            ],
        );

        $reservation = [...$app->make(DBLayerTransport::class)->receive('work')][0];

        expect($reservation->envelope()->message)->toEqual(
            new FoundationOmnibusDurableMessage('legacy'),
        );
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('binds Omnibus after-commit dispatch to the current Foundation execution connection', function (): void {
    [$app, $database] = foundationOmnibusDurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);

        $app->execution()->run(function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection('main');
            $dispatcher = $app->make(AfterCommitDispatcher::class);

            $connection->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new FoundationOmnibusDurableMessage('after-commit'));
            });
        });

        expect($transport->size('work'))->toBe(1);

        expect(fn() => $app->execution()->run(function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection('main');
            $dispatcher = $app->make(AfterCommitDispatcher::class);

            $connection->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new FoundationOmnibusDurableMessage('rolled-back'));

                throw new RuntimeException('rollback');
            });
        }))->toThrow(RuntimeException::class, 'rollback')
            ->and($transport->size('work'))->toBe(1);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('requires an explicit failure-store policy for durable consumers', function (): void {
    $database = foundationOmnibusDatabasePath();

    try {
        expect(fn() => Foundation::worker([
            'database' => foundationOmnibusDatabaseConfig($database),
            'messaging' => [
                'durable' => [
                    'enabled' => true,
                    'connection' => 'main',
                ],
                'consumer' => ['transport' => 'database'],
            ],
        ]))->toThrow(
            ConfigurationException::class,
            'Durable database consumers/workers require an explicit messaging.durable.failure_store policy.',
        );
    } finally {
        foundationOmnibusRemoveDatabase($database);
    }
});

it('keeps the DBLayer durable graph cold when durable messaging is disabled', function (): void {
    $observer = new class extends ServiceProvider {
        /** @var array<string, bool> */
        public array $resolved = [];

        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);
            foreach ([DBLayerFactory::class, DBLayerTransport::class] as $id) {
                $builder->onResolved($id, function (string $resolved): void {
                    $this->resolved[$resolved] = true;
                });
            }
        }
    };
    $app = Foundation::worker([
        'providers' => ['common' => [$observer]],
        'messaging' => [
            'default_route' => ['transport' => 'sync', 'queue' => 'default'],
            'routes' => [],
            'handlers' => [],
            'workers' => [],
        ],
    ]);

    expect($observer->resolved)->toBe([])
        ->and($app->has(DBLayerTransport::class))->toBeFalse();

    $app->make(MessageBus::class);

    expect($observer->resolved)->toBe([])
        ->and($app->has(DBLayerTransport::class))->toBeFalse();
});

/** @return array{0:\Infocyph\Foundation\Application\Application,1:string} */
function foundationOmnibusDurableApp(?array $selectedFeatures = null, int $retryMaximumAttempts = 1, ?array $workers = null): array
{
    $database = foundationOmnibusDatabasePath();
    $config = [
        'database' => foundationOmnibusDatabaseConfig($database),
        'messaging' => [
            'durable' => [
                'enabled' => true,
                'connection' => 'main',
                'failure_store' => 'database',
            ],
            'serialization' => [
                'message_codecs' => [FoundationOmnibusDurableMessageCodec::class],
            ],
            'default_route' => [
                'transport' => 'database',
                'queue' => 'work',
            ],
            'routes' => [
                FoundationOmnibusDurableMessage::class => [
                    'transport' => 'database',
                    'queue' => 'work',
                ],
            ],
            'handlers' => [
                FoundationOmnibusDurableMessage::class => FoundationOmnibusDurableHandler::class,
            ],
            'consumer' => ['transport' => 'database'],
            'retry' => [
                'maximum_attempts' => $retryMaximumAttempts,
                'initial_delay_seconds' => 0.0,
                'multiplier' => 1.0,
                'maximum_delay_seconds' => 0.0,
                'jitter_ratio' => 0.0,
            ],
            'workers' => $workers ?? [],
        ],
    ];
    if ($selectedFeatures !== null) {
        $config['modules'] = ['features' => $selectedFeatures];
    }
    $app = Foundation::worker($config)->boot();

    return [$app, $database];
}

/** @return array<string,mixed> */
function foundationOmnibusDatabaseConfig(string $database): array
{
    return [
        'default' => 'main',
        'connections' => [
            'main' => [
                'driver' => 'sqlite',
                'database' => $database,
            ],
        ],
    ];
}

function foundationOmnibusDatabasePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'foundation-omnibus-');
    if (!is_string($path)) {
        throw new RuntimeException('Unable to allocate Omnibus durable SQLite fixture.');
    }

    return $path;
}

function foundationOmnibusRemoveDatabase(string $database): void
{
    if (is_file($database)) {
        unlink($database);
    }
}


it('retains durable messages and failed attempts across selected specialist execution failures', function (): void {
    [$app, $database] = foundationOmnibusDurableApp([
        'data.database' => true,
        'execution.messaging' => true,
    ]);
    try {
        $schema = $app->make(MessagingDatabaseSchema::class);
        expect($schema->readiness()['installed'])->toBeFalse();
        $schema->install();
        expect($schema->readiness()['installed'])->toBeTrue();

        $transport = $app->make(DBLayerTransport::class);
        $bus = $app->make(MessageBus::class);

        $bus->dispatch(new FoundationOmnibusDurableMessage('fail'));
        expect($transport->size('work'))->toBe(1);
        $app->make(ConsumerFactory::class)->make('database')->run('work');
        expect($transport->size('work'))->toBe(0)
            ->and($app->make(DBLayerFailureStore::class)->all())->toHaveCount(1);

        // Failed delivery must be durable, not a destructive rollback of the
        // native messaging schema or the subsequent successful dispatch.
        $bus->dispatch(new FoundationOmnibusDurableMessage('recovered'));
        $app->make(ConsumerFactory::class)->make('database')->run('work');
        expect($transport->size('work'))->toBe(0)
            ->and(FoundationOmnibusDurableHandler::$handled)->toContain('recovered')
            ->and($app->make(DBLayerFailureStore::class)->all())->toHaveCount(1)
            ->and($schema->readiness()['installed'])->toBeTrue();

        expect(fn() => $app->execution()->run(function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection('main');
            $dispatcher = $app->make(AfterCommitDispatcher::class);
            $connection->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new FoundationOmnibusDurableMessage('cancelled'));

                throw new RuntimeException('cancelled transaction');
            });
        }))->toThrow(RuntimeException::class, 'cancelled transaction')
            ->and($transport->size('work'))->toBe(0);
    } finally {
        unset($app);
        foundationOmnibusRemoveDatabase($database);
    }
});

it('rejects durable selected messaging without DB ownership before opening storage', function (): void {
    $database = foundationOmnibusDatabasePath();
    try {
        expect(fn() => Foundation::worker([
            'database' => foundationOmnibusDatabaseConfig($database),
            'modules' => ['features' => ['execution.messaging' => true]],
            'messaging' => ['durable' => ['enabled' => true]],
        ]))->toThrow(LogicException::class, 'data.database')
            ->and(filesize($database))->toBe(0);
    } finally {
        foundationOmnibusRemoveDatabase($database);
    }
});

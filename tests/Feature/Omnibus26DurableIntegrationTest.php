<?php

declare(strict_types=1);

use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodec;

final readonly class FoundationOmnibus26DurableMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationOmnibus26DurableMessageCodec implements MessageCodec
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

        return new FoundationOmnibus26DurableMessage($value);
    }

    public function encode(object $message): array
    {
        if (!$message instanceof FoundationOmnibus26DurableMessage) {
            throw new InvalidArgumentException('Unexpected durable message type.');
        }

        return ['value' => $message->value];
    }

    public function type(): string
    {
        return FoundationOmnibus26DurableMessage::class;
    }
}

final class FoundationOmnibus26DurableHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public function __invoke(FoundationOmnibus26DurableMessage $message): void
    {
        if ($message->value === 'fail') {
            throw new RuntimeException('expected durable handler failure');
        }

        self::$handled[] = $message->value;
    }
}

beforeEach(function (): void {
    FoundationOmnibus26DurableHandler::$handled = [];
});

it('composes Omnibus 2.6 DBLayer transport, failure store and schema only when enabled', function (): void {
    [$app, $database] = foundationOmnibus26DurableApp();

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

        $bus->dispatch(new FoundationOmnibus26DurableMessage('handled'));
        expect($transport->size('work'))->toBe(1);

        $app->make(ConsumerFactory::class)->make('database')->run('work');

        expect($transport->size('work'))->toBe(0)
            ->and(FoundationOmnibus26DurableHandler::$handled)->toBe(['handled']);

        $bus->dispatch(new FoundationOmnibus26DurableMessage('fail'));
        $app->make(ConsumerFactory::class)->make('database')->run('work');

        $failures = $app->make(DBLayerFailureStore::class)->all();
        expect($failures)->toHaveCount(1)
            ->and($failures[0]->envelope?->message)->toEqual(
                new FoundationOmnibus26DurableMessage('fail'),
            );
    } finally {
        unset($app);
        foundationOmnibus26RemoveDatabase($database);
    }
});

it('reads legacy Omnibus 2.5 unwrapped DB payloads during the coordinated 2.6 cutover', function (): void {
    [$app, $database] = foundationOmnibus26DurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $serializer = $app->make(EnvelopeSerializer::class);
        $payload = $serializer->encode(new Envelope(
            new FoundationOmnibus26DurableMessage('legacy'),
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
            new FoundationOmnibus26DurableMessage('legacy'),
        );
    } finally {
        unset($app);
        foundationOmnibus26RemoveDatabase($database);
    }
});

it('binds Omnibus after-commit dispatch to the current Foundation execution connection', function (): void {
    [$app, $database] = foundationOmnibus26DurableApp();

    try {
        $app->make(MessagingDatabaseSchema::class)->install();
        $transport = $app->make(DBLayerTransport::class);

        $app->execution()->run(function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection('main');
            $dispatcher = $app->make(AfterCommitDispatcher::class);

            $connection->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new FoundationOmnibus26DurableMessage('after-commit'));
            });
        });

        expect($transport->size('work'))->toBe(1);

        expect(fn() => $app->execution()->run(function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection('main');
            $dispatcher = $app->make(AfterCommitDispatcher::class);

            $connection->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new FoundationOmnibus26DurableMessage('rolled-back'));

                throw new RuntimeException('rollback');
            });
        }))->toThrow(RuntimeException::class, 'rollback')
            ->and($transport->size('work'))->toBe(1);
    } finally {
        unset($app);
        foundationOmnibus26RemoveDatabase($database);
    }
});

it('requires an explicit failure-store policy for durable consumers', function (): void {
    $database = foundationOmnibus26DatabasePath();

    try {
        expect(fn() => Foundation::worker([
            'database' => foundationOmnibus26DatabaseConfig($database),
            'messaging' => [
                'durable' => [
                    'enabled' => true,
                    'connection' => 'main',
                ],
                'consumer' => ['transport' => 'database'],
            ],
        ]))->toThrow(
            ConfigurationException::class,
            'Durable database consumers/workers require an explicit messaging.durable.failure_store policy or FailureStore binding.',
        );
    } finally {
        foundationOmnibus26RemoveDatabase($database);
    }
});

it('keeps the DBLayer durable graph cold when durable messaging is disabled', function (): void {
    $app = Foundation::worker([
        'messaging' => [
            'default_route' => ['transport' => 'sync', 'queue' => 'default'],
            'routes' => [],
            'handlers' => [],
            'workers' => [],
        ],
    ]);
    $repository = $app->container()->getRepository();

    expect($repository->hasResolvedSingleton(DBLayerFactory::class))->toBeFalse()
        ->and($repository->hasFunctionReference(DBLayerTransport::class))->toBeFalse();

    $app->make(MessageBus::class);

    expect($repository->hasResolvedSingleton(DBLayerFactory::class))->toBeFalse()
        ->and($repository->hasFunctionReference(DBLayerTransport::class))->toBeFalse()
        ->and($repository->hasResolvedSingleton(DBLayerTransport::class))->toBeFalse();
});

/** @return array{0:\Infocyph\Foundation\Application\Application,1:string} */
function foundationOmnibus26DurableApp(): array
{
    $database = foundationOmnibus26DatabasePath();
    $app = Foundation::worker([
        'database' => foundationOmnibus26DatabaseConfig($database),
        'messaging' => [
            'durable' => [
                'enabled' => true,
                'connection' => 'main',
                'failure_store' => 'database',
            ],
            'serialization' => [
                'message_codecs' => [FoundationOmnibus26DurableMessageCodec::class],
            ],
            'default_route' => [
                'transport' => 'database',
                'queue' => 'work',
            ],
            'routes' => [
                FoundationOmnibus26DurableMessage::class => [
                    'transport' => 'database',
                    'queue' => 'work',
                ],
            ],
            'handlers' => [
                FoundationOmnibus26DurableMessage::class => FoundationOmnibus26DurableHandler::class,
            ],
            'consumer' => ['transport' => 'database'],
            'retry' => [
                'maximum_attempts' => 1,
                'initial_delay_seconds' => 0.0,
                'multiplier' => 1.0,
                'maximum_delay_seconds' => 0.0,
                'jitter_ratio' => 0.0,
            ],
            'workers' => [],
        ],
    ])->boot();

    return [$app, $database];
}

/** @return array<string,mixed> */
function foundationOmnibus26DatabaseConfig(string $database): array
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

function foundationOmnibus26DatabasePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'foundation-omnibus26-');
    if (!is_string($path)) {
        throw new RuntimeException('Unable to allocate Omnibus 2.6 SQLite fixture.');
    }

    return $path;
}

function foundationOmnibus26RemoveDatabase(string $database): void
{
    if (is_file($database)) {
        unlink($database);
    }
}

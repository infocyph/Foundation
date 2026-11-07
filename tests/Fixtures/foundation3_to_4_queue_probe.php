<?php

declare(strict_types=1);

use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Serialization\MessageCodec;

if ($argc !== 5) {
    throw new InvalidArgumentException('Expected queue phase, shared database, versioned autoloader and evidence path.');
}

[$phase, $database, $autoload, $reports] = array_slice($argv, 1);
if (!in_array($phase, ['old-seed', 'new-probe', 'old-rollback', 'old-restored', 'new-rollforward'], true)) {
    throw new InvalidArgumentException('Unknown cross-major durable-queue phase.');
}

require $autoload;

final readonly class FoundationReleaseCarryoverMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationReleaseCarryoverMessageCodec implements MessageCodec
{
    public function alias(): string
    {
        return 'foundation.omnibus26.durable-message.v1';
    }

    public function decode(array $payload): object
    {
        $value = $payload['value'] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException('Durable carryover value is invalid.');
        }

        return new FoundationReleaseCarryoverMessage($value);
    }

    public function encode(object $message): array
    {
        if (!$message instanceof FoundationReleaseCarryoverMessage) {
            throw new InvalidArgumentException('Unexpected carryover message type.');
        }

        return ['value' => $message->value];
    }

    public function type(): string
    {
        return FoundationReleaseCarryoverMessage::class;
    }
}

final class FoundationReleaseCarryoverHandler
{
    public function __invoke(FoundationReleaseCarryoverMessage $message): void
    {
        if ($message->value === 'dead-letter') {
            throw new RuntimeException('Expected durable carryover failure.');
        }
    }
}

$queue = 'release-carryover';
$app = Foundation::worker([
    'database' => [
        'default' => 'main',
        'connections' => [
            'main' => ['driver' => 'sqlite', 'database' => $database],
        ],
    ],
    'messaging' => [
        'durable' => ['enabled' => true, 'connection' => 'main', 'failure_store' => 'database'],
        'serialization' => [
            'message_codecs' => [FoundationReleaseCarryoverMessageCodec::class],
        ],
        'default_route' => ['transport' => 'database', 'queue' => $queue],
        'routes' => [
            FoundationReleaseCarryoverMessage::class => ['transport' => 'database', 'queue' => $queue],
        ],
        'handlers' => [
            FoundationReleaseCarryoverMessage::class => FoundationReleaseCarryoverHandler::class,
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

$schema = $app->make(MessagingDatabaseSchema::class);
if ($phase === 'old-seed') {
    $schema->install();
} elseif (!$schema->readiness()['installed']) {
    throw new RuntimeException('Original Omnibus durable SQL schema was not retained.');
}

$transport = $app->make(DBLayerTransport::class);
$failures = $app->make(DBLayerFailureStore::class);
$connection = $app->make(DBLayerFactory::class)->infrastructureConnection('main');

if ($phase === 'old-seed') {
    $bus = $app->make(MessageBus::class);
    $bus->dispatch(new FoundationReleaseCarryoverMessage('dead-letter'));
    $app->make(ConsumerFactory::class)->make('database')->run($queue);
    $bus->dispatch(new FoundationReleaseCarryoverMessage('pending'));
}

$failed = $failures->all();
if (count($failed) !== 1
    || !$failed[0]->envelope?->message instanceof FoundationReleaseCarryoverMessage
    || $failed[0]->envelope->message->value !== 'dead-letter'
) {
    throw new RuntimeException('Retained Omnibus failure store no longer decodes the original failure.');
}
if ($transport->size($queue) !== 1) {
    throw new RuntimeException('Original durable queue lost or duplicated a pending message.');
}

$digestPath = $reports . '/original-durable-queue.sha256';
$rows = [
    'messages' => $connection->select('SELECT * FROM omnibus_messages ORDER BY id'),
    'failures' => $connection->select('SELECT * FROM omnibus_failures ORDER BY id'),
];
$digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
if ($phase === 'old-seed') {
    if (file_put_contents($digestPath, $digest . PHP_EOL) === false) {
        throw new RuntimeException('Unable to preserve original SQL queue state digest.');
    }
} elseif ($phase === 'old-restored') {
    if (!hash_equals(trim((string) file_get_contents($digestPath)), $digest)) {
        throw new RuntimeException('Coherent database restore did not recover the original durable-queue bytes.');
    }
}

if (in_array($phase, ['new-probe', 'old-rollback', 'new-rollforward'], true)) {
    $reservations = [...$transport->receive($queue)];
    if (count($reservations) !== 1
        || !$reservations[0]->envelope()->message instanceof FoundationReleaseCarryoverMessage
        || $reservations[0]->envelope()->message->value !== 'pending'
    ) {
        throw new RuntimeException('Versioned Omnibus transport could not decode its historical pending message.');
    }
    $transport->release($reservations[0]);
}

$report = [
    'phase' => $phase,
    'source_file' => (new ReflectionClass(MessagingDatabaseSchema::class))->getFileName(),
    'durable_sha256' => $digest,
    'pending' => (int) $transport->size($queue),
    'failures' => count($failed),
];
if (file_put_contents($reports . '/queue-' . $phase . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
    throw new RuntimeException('Unable to persist genuine old/new Omnibus state evidence.');
}
fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);

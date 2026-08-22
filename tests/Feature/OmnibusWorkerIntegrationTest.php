<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\MessagingManager;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class FoundationWorkerMessage
{
    public function __construct(public string $value) {}
}

final class FoundationWorkerProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationWorkerMessageHandler
{
    /** @var list<array{value:string,sequence:int,envelope:bool,message:bool}> */
    public static array $handled = [];

    public function __construct(private Application $application) {}

    public function __invoke(FoundationWorkerMessage $message, Envelope $envelope): void
    {
        self::$handled[] = [
            'value' => $message->value,
            'sequence' => $this->application->make(FoundationWorkerProbe::class)->sequence,
            'envelope' => $this->application->make(Envelope::class) === $envelope,
            'message' => $this->application->make('omnibus.message') === $message,
        ];
    }
}

beforeEach(function (): void {
    FoundationWorkerMessageHandler::$handled = [];
});

it('runs bounded Omnibus workers with a fresh Foundation execution scope per message', function (): void {
    $provider = new class extends ServiceProvider {
        public function register(Application $app): void
        {
            $app->container()->bind(
                FoundationWorkerProbe::class,
                static fn() => new FoundationWorkerProbe(),
                LifetimeEnum::Scoped,
            );
        }
    };
    $app = Foundation::worker([
        'providers' => ['worker' => [$provider]],
        'messaging' => [
            'handlers' => [FoundationWorkerMessage::class => FoundationWorkerMessageHandler::class],
            'routes' => [
                FoundationWorkerMessage::class => [
                    'transport' => 'memory',
                    'queue' => 'jobs',
                ],
            ],
            'consumer' => ['transport' => 'memory'],
            'retry' => [
                'maximum_attempts' => 1,
                'initial_delay_seconds' => 0.0,
                'multiplier' => 1.0,
                'maximum_delay_seconds' => 0.0,
                'jitter_ratio' => 0.0,
            ],
            'workers' => [
                'jobs' => [
                    'transport' => 'memory',
                    'queue' => 'jobs',
                    'prefetch' => 1,
                    'visibility_seconds' => 60.0,
                    'idle_sleep_seconds' => 0.0,
                    'max_idle_sleep_seconds' => 0.0,
                    'idle_jitter_ratio' => 0.0,
                    'max_messages' => 2,
                    'handle_signals' => false,
                    'pool' => ['enabled' => false],
                ],
            ],
        ],
    ]);

    $messaging = $app->make(MessagingManager::class);
    $messaging->dispatch(new FoundationWorkerMessage('first'));
    $messaging->dispatch(new FoundationWorkerMessage('second'));

    $factory = $app->make(OmnibusWorkerFactory::class);
    $options = $factory->options('jobs');

    expect($options->queue)->toBe('jobs')
        ->and($options->maxMessages)->toBe(2)
        ->and($factory->pool('jobs')['enabled'])->toBeFalse()
        ->and(new WorkerManager($app)->run('jobs'))->toBe(0)
        ->and(FoundationWorkerMessageHandler::$handled)->toHaveCount(2)
        ->and(array_column(FoundationWorkerMessageHandler::$handled, 'value'))->toBe(['first', 'second'])
        ->and(FoundationWorkerMessageHandler::$handled[0]['sequence'])
        ->not->toBe(FoundationWorkerMessageHandler::$handled[1]['sequence'])
        ->and(FoundationWorkerMessageHandler::$handled[0]['envelope'])->toBeTrue()
        ->and(FoundationWorkerMessageHandler::$handled[0]['message'])->toBeTrue();
});

it('rejects process-local memory transport before starting an Omnibus worker pool', function (): void {
    $app = Foundation::worker([
        'messaging' => [
            'workers' => [
                'parallel' => [
                    'transport' => 'memory',
                    'queue' => 'default',
                    'pool' => [
                        'enabled' => true,
                        'concurrency' => 2,
                    ],
                ],
            ],
        ],
    ]);

    expect(fn() => new WorkerManager($app)->run('parallel'))
        ->toThrow(LogicException::class, 'process-local');
});

it('requires declarative fork-safe configuration before starting a worker pool', function (): void {
    if (class_exists(DB::class)) {
        DB::purge();
    }

    $app = Foundation::worker([
        'messaging' => [
            'handlers' => [FoundationWorkerMessage::class => static fn(): null => null],
            'workers' => [
                'parallel' => [
                    'transport' => 'shared',
                    'queue' => 'default',
                    'pool' => [
                        'enabled' => true,
                        'concurrency' => 2,
                    ],
                ],
            ],
        ],
    ]);

    expect(fn() => new WorkerManager($app)->run('parallel'))
        ->toThrow(LogicException::class, 'scalar/array configuration');
});

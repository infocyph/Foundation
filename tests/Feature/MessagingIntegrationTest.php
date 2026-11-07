<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Testing\TestKit;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class FoundationMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationFailingMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationFiberMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationEvent
{
    public function __construct(public string $value) {}
}

final class FoundationMessageProbe
{
    private static int $next = 0;
    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationMessageHandler
{
    /** @var list<array{value:string,sequence:int,envelope:bool,message:bool}> */
    public static array $handled = [];

    public function __construct(private Application $application) {}

    public function __invoke(FoundationMessage $message, Envelope $envelope): void
    {
        $probe = $this->application->make(FoundationMessageProbe::class);
        self::$handled[] = [
            'value' => $message->value,
            'sequence' => $probe->sequence,
            'envelope' => $this->application->make(Envelope::class) === $envelope,
            'message' => $this->application->make('omnibus.message') === $message,
        ];
        if ($message->value === 'nested-borrowed-parent') {
            $this->application->make(MessageBus::class)
                ->dispatch(new FoundationMessage('nested-borrowed-child'));
        }
    }
}

final class FoundationFailingMessageHandler
{
    /** @var list<int> */
    public static array $scopes = [];

    public function __construct(private Application $application) {}

    public function __invoke(): never
    {
        self::$scopes[] = $this->application->make(FoundationMessageProbe::class)->sequence;
        throw new RuntimeException('expected consumer failure');
    }
}

final class FoundationFiberMessageHandler
{
    /** @var list<array{value:string,before:int,after:int}> */
    public static array $handled = [];

    public function __construct(private Application $application) {}

    public function __invoke(FoundationFiberMessage $message): void
    {
        $before = $this->application->make(FoundationMessageProbe::class)->sequence;
        Fiber::suspend($before);
        $after = $this->application->make(FoundationMessageProbe::class)->sequence;
        self::$handled[] = ['value' => $message->value, 'before' => $before, 'after' => $after];
    }
}

final class FoundationEventListener
{
    /** @var list<string> */
    public static array $events = [];

    public function __invoke(FoundationEvent $event): void
    {
        self::$events[] = $event->value;
    }
}

final class FoundationScheduledMessageFactory
{
    public function __invoke(): FoundationMessage
    {
        return new FoundationMessage('scheduled');
    }
}

function foundationMessagingApplication(array $messaging = []): Application
{
    $provider = new class extends ServiceProvider {
        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);

            $builder->factory(
                FoundationMessageProbe::class,
                FactoryDefinition::construct(FoundationMessageProbe::class),
                LifetimeEnum::Scoped,
            );
        }
    };

    return Foundation::cli([
        'providers' => ['cli' => [$provider]],
        'messaging' => array_replace_recursive([
            'handlers' => [
                FoundationMessage::class => FoundationMessageHandler::class,
                FoundationFailingMessage::class => FoundationFailingMessageHandler::class,
                FoundationFiberMessage::class => FoundationFiberMessageHandler::class,
            ],
            'listeners' => [FoundationEvent::class => [FoundationEventListener::class]],
            'routes' => [
                FoundationMessage::class => ['transport' => 'memory', 'queue' => 'default'],
                FoundationFailingMessage::class => ['transport' => 'memory', 'queue' => 'default'],
                FoundationFiberMessage::class => ['transport' => 'memory', 'queue' => 'default'],
            ],
            'scheduled_messages' => ['reports.daily' => FoundationScheduledMessageFactory::class],
            'retry' => [
                'maximum_attempts' => 1,
                'initial_delay_seconds' => 0.0,
                'multiplier' => 1.0,
                'maximum_delay_seconds' => 0.0,
                'jitter_ratio' => 0.0,
            ],
        ], $messaging),
    ]);
}

beforeEach(function (): void {
    FoundationMessageHandler::$handled = [];
    FoundationFailingMessageHandler::$scopes = [];
    FoundationFiberMessageHandler::$handled = [];
    FoundationEventListener::$events = [];
});

it('keeps Omnibus deferred until messaging is selected', function (): void {
    $observer = new class extends ServiceProvider {
        public bool $resolved = false;

        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);
            $builder->onResolved(MessageBus::class, function (string $id): void {
                $this->resolved = $id === MessageBus::class;
            });
        }
    };
    $app = Foundation::cli(['providers' => ['cli' => [$observer]]]);

    expect($observer->resolved)->toBeFalse()
        ->and($app->has(MessageBus::class))->toBeTrue()
        ->and($observer->resolved)->toBeFalse()
        ->and($app->make(MessageBus::class))->toBeInstanceOf(MessageBus::class)
        ->and($observer->resolved)->toBeTrue();
});

it('wires explicit event listeners and Foundation messaging fakes', function (): void {
    $app = foundationMessagingApplication();
    $events = $app->make(EventDispatcherInterface::class);
    expect($events->dispatch(new FoundationEvent('created')))
        ->toBeInstanceOf(FoundationEvent::class)
        ->and(FoundationEventListener::$events)->toBe(['created']);

    $recording = $app->make(TestKit::class)->fakeMessaging();
    $app->make(MessageBus::class)->dispatch(new FoundationMessage('fake'));
    $app->make(EventDispatcherInterface::class)->dispatch(new FoundationEvent('fake-event'));

    expect($recording->count(FoundationMessage::class))->toBe(1)
        ->and(FoundationEventListener::$events)->toBe(['created', 'fake-event']);
});

it('creates a fresh InterMix scope after successful and failed message handling', function (): void {
    $app = foundationMessagingApplication();
    $bus = $app->make(MessageBus::class);
    $task = $app->make(ConsumerTask::class);

    $bus->dispatch(new FoundationMessage('first'));
    $first = $task->run(new ConsumeRequest(limit: 1));
    $bus->dispatch(new FoundationFailingMessage('failure'));
    $failure = $task->run(new ConsumeRequest(limit: 1));
    $bus->dispatch(new FoundationMessage('second'));
    $second = $task->run(new ConsumeRequest(limit: 1));

    expect($first->succeeded)->toBe(1)
        ->and($failure->failed)->toBe(1)
        ->and($second->succeeded)->toBe(1)
        ->and(FoundationMessageHandler::$handled)->toHaveCount(2)
        ->and(FoundationMessageHandler::$handled[0]['envelope'])->toBeTrue()
        ->and(FoundationMessageHandler::$handled[0]['message'])->toBeTrue()
        ->and(FoundationMessageHandler::$handled[0]['sequence'])->not->toBe(FoundationFailingMessageHandler::$scopes[0])
        ->and(FoundationFailingMessageHandler::$scopes[0])->not->toBe(FoundationMessageHandler::$handled[1]['sequence']);
});

it('isolates concurrent Fiber message executions in distinct Foundation scopes', function (): void {
    $app = foundationMessagingApplication();
    $bus = $app->make(MessageBus::class);
    $task = $app->make(ConsumerTask::class);

    $bus->dispatch(new FoundationFiberMessage('a'));
    $bus->dispatch(new FoundationFiberMessage('b'));

    $fiberA = new Fiber(static fn() => $task->run(new ConsumeRequest(limit: 1)));
    $fiberB = new Fiber(static fn() => $task->run(new ConsumeRequest(limit: 1)));

    $scopeA = $fiberA->start();
    $scopeB = $fiberB->start();

    expect($scopeA)->toBeInt()
        ->and($scopeB)->toBeInt()
        ->and($scopeA)->not->toBe($scopeB);

    $fiberB->resume();
    $fiberA->resume();

    expect($fiberA->getReturn()->succeeded)->toBe(1)
        ->and($fiberB->getReturn()->succeeded)->toBe(1)
        ->and(FoundationFiberMessageHandler::$handled)->toHaveCount(2);

    foreach (FoundationFiberMessageHandler::$handled as $handled) {
        expect($handled['after'])->toBe($handled['before']);
    }

    expect(array_column(FoundationFiberMessageHandler::$handled, 'before'))
        ->toContain($scopeA, $scopeB);
});

it('keeps real Omnibus bus and consumer delivery bound only to the live host request', function (): void {
    $app = foundationMessagingApplication();
    $bus = $app->make(MessageBus::class);
    $consumer = $app->make(ConsumerTask::class);
    $binding = $app->make(RunwireBinding::class);

    expect($binding->request())->toBeNull();

    $runtime = RuntimeContext::standalone();
    $host = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        CacheRunwire::share($host, null, static function () use ($bus, $consumer, $binding, $host): void {
            expect($binding->request())->toBe($host);

            $bus->dispatch(new FoundationMessage('host-bound'));
            expect($consumer->run(new ConsumeRequest(limit: 1))->succeeded)->toBe(1);

            $host->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $bus->dispatch(new FoundationMessage('cancelled')))
                ->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $host->complete();
        CacheRunwire::release($runtime);
    }

    expect($binding->request())->toBeNull();

    $bus->dispatch(new FoundationMessage('ordinary'));
    expect($consumer->run(new ConsumeRequest(limit: 1))->succeeded)->toBe(1)
        ->and(array_column(FoundationMessageHandler::$handled, 'value'))
        ->toBe(['host-bound', 'ordinary']);
});

it('dispatches nested Omnibus messages in the original borrowed host context without retaining it afterward', function (): void {
    $app = foundationMessagingApplication();
    $bus = $app->make(MessageBus::class);
    $consumer = $app->make(ConsumerTask::class);
    $binding = $app->make(RunwireBinding::class);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        CacheRunwire::share($request, null, static function () use ($bus, $consumer, $binding, $request): void {
            expect($binding->request())->toBe($request);
            $bus->dispatch(new FoundationMessage('nested-borrowed-parent'));

            expect($consumer->run(new ConsumeRequest(limit: 1))->succeeded)->toBe(1)
                ->and($binding->request())->toBe($request)
                ->and($consumer->run(new ConsumeRequest(limit: 1))->succeeded)->toBe(1)
                ->and($binding->request())->toBe($request);

            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $bus->dispatch(new FoundationMessage('post-cancel')))
                ->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }

    expect($binding->request())->toBeNull()
        ->and(array_column(FoundationMessageHandler::$handled, 'value'))
        ->toBe(['nested-borrowed-parent', 'nested-borrowed-child'])
        ->and(FoundationMessageHandler::$handled[0]['sequence'])
        ->not->toBe(FoundationMessageHandler::$handled[1]['sequence']);

    $bus->dispatch(new FoundationMessage('ordinary-after-borrow'));
    expect($consumer->run(new ConsumeRequest(limit: 1))->succeeded)->toBe(1)
        ->and($binding->request())->toBeNull()
        ->and(array_column(FoundationMessageHandler::$handled, 'value'))
        ->toBe(['nested-borrowed-parent', 'nested-borrowed-child', 'ordinary-after-borrow']);
});

it('dispatches named scheduled messages through the configured route map', function (): void {
    $app = foundationMessagingApplication();
    $app->make(ScheduledMessageDispatcher::class)->dispatch('reports.daily');
    $result = $app->make(ConsumerTask::class)->run(new ConsumeRequest(limit: 1));
    expect($result->succeeded)->toBe(1)
        ->and(FoundationMessageHandler::$handled[0]['value'])->toBe('scheduled');
});
<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\MessagingManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;

final readonly class FoundationMessage
{
    public function __construct(public string $value) {}
}

final readonly class FoundationFailingMessage
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

    public function __invoke(
        FoundationMessage $message,
        Envelope $envelope,
    ): void {
        $probe = $this->application->make(FoundationMessageProbe::class);
        self::$handled[] = [
            'value' => $message->value,
            'sequence' => $probe->sequence,
            'envelope' => $this->application->make(Envelope::class) === $envelope,
            'message' => $this->application->make('omnibus.message') === $message,
        ];
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
        public function register(Application $app): void
        {
            $container = $app->container();
            $container->bind(
                FoundationMessageProbe::class,
                static fn() => new FoundationMessageProbe(),
                LifetimeEnum::Scoped,
            );
        }
    };

    return Foundation::console([
        'providers' => ['console' => [$provider]],
        'messaging' => array_replace_recursive([
            'handlers' => [
                FoundationMessage::class => FoundationMessageHandler::class,
                FoundationFailingMessage::class => FoundationFailingMessageHandler::class,
            ],
            'listeners' => [
                FoundationEvent::class => [FoundationEventListener::class],
            ],
            'routes' => [
                FoundationMessage::class => ['transport' => 'memory', 'queue' => 'default'],
                FoundationFailingMessage::class => ['transport' => 'memory', 'queue' => 'default'],
            ],
            'scheduled_messages' => [
                'reports.daily' => FoundationScheduledMessageFactory::class,
            ],
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
    FoundationEventListener::$events = [];
});

it('keeps Omnibus deferred until messaging is selected', function (): void {
    $app = Foundation::console();

    expect($app->container()->has(MessagingManager::class))->toBeFalse()
        ->and($app->has(MessagingManager::class))->toBeTrue()
        ->and($app->container()->has(MessagingManager::class))->toBeFalse()
        ->and($app->messaging())->toBeInstanceOf(MessagingManager::class)
        ->and($app->container()->has(MessagingManager::class))->toBeTrue();
});

it('wires explicit event listeners and Foundation messaging fakes', function (): void {
    $app = foundationMessagingApplication();

    expect($app->messaging()->event(new FoundationEvent('created')))
        ->toBeInstanceOf(FoundationEvent::class)
        ->and(FoundationEventListener::$events)->toBe(['created']);

    $recording = $app->testing()->fakeMessaging();
    $app->messaging()->dispatch(new FoundationMessage('fake'));
    $app->messaging()->dispatchNotification(new FoundationMessage('notification'));
    $app->messaging()->event(new FoundationEvent('fake-event'));

    expect($recording->count(FoundationMessage::class))->toBe(2)
        ->and($recording->count(FoundationEvent::class))->toBe(1)
        ->and(array_column($recording->sent(), 'queue'))->toBe(['default', 'default', 'events']);

    $app->messaging()->restore();
    expect($app->messaging()->isFaking())->toBeFalse();
});

it('creates a fresh InterMix scope after successful and failed message handling', function (): void {
    $app = foundationMessagingApplication();
    $task = $app->make(ConsumerTask::class);

    $app->messaging()->dispatch(new FoundationMessage('first'));
    $first = $task->run(new ConsumeRequest(limit: 1));

    $app->messaging()->dispatch(new FoundationFailingMessage('failure'));
    $failure = $task->run(new ConsumeRequest(limit: 1));

    $app->messaging()->dispatch(new FoundationMessage('second'));
    $second = $task->run(new ConsumeRequest(limit: 1));

    expect($first->succeeded)->toBe(1)
        ->and($failure->failed)->toBe(1)
        ->and($second->succeeded)->toBe(1)
        ->and(FoundationMessageHandler::$handled)->toHaveCount(2)
        ->and(FoundationMessageHandler::$handled[0]['envelope'])->toBeTrue()
        ->and(FoundationMessageHandler::$handled[0]['message'])->toBeTrue()
        ->and(FoundationMessageHandler::$handled[0]['sequence'])
        ->not->toBe(FoundationFailingMessageHandler::$scopes[0])
        ->and(FoundationFailingMessageHandler::$scopes[0])
        ->not->toBe(FoundationMessageHandler::$handled[1]['sequence']);
});

it('dispatches named scheduled messages through the configured route map', function (): void {
    $app = foundationMessagingApplication();

    $app->make(ScheduledMessageDispatcher::class)->dispatch('reports.daily');
    $result = $app->make(ConsumerTask::class)->run(new ConsumeRequest(limit: 1));

    expect($result->succeeded)->toBe(1)
        ->and(FoundationMessageHandler::$handled[0]['value'])->toBe('scheduled');
});

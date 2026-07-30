<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;
use Infocyph\Foundation\Messaging\MessagingManager;
use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use PhpBench\Attributes as Bench;

#[Bench\Revs(10)]
#[Bench\Iterations(3)]
#[Bench\Warmup(1)]
final class RuntimeCapabilityBench
{
    private ConsumerTask $consumer;

    private int $events = 0;

    private MessagingManager $messaging;

    private JsonDispatchResponseFactory $responses;

    #[Bench\BeforeMethods('setUpResponses')]
    public function benchJsonDispatchResourceResponse(): void
    {
        $response = $this->responses->success([
            'id' => 42,
            'name' => 'Foundation',
            'roles' => ['operator', 'reporter'],
        ]);
        if ($response->getStatusCode() !== 200 || (string) $response->getBody() === '') {
            throw new \LogicException('JsonDispatch benchmark produced an invalid response.');
        }
    }

    #[Bench\BeforeMethods('setUpMessaging')]
    public function benchQueueDispatchAndConsume(): void
    {
        $this->messaging->dispatch((object) ['id' => 42]);
        $result = $this->consumer->run(new ConsumeRequest(limit: 1));
        if ($result->succeeded !== 1) {
            throw new \LogicException('Queue benchmark did not consume its message.');
        }
    }

    #[Bench\BeforeMethods('setUpMessaging')]
    public function benchSynchronousEventDispatch(): void
    {
        $before = $this->events;
        $this->messaging->event(new \ArrayObject(['id' => 42]));
        if ($this->events !== $before + 1) {
            throw new \LogicException('Event benchmark did not invoke its listener.');
        }
    }

    public function setUpMessaging(): void
    {
        $application = Foundation::console([
            'messaging' => [
                'handlers' => [
                    \stdClass::class => static fn(\stdClass $message): mixed => $message->id,
                ],
                'routes' => [
                    \stdClass::class => [
                        'transport' => 'memory',
                        'queue' => 'default',
                        'delay_seconds' => 0.0,
                    ],
                ],
                'listeners' => [
                    \ArrayObject::class => [
                        function (): void {
                            ++$this->events;
                        },
                    ],
                ],
                'retry' => [
                    'maximum_attempts' => 1,
                    'initial_delay_seconds' => 0.0,
                    'multiplier' => 1.0,
                    'maximum_delay_seconds' => 0.0,
                    'jitter_ratio' => 0.0,
                ],
            ],
        ]);
        $this->messaging = $application->messaging();
        $this->consumer = $application->make(ConsumerTask::class);
    }

    public function setUpResponses(): void
    {
        $this->responses = new JsonDispatchResponseFactory(
            vendor: 'infocyph',
            applicationVersion: 'benchmark',
        );
    }
}

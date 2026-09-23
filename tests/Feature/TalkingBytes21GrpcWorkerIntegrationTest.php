<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Communication\GrpcInboundWorker;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundSource;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcInboundSource;

final class FoundationTalkingBytes21GrpcHandler implements GrpcInboundHandlerInterface
{
    public static int $handled = 0;

    public static int $next = 0;

    private int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }

    public function handle(GrpcInboundRequest $request): GrpcInboundResponse
    {
        ++self::$handled;

        return GrpcInboundResponse::ok([
            'sequence' => $this->sequence,
            'message' => $request->message,
        ]);
    }
}

final class FoundationTalkingBytes21GrpcSource implements GrpcInboundSource
{
    public bool $requestStopOnAccept = false;

    public bool $sawCancellation = false;

    public bool $stopRequested = false;

    private FakeGrpcInboundSource $inner;

    public function __construct()
    {
        $this->inner = new FakeGrpcInboundSource();
    }

    public function accept(?CancellationSignal $cancellation = null): ?GrpcInboundExchange
    {
        $this->sawCancellation = $this->sawCancellation || $cancellation !== null;
        if ($this->requestStopOnAccept) {
            $this->stopRequested = true;
        }

        return $this->inner->accept($cancellation);
    }

    public function enqueue(GrpcInboundRequest $request): FakeGrpcInboundExchange
    {
        return $this->inner->enqueue($request);
    }

    public function pendingCount(): int
    {
        return $this->inner->pendingCount();
    }
}

final class FoundationTalkingBytes21GrpcProvider extends ServiceProvider
{
    public function __construct(private readonly FoundationTalkingBytes21GrpcSource $source) {}

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->value(GrpcInboundSource::class, $this->source);
        $builder->scoped(
            FoundationTalkingBytes21GrpcHandler::class,
            FactoryDefinition::construct(FoundationTalkingBytes21GrpcHandler::class),
        );
    }
}

it('runs accepted gRPC exchanges inside fresh Foundation worker execution scopes', function (): void {
    FoundationTalkingBytes21GrpcHandler::$handled = 0;
    FoundationTalkingBytes21GrpcHandler::$next = 0;
    $source = new FoundationTalkingBytes21GrpcSource();
    $first = $source->enqueue(new GrpcInboundRequest('/foundation.v1.Test/Call', ['id' => 1]));
    $second = $source->enqueue(new GrpcInboundRequest('/foundation.v1.Test/Call', ['id' => 2]));
    $heartbeat = 0;

    $app = foundationTalkingBytes21GrpcApplication($source);
    $runtime = new WorkerRuntime(
        $app,
        static function () use (&$heartbeat): void {
            ++$heartbeat;
        },
        static fn(): bool => $first->completed() && $second->completed(),
    );

    $result = $app->make(GrpcInboundWorker::class)->run($runtime);

    expect($result)->toBe(0)
        ->and($source->sawCancellation)->toBeTrue()
        ->and($heartbeat)->toBeGreaterThanOrEqual(2)
        ->and($first->completed())->toBeTrue()
        ->and($second->completed())->toBeTrue()
        ->and($first->response()?->message['sequence'] ?? null)->toBe(1)
        ->and($second->response()?->message['sequence'] ?? null)->toBe(2)
        ->and(FoundationTalkingBytes21GrpcHandler::$handled)->toBe(2)
        ->and($first->response()?->message['message'] ?? null)->toBe(['id' => 1])
        ->and($second->response()?->message['message'] ?? null)->toBe(['id' => 2]);
});

it('forwards Foundation stop policy through TalkingBytes inbound cancellation', function (): void {
    FoundationTalkingBytes21GrpcHandler::$handled = 0;
    FoundationTalkingBytes21GrpcHandler::$next = 0;
    $source = new FoundationTalkingBytes21GrpcSource();
    $exchange = $source->enqueue(new GrpcInboundRequest('/foundation.v1.Test/Call', ['id' => 3]));
    $source->requestStopOnAccept = true;

    $app = foundationTalkingBytes21GrpcApplication($source);
    $runtime = new WorkerRuntime(
        $app,
        stopRequested: static fn(): bool => $source->stopRequested,
    );

    $result = $app->make(GrpcInboundWorker::class)->run($runtime);

    expect($result)->toBe(0)
        ->and($source->sawCancellation)->toBeTrue()
        ->and($exchange->completed())->toBeTrue()
        ->and($exchange->response()?->status)->toBe(GrpcStatus::Cancelled)
        ->and(FoundationTalkingBytes21GrpcHandler::$handled)->toBe(0);
});

function foundationTalkingBytes21GrpcApplication(
    FoundationTalkingBytes21GrpcSource $source,
): \Infocyph\Foundation\Application\Application {
    return Foundation::worker([
        'app' => [
            'base_path' => sys_get_temp_dir(),
            'capabilities' => ['communication'],
        ],
        '_config_cache' => false,
        'providers' => [
            'worker' => [
                new FoundationTalkingBytes21GrpcProvider($source),
            ],
        ],
        'communication' => [
            'grpc' => [
                'inbound' => [
                    'source_service' => GrpcInboundSource::class,
                    'idle_sleep_milliseconds' => 0,
                    'heartbeat_interval_milliseconds' => 5_000,
                    'handlers' => [
                        '/foundation.v1.Test/Call' => FoundationTalkingBytes21GrpcHandler::class,
                    ],
                ],
            ],
        ],
    ])->boot();
}

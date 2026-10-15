<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\MessagingRuntimeResolver;
use Infocyph\Foundation\Runtime\NativeRunwireOperation;
use Infocyph\Foundation\Validation\FormRequest;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\Schema\SchemaRegistry;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;

it('forwards native host cancellation to ordinary Foundation database operations and restores binding', function (): void {
    $app = Foundation::cli([
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        $app->execution()->run(static function () use ($app, $request, $runtime): void {
            $factory = $app->make(DBLayerFactory::class);
            expect($factory->withConnection(static function (Connection $connection): mixed {
                expect($connection->runwireBinding())->toBeNull();

                return $connection->scalar('SELECT 1');
            }))->toBe(1);

            $bound = CacheRunwire::share($request, null, static function () use ($factory): array {
                return $factory->withConnection(static function (Connection $connection): array {
                    $binding = $connection->runwireBinding();
                    expect($binding)->not->toBeNull();

                    return [$binding['runtime'], $binding['request'], $connection->scalar('SELECT 1')];
                });
            });
            expect($bound)->toBe([$runtime, $request, 1])
                ->and($factory->connection()->runwireBinding())->toBeNull()
                ->and(NativeRunwireOperation::current())->toBeNull();

            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => CacheRunwire::share(
                $request,
                null,
                static fn(): mixed => $factory->withConnection(
                    static fn(Connection $connection): mixed => $connection->scalar('SELECT 1'),
                ),
            ))->toThrow(CancelledException::class);
        }, [
            RuntimeContext::class => $runtime,
            RequestContext::class => $request,
        ]);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }
});

it('uses native ReqShield and TalkingBytes operation cancellation without retaining host state', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);
    $validator = new class(new ValidatorFactory(new ConfigRepository(), new SchemaRegistry())) extends FormRequest {
        protected function rules(Request $request): array
        {
            return ['name' => 'required|string'];
        }
    };
    $profiles = new CommunicationProfiles(new ConfigRepository([
        'communication' => [
            'http' => ['clients' => ['default' => []]],
            'grpc' => ['profiles' => ['default' => []]],
        ],
    ]));
    try {
        expect($validator->validate(Request::fake(['name' => 'Ada']))->passes())->toBeTrue()
            ->and($profiles->http())->toBeInstanceOf(\Infocyph\TalkingBytes\Http\HttpClient::class);

        CacheRunwire::share($request, null, static function () use ($validator, $profiles): void {
            expect($validator->validate(Request::fake(['name' => 'Ada']))->passes())->toBeTrue()
                ->and($profiles->http())->toBeInstanceOf(\Infocyph\TalkingBytes\Http\HttpClient::class);
        });

        $request->cancel(CancellationReason::HOST_CANCELLED);
        expect(fn() => CacheRunwire::share(
            $request,
            null,
            static fn(): mixed => $validator->validate(Request::fake(['name' => 'Ada'])),
        ))->toThrow(CancelledException::class)
            ->and(fn() => CacheRunwire::share(
                $request,
                null,
                static fn(): mixed => $profiles->http(),
            ))->toThrow(CancelledException::class);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }
});

final readonly class FoundationNativeOperationMessage
{
    public function __construct(public string $value) {}
}

it('propagates native Runwire cancellation through Foundation Omnibus dispatch without leaking the bus', function (): void {
    $app = Foundation::cli([
        'messaging' => [
            'routes' => [
                FoundationNativeOperationMessage::class => ['transport' => 'memory', 'queue' => 'default'],
            ],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        $app->execution()->run(static function () use ($app, $request): void {
            $resolver = $app->make(MessagingRuntimeResolver::class);
            $bus = $app->make(\Infocyph\Omnibus\MessageBus::class);

            expect($resolver->dispatch(new FoundationNativeOperationMessage('plain')))
                ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);

            CacheRunwire::share($request, null, static function () use ($resolver, $bus): void {
                expect($resolver->dispatch(new FoundationNativeOperationMessage('bound')))
                    ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class)
                    ->and($bus->runwireBinding()->request())->toBeNull();
            });
            expect($bus->runwireBinding()->request())->toBeNull();

            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => CacheRunwire::share(
                $request,
                null,
                static fn(): mixed => $resolver->dispatch(new FoundationNativeOperationMessage('cancelled')),
            ))->toThrow(CancelledException::class);
        }, [
            RuntimeContext::class => $runtime,
            RequestContext::class => $request,
        ]);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }
});

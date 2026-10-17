<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\FormRequest;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\Schema\SchemaRegistry;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;

it('binds ordinary database operations to passed host inputs and restores the synchronous path', function (string $mode): void {
    $config = [
        'app' => ['capabilities' => ['runwire' => true]],
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ];
    $app = match ($mode) {
        'worker' => Foundation::worker($config)->boot(),
        'scheduler' => Foundation::scheduler($config)->boot(),
        default => Foundation::cli($config)->boot(),
    };
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $factory = $app->make(DBLayerFactory::class);

    try {
        $app->execution()->run(static function () use ($factory): void {
            expect($factory->connection()->scalar('SELECT 1'))->toBe(1)
                ->and($factory->connection()->runwireBinding())->toBeNull();
        });

        $app->execution()->run(static function () use ($factory, $runtime, $request): void {
            $connection = $factory->connection();
            expect(CacheRunwire::current()?->runtime)->toBe($runtime)
                ->and($connection->runwireBinding())->toBe([
                    'runtime' => $runtime, 'request' => $request, 'scope' => null,
                ])
                ->and($connection->scalar('SELECT 1'))->toBe(1)
                ->and($factory->connection(fresh: true)->scalar('SELECT 2'))->toBe(2);

            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $connection->scalar('SELECT 1'))
                ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class);
        }, [RuntimeContext::class => $runtime, RequestContext::class => $request]);

        expect(CacheRunwire::current())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull()
            ->and($request->completed())->toBeFalse();
        $app->execution()->run(static function () use ($factory): void {
            expect($factory->connection()->scalar('SELECT 3'))->toBe(3)
                ->and($factory->connection()->runwireBinding())->toBeNull();
        });
    } finally {
        $request->complete();
    }
})->with(['cli', 'worker', 'scheduler']);

it('observes cancellation after entering an ordinary native database query', function (): void {
    $app = Foundation::cli([
        'app' => ['capabilities' => ['runwire' => true]],
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $observed = null;
    $listener = static function (\Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuting $event) use ($request, &$observed): void {
        if ($event->sql === 'SELECT 42') {
            $observed = $event->connection->runwireBinding();
            $request->cancel(CancellationReason::HOST_CANCELLED);
        }
    };
    \Infocyph\DBLayer\Events\Events::listen('db.query.executing', $listener);

    try {
        $app->execution()->run(static function () use ($app): void {
            $connection = $app->make(DBLayerFactory::class)->connection();
            expect(fn() => $connection->scalar('SELECT 42'))
                ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class);
        }, [RuntimeContext::class => $runtime, RequestContext::class => $request]);
        expect($observed)->toBe(['runtime' => $runtime, 'request' => $request, 'scope' => null])
            ->and(CacheRunwire::current())->toBeNull();
    } finally {
        \Infocyph\DBLayer\Events\Events::forget('db.query.executing', $listener);
        $request->complete();
    }
});

it('uses native ReqShield and TalkingBytes operation cancellation without retaining host state', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);
    $validator = new class(new ValidatorFactory(new ConfigRepository(), new SchemaRegistry())) extends FormRequest {
        protected function rules(Request $request): array
        {
            unset($request);

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

it('propagates passed native context to ordinary bus consumer and worker operations', function (): void {
    $app = Foundation::cli([
        'app' => ['capabilities' => ['runwire' => true, 'messaging' => true]],
        'messaging' => [
            'default_route' => ['transport' => 'memory', 'queue' => 'default'],
            'workers' => ['test' => ['transport' => 'memory']],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $bus = $app->make(\Infocyph\Omnibus\MessageBus::class);
    $consumer = $app->make(\Infocyph\Omnibus\Consumer\Consumer::class);
    $worker = $app->make(\Infocyph\Foundation\Messaging\OmnibusWorkerFactory::class)->make('test');

    try {
        expect($bus->dispatch(new FoundationNativeOperationMessage('plain')))
            ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);
        $app->execution()->run(static function () use ($bus, $consumer, $worker, $runtime, $request): void {
            foreach ([$bus, $consumer, $worker] as $service) {
                expect($service->runwireBinding()->runtime())->toBe($runtime)
                    ->and($service->runwireBinding()->request())->toBe($request);
            }
            expect($bus->dispatch(new FoundationNativeOperationMessage('bound')))
                ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);
            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $bus->dispatch(new FoundationNativeOperationMessage('cancelled')))
                ->toThrow(CancelledException::class)
                ->and(fn() => $consumer->run())->toThrow(CancelledException::class);
        }, [RuntimeContext::class => $runtime, RequestContext::class => $request]);

        expect($bus->runwireBinding()->request())->toBeNull()
            ->and($consumer->runwireBinding()->request())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull()
            ->and($request->completed())->toBeFalse()
            ->and($bus->dispatch(new FoundationNativeOperationMessage('fallback')))
            ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);
    } finally {
        $request->complete();
    }
});

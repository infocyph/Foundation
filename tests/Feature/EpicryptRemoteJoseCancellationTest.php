<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Epicrypt\Integration\Runwire\RunwireExecutionContext;
use Infocyph\Epicrypt\Token\Jwt\RemoteJoseHostResolverInterface;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwks;
use Infocyph\Epicrypt\Token\Jwt\RemoteJwksConfiguration;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

it('never serves stale remote JOSE keys when a selected resolver cancels the borrowed Foundation host', function (bool $throwDuringResolution): void {
    $runtime = RuntimeContext::standalone();
    $hostRequest = RequestContext::create($runtime);

    $resolver = new class($hostRequest, $throwDuringResolution) implements RemoteJoseHostResolverInterface {
        public int $calls = 0;

        public function __construct(
            private RequestContext $hostRequest,
            private bool $throwDuringResolution,
        ) {}

        /**
         * @throws \Infocyph\Runwire\Exception\CancelledException
         */
        #[\Override]
        public function resolve(string $hostname): array
        {
            expect($hostname)->toBe('issuer.example');
            ++$this->calls;
            $this->hostRequest->cancel(CancellationReason::HOST_CANCELLED);
            if ($this->throwDuringResolution) {
                $this->hostRequest->cancellation->throwIfCancelled();
            }

            return ['1.1.1.1'];
        }
    };

    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('sendRequest');
    $factory = $this->createMock(RequestFactoryInterface::class);
    $factory->expects($this->never())->method('createRequest');

    $cache = $this->createMock(CacheInterface::class);
    $cache->expects($this->once())->method('get')->willReturn([
        'jwks' => ['keys' => []],
        'freshUntil' => 0,
        'staleUntil' => PHP_INT_MAX - 1,
    ]);
    $cache->expects($this->never())->method('set');

    $remote = new RemoteJwks(
        $client,
        $factory,
        new RemoteJwksConfiguration(
            issuer: 'https://issuer.example',
            jwksUri: 'https://issuer.example/jwks',
        ),
        cache: $cache,
        hostResolver: $resolver,
    );

    CacheRunwire::bind($runtime);
    try {
        CacheRunwire::share(
            $hostRequest,
            null,
            static function () use ($remote): void {
                $borrowed = CacheRunwire::current();
                expect($borrowed)->not->toBeNull();

                $context = new RunwireExecutionContext(
                    $borrowed->runtime,
                    $borrowed->request,
                    $borrowed->scope,
                );

                expect(fn() => $remote->load(context: $context))
                    ->toThrow(CancelledException::class);
            },
            $runtime,
        );

        expect($resolver->calls)->toBe(1)
            ->and($hostRequest->cancelled())->toBeTrue();
    } finally {
        $hostRequest->complete();
        CacheRunwire::release($runtime);
    }
})->with([
    'resolver throws native cancellation' => [true],
    'resolver returns after host cancellation' => [false],
]);

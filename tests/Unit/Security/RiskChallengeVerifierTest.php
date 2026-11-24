<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\Foundation\Security\Risk\RiskChallengeVerifier;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\HttpOutboundDispatcher;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;

it('constructs only the approved native HTTPS POST and keeps proof out of its URI', function (): void {
    $endpoint = 'https://verify.example.test/siteverify?fixed=1';
    $provider = new RiskChallengeVerifier($endpoint, 'secret', 'app.example.test', 'tenant', 'login');
    $source = new CancellationSource();
    try {
        $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
        $request = $provider->prepare($context, 'proof&value', $source->token(), RequestDeadline::unlimited());
        \PHPUnit\Framework\Assert::assertInstanceOf(ClientRequest::class, $request);
        \PHPUnit\Framework\Assert::assertNotNull($request->body);
        expect($request->uriForSend())->toBe($endpoint)
            ->and($request->method)->toBe('POST')
            ->and($request->protocol->allowPlaintext)->toBeFalse()
            ->and($request->body->read())->toBe('secret=secret&response=proof%26value&remoteip=192.0.2.1');
        expect(fn() => new ClientRequest('POST', 'https://verify.example.test/siteverify?fixed=2', protocol: $request->protocol)->uriForSend())
            ->toThrow(InvalidArgumentException::class);
        expect($provider->prepare(RiskContext::fromTransport('other', 'login', '192.0.2.1'), 'proof', $source->token(), RequestDeadline::unlimited()))->toBeNull()
            ->and($provider->prepare($context, str_repeat('a', 4097), $source->token(), RequestDeadline::unlimited()))->toBeNull();
    } finally {
        $source->dispose();
    }
});

it('fails closed for redirects malformed responses and incorrect challenge scope', function (): void {
    $provider = new RiskChallengeVerifier('https://verify.example.test/siteverify', 'secret', 'app.example.test', 'tenant', 'login');
    $valid = '{"success":true,"hostname":"app.example.test","action":"login","cdata":"tenant"}';
    expect($provider->interpret(200, $valid))->toBeTrue()
        ->and($provider->interpret(302, $valid))->toBeNull()
        ->and($provider->interpret(200, '{"success":false}'))->toBeFalse()
        ->and($provider->interpret(200, str_replace('app.example.test', 'other.example.test', $valid)))->toBeFalse()
        ->and($provider->interpret(200, str_replace('login', 'other', $valid)))->toBeFalse()
        ->and($provider->interpret(200, str_replace('tenant', 'other', $valid)))->toBeFalse()
        ->and($provider->interpret(200, '{"success":"true"}'))->toBeNull()
        ->and($provider->interpret(200, str_repeat(' ', 16_385)))->toBeNull()
        ->and($provider->interpret(200, '{'))->toBeNull();
    foreach (['http://verify.example.test/', 'https://user@verify.example.test/', 'https://127.0.0.1/', 'https://verify.example.test/#fragment'] as $endpoint) {
        expect(fn() => new RiskChallengeVerifier($endpoint, 'secret', 'app.example.test', 'tenant', 'login'))->toThrow(InvalidArgumentException::class);
    }
});

it('blocks a private DNS answer before opening an outbound socket using Runwire', function (): void {
    $loop = new SelectLoop();
    $resolver = new class ($loop) implements DnsResolverInterface {
        public int $calls = 0;

        public function __construct(private readonly SelectLoop $loop) {}

        #[\Override]
        public function cancel(int $id): bool
        {
            $this->loop->cancel($id);

            return true;
        }

        /**
         * @param callable(list<string>,?\Throwable):void $completion
         * @throws \OverflowException
         */
        #[\Override]
        public function resolve(string $hostname, callable $completion, ?CancellationToken $token = null, ?RequestDeadline $deadline = null): int
        {
            ++$this->calls;

            return $this->loop->defer(static function () use ($completion, $hostname, $token, $deadline): void {
                if ($hostname === 'verify.example.test' && !($token?->isCancelled() ?? false) && !($deadline?->expired() ?? false)) {
                    $completion(['127.0.0.1'], null);
                } else {
                    $completion([], new \RuntimeException('Unexpected DNS request.'));
                }
            });
        }
    };
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, $resolver);
    $dispatcher = new HttpOutboundDispatcher($loop, $dialer);
    $provider = new RiskChallengeVerifier('https://verify.example.test/siteverify', 'secret', 'app.example.test', 'tenant', 'login');
    try {
        $result = new CoroutineRuntime($loop)->run(static fn(CoroutineScope $scope): ?bool => $provider->verify(
            $scope,
            $dispatcher,
            RiskContext::fromTransport('tenant', 'login', '192.0.2.1'),
            'proof',
        ));
        expect($result)->toBeNull()
            ->and($resolver->calls)->toBe(1)
            ->and($numeric->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0);
    } finally {
        $dialer->close();
        $numeric->close();
    }
});

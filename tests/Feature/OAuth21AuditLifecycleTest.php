<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('audits authorization code consumption at the authoritative Epicrypt outcome', function (
    string $outcome,
    AuthEventType $expectedType,
    string $expectedResult,
    bool $expectAuthorizationId,
): void {
    $now = 1_700_000_000;
    $capture = new OAuthAuditCapture();
    $fixture = new OAuth21FlowFixture($now, $capture->recorder($now));
    $redirectUri = 'https://client.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('a', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile:read'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile:read',
            'audience' => $audience,
        ]);
        $issue = $fixture->codes->issue(
            $request,
            new Principal('account-1', accountId: 'account-1'),
        );

        if ($outcome === 'expired') {
            $fixture->advance(61);
        } elseif ($outcome === 'replayed') {
            $fixture->codes->consume(
                $issue->code,
                $registration->client->clientId,
                $redirectUri,
                $verifier,
            );
            $capture->events = [];
        }

        $consume = fn() => $fixture->codes->consume(
            $issue->code,
            $registration->client->clientId,
            $redirectUri,
            $verifier,
        );
        if ($outcome === 'consumed') {
            $consume();
        } else {
            expect($consume)->toThrow(OAuthProtocolException::class, 'invalid_grant');
        }

        expect($capture->events)->toHaveCount(1)
            ->and($capture->events[0]->type)->toBe($expectedType)
            ->and($capture->events[0]->metadata['client_id'] ?? null)->toBe($registration->client->clientId)
            ->and($capture->events[0]->metadata['result'] ?? null)->toBe($expectedResult);

        if ($expectAuthorizationId) {
            expect($capture->events[0]->metadata['authorization_id'] ?? null)
                ->toBe($issue->authorization->id);
        } else {
            expect($capture->events[0]->metadata['authorization_id'] ?? null)->toBeNull();
        }
    } finally {
        $fixture->close();
    }
})->with([
    'consumed' => ['consumed', AuthEventType::OAUTH_AUTHORIZATION_CODE_CONSUMED, 'consumed', true],
    'expired sealed artifact' => ['expired', AuthEventType::OAUTH_INVALID_REQUEST, 'invalid', false],
    'replayed' => ['replayed', AuthEventType::OAUTH_AUTHORIZATION_CODE_REPLAY, 'replayed', false],
]);

it('audits OAuth endpoint throttling only when a request is rejected', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth']['rate_limits']['token'] = ['max' => 1, 'window' => 60];
    $capture = new OAuthAuditCapture();
    $factory = new OAuthHttpThrottleFactory(
        new ConfigRepository($config),
        $capture->recorder(),
    );
    $middleware = $factory->forEndpoint('token', Cache::memory('oauth-rate-limit-audit'));
    $request = Request::fake(method: 'POST', uri: '/oauth/token')
        ->withAttribute('client_ip', '203.0.113.10');
    $next = static function (Request $request): Response {
        unset($request);

        return Response::json(['ok' => true]);
    };
    $previousRequestTime = $_SERVER['REQUEST_TIME'] ?? null;
    $_SERVER['REQUEST_TIME'] = time();

    try {
        $middleware($request, $next);
        expect($capture->events)->toBe([]);

        try {
            $middleware($request, $next);
            throw new RuntimeException('Expected the second request in the fixed window to be throttled.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(429);
        }

        expect($capture->events)->toHaveCount(1)
            ->and($capture->events[0]->type)->toBe(AuthEventType::OAUTH_RATE_LIMITED)
            ->and($capture->events[0]->metadata)->toBe([
                'reason' => 'token',
                'result' => 'rejected',
            ]);
    } finally {
        if ($previousRequestTime === null) {
            unset($_SERVER['REQUEST_TIME']);
        } else {
            $_SERVER['REQUEST_TIME'] = $previousRequestTime;
        }
    }
});

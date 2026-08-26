<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCode;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeResult;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeStatus;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationCodeStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('audits authorization code consume expiry and replay at the atomic store outcome', function (
    OAuthAuthorizationCodeConsumeStatus $status,
    AuthEventType $expectedType,
): void {
    $now = 1_700_000_000;
    $code = new OAuthAuthorizationCode(
        id: 'code-id',
        codeHash: 'stored-hash',
        clientId: 'oc_client',
        accountId: 'account-1',
        authorizationId: 'authorization-1',
        redirectUriHash: hash('sha256', 'https://client.example.test/callback'),
        pkceChallenge: 'challenge',
        scopes: ['profile:read'],
        audiences: ['https://api.example.test'],
        issuedAt: $now - 30,
        expiresAt: $now + 30,
        consumedAt: $status === OAuthAuthorizationCodeConsumeStatus::Reused ? $now - 1 : null,
    );
    $result = new OAuthAuthorizationCodeConsumeResult($status, $code);
    $codes = new class($result) implements OAuthAuthorizationCodeStoreInterface {
        public function __construct(private OAuthAuthorizationCodeConsumeResult $result) {}
        public function save(OAuthAuthorizationCode $code): void {}
        public function consume(
            string $codeHash,
            string $clientId,
            string $redirectUriHash,
            string $pkceChallenge,
            int $now,
        ): OAuthAuthorizationCodeConsumeResult {
            return $this->result;
        }
    };
    $authorizations = new class implements OAuthAuthorizationStoreInterface {
        public function find(string $authorizationId): ?OAuthAuthorization { return null; }
        public function recent(int $limit = 100, ?string $clientId = null): array { return []; }
        public function revoke(string $authorizationId, int $revokedAt): bool { return false; }
        public function save(OAuthAuthorization $authorization): void {}
    };
    $authorizer = new class implements AuthorizerInterface {
        public function authorize(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): void {}
        public function can(PrincipalInterface $principal, string $ability, mixed $resource = null, array $context = []): AuthorizationDecision {
            throw new LogicException('Not used by authorization-code consumption.');
        }
    };
    $clock = new readonly class($now) implements ClockInterface {
        public function __construct(private int $now) {}
        public function now(): int { return $this->now; }
    };
    $capture = new OAuthAuditCapture();
    $manager = new AuthorizationCodeManager(
        codes: $codes,
        authorizations: $authorizations,
        authorizer: $authorizer,
        clock: $clock,
        tokens: new OpaqueToken(),
        audit: $capture->recorder($now),
    );
    $plainCode = new OpaqueToken()->issue(64);

    try {
        $manager->consume(
            $plainCode,
            'oc_client',
            'https://client.example.test/callback',
            str_repeat('A', 43),
        );
    } catch (OAuthProtocolException $exception) {
        expect($status)->not->toBe(OAuthAuthorizationCodeConsumeStatus::Consumed)
            ->and($exception->error)->toBe('invalid_grant');
    }

    expect($capture->events)->toHaveCount(1)
        ->and($capture->events[0]->type)->toBe($expectedType)
        ->and($capture->events[0]->metadata)->toMatchArray([
            'client_id' => 'oc_client',
            'authorization_id' => 'authorization-1',
            'result' => $status->value,
        ]);
})->with([
    'consumed' => [OAuthAuthorizationCodeConsumeStatus::Consumed, AuthEventType::OAUTH_AUTHORIZATION_CODE_CONSUMED],
    'expired' => [OAuthAuthorizationCodeConsumeStatus::Expired, AuthEventType::OAUTH_AUTHORIZATION_CODE_EXPIRED],
    'replayed' => [OAuthAuthorizationCodeConsumeStatus::Reused, AuthEventType::OAUTH_AUTHORIZATION_CODE_REPLAY],
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
    $next = static fn(Request $request): Response => Response::json(['ok' => true]);

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
});

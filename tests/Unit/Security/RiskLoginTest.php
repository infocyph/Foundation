<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskLogin;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
use Infocyph\Foundation\Security\Risk\RiskProofStore;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

it('admits only protected login attempts and records confirmed credential failures once', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $services = $app->make(AuthServices::class);
    $authenticator = $app->make(Authenticator::class);
    $services->accounts()->create('known@example.test', $services->passwordHasher()->hash('correct-secret'));
    $telemetry = new RiskTelemetry(Cache::memory(namespace: 'risk-login-' . bin2hex(random_bytes(8))), str_repeat('t', 32));
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $request = Request::fake(method: 'POST', uri: '/login');
    $render = static fn(LoginResult $result): Response => Response::json(['ok' => $result->authenticated()], $result->authenticated() ? 200 : 401);
    $guard = new RiskLogin($authenticator, new RiskPolicy(), $telemetry);
    $known = new LoginRequest('known@example.test', 'wrong');
    $unknown = new LoginRequest('unknown@example.test', 'wrong');

    expect($guard->handle($request, $known, $context, 120, $render)->getStatusCode())->toBe(401)
        ->and($telemetry->observe($context, 120, $known->identifier)['failedSignatureAttempts'])->toBe(1)
        ->and($guard->handle($request, $unknown, $context, 120, $render)->getStatusCode())->toBe(401)
        ->and($telemetry->observe($context, 120, $unknown->identifier)['failedSignatureAttempts'])->toBe(1);
    $denied = $guard->handle($request, $known, $context, 120, $render, hardDeny: true);
    expect($denied->getStatusCode())->toBe(403)
        ->and($denied->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($telemetry->observe($context, 120, $known->identifier)['failedSignatureAttempts'])->toBe(1);

    $shadow = new RiskLogin($authenticator, new RiskPolicy(shadow: true), $telemetry);
    expect($shadow->handle($request, $known, $context, 120, $render, hardDeny: true)->getStatusCode())->toBe(401)
        ->and($telemetry->observe($context, 120, $known->identifier)['failedSignatureAttempts'])->toBe(1);
    $authenticator->login($unknown);
    expect($telemetry->observe($context, 120, $unknown->identifier)['failedSignatureAttempts'])->toBe(1);
});

it('does not spend clearance on deny or shadow and consumes it before a failed password', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $services = $app->make(AuthServices::class);
    $authenticator = $app->make(Authenticator::class);
    $services->accounts()->create('risk@example.test', $services->passwordHasher()->hash('correct-secret'));
    $cache = Cache::memory(namespace: 'risk-clearance-login-' . bin2hex(random_bytes(8)), options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false));
    $authority = new RiskAuthority($cache, str_repeat('a', 32), shared: true);
    $proofs = new RiskProofStore($authority, 'fixture-provider');
    $telemetry = new RiskTelemetry($cache, str_repeat('t', 32));
    $policy = new RiskPolicy(failureAt: 1, failureWeight: 30);
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $request = Request::fake(method: 'POST', uri: '/login');
    $login = new LoginRequest('risk@example.test', 'incorrect');
    $render = static fn(LoginResult $result): Response => Response::json(['ok' => $result->authenticated()], $result->authenticated() ? 200 : 401);

    expect($telemetry->recordFailure($context, 120, $login->identifier))->toBeTrue();
    $token = $proofs->issueClearance($context, $login->identifier, 'verified-fixture-proof', 120);
    \PHPUnit\Framework\Assert::assertIsString($token);
    $guard = new RiskLogin($authenticator, $policy, $telemetry, $proofs);

    expect($guard->handle($request, $login, $context, 120, $render)->getStatusCode())->toBe(429)
        ->and($guard->handle($request, $login, $context, 120, $render, clearance: $token, hardDeny: true)->getStatusCode())->toBe(403);
    $shadow = new RiskLogin($authenticator, new RiskPolicy(failureAt: 1, failureWeight: 30, shadow: true), $telemetry, $proofs);
    expect($shadow->handle($request, $login, $context, 120, $render, clearance: $token)->getStatusCode())->toBe(401)
        ->and($telemetry->observe($context, 120, $login->identifier)['failedSignatureAttempts'])->toBe(1);

    $accepted = $guard->handle($request, $login, $context, 120, $render, clearance: $token);
    expect($accepted->getStatusCode())->toBe(401)
        ->and($accepted->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($telemetry->observe($context, 120, $login->identifier)['failedSignatureAttempts'])->toBe(2)
        ->and($guard->handle($request, $login, $context, 120, $render, clearance: $token)->getStatusCode())->toBe(429)
        ->and($telemetry->observe($context, 120, $login->identifier)['failedSignatureAttempts'])->toBe(2);

    $unavailable = new RiskLogin($authenticator, $policy, $telemetry);
    expect($unavailable->handle($request, $login, $context, 120, $render, clearance: $token)->getStatusCode())->toBe(503);
});

it('rejects exhausted capacity before authentication and releases owned capacity after login', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $services = $app->make(AuthServices::class);
    $authenticator = $app->make(Authenticator::class);
    $services->accounts()->create('capacity@example.test', $services->passwordHasher()->hash('correct-secret'));
    $cache = Cache::memory(namespace: 'risk-capacity-login-' . bin2hex(random_bytes(8)), options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false));
    $authority = new RiskAuthority($cache, str_repeat('a', 32), shared: true);
    $bulkhead = new RiskHashBulkhead($authority, 'fixture-pool', 1, nonEvictingIsolatedPool: true);
    $telemetry = new RiskTelemetry($cache, str_repeat('t', 32));
    $guard = new RiskLogin($authenticator, new RiskPolicy(), $telemetry, bulkhead: $bulkhead);
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $request = Request::fake(method: 'POST', uri: '/login');
    $login = new LoginRequest('capacity@example.test', 'correct-secret');
    $render = static fn(LoginResult $result): Response => Response::json(['ok' => $result->authenticated()], $result->authenticated() ? 200 : 401);

    expect($bulkhead->provisionDrainedPool())->toBeTrue();
    $owner = $bulkhead->acquire();
    \PHPUnit\Framework\Assert::assertNotNull($owner);
    $blocked = $guard->handle($request, $login, $context, 120, $render);
    expect($blocked->getStatusCode())->toBe(503)
        ->and($blocked->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($bulkhead->release($owner))->toBeTrue();
    expect($guard->handle($request, $login, $context, 120, $render)->getStatusCode())->toBe(200);
    $next = $bulkhead->acquire();
    \PHPUnit\Framework\Assert::assertNotNull($next);
    expect($bulkhead->release($next))->toBeTrue();
});

it('preserves independent lockout and completed authentication when feedback throws', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $services = $app->make(AuthServices::class);
    $authenticator = $app->make(Authenticator::class);
    $account = $services->accounts()->create('lockout@example.test', $services->passwordHasher()->hash('correct-secret'))->account;
    \PHPUnit\Framework\Assert::assertNotNull($account);
    $services->accounts()->create('success@example.test', $services->passwordHasher()->hash('correct-secret'));
    $outcomes = [];
    $observer = static function (bool $success) use (&$outcomes): void {
        $outcomes[] = $success;
        throw new \RuntimeException('Optional telemetry unavailable.');
    };

    expect($authenticator->login(new LoginRequest('success@example.test', 'correct-secret'), $observer)->authenticated())->toBeTrue()
        ->and($authenticator->login(new LoginRequest('absent@example.test', 'wrong'), $observer)->authenticated())->toBeFalse();
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        expect($authenticator->login(new LoginRequest('lockout@example.test', 'wrong'), $observer)->authenticated())->toBeFalse();
    }
    expect($services->lockouts()->isLocked($account->id()))->toBeTrue()
        ->and($authenticator->login(new LoginRequest('lockout@example.test', 'correct-secret'), $observer)->authenticated())->toBeFalse()
        ->and($outcomes)->toBe([true, false, false, false, false, false, false]);
});

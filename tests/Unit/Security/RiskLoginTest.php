<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskLogin;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
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

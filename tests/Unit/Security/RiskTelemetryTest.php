<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Support\IpCidr;
use InvalidArgumentException;

it('records bounded local failure windows without claiming distributed reliability', function (): void {
    $cache = Cache::memory(namespace: 'risk-local-' . bin2hex(random_bytes(8)), options: new CacheOptions(failOpen: false));
    $telemetry = new RiskTelemetry($cache, str_repeat('k', 32), concentrationAt: 2);
    $context = RiskContext::fromTransport('tenant-a', 'login', '10.0.0.1', [IpCidr::from('10.0.0.0/8')], ['192.0.2.1'], ['t13d1516h2_8daaf6152771_02713d6af862'], ['64512']);
    expect($telemetry->observe($context, 120, 'person@example.test'))->toBe([
        'familiar' => null, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => false,
    ]);
    expect($telemetry->recordFailure($context, 120, 'person@example.test'))->toBeTrue()
        ->and($telemetry->recordFailure($context, 120, 'person@example.test'))->toBeTrue()
        ->and($telemetry->recordSuccess($context))->toBeTrue();
    expect($telemetry->observe($context, 120, 'person@example.test'))->toBe([
        'familiar' => true, 'failedSignatureAttempts' => 2, 'concentrated' => true, 'reliable' => false,
    ])->and($telemetry->observe($context, 180, 'person@example.test'))->toBe([
        'familiar' => true, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => false,
    ]);
    $other = RiskContext::fromTransport('tenant-b', 'login', '10.0.0.1', [IpCidr::from('10.0.0.0/8')], ['192.0.2.1'], ['t13d1516h2_8daaf6152771_02713d6af862'], ['64512']);
    expect($telemetry->observe($other, 120, 'person@example.test')['failedSignatureAttempts'])->toBe(0)
        ->and($cache->get('person@example.test'))->toBeNull();
    $rotated = new RiskTelemetry($cache, str_repeat('n', 32));
    expect($rotated->observe($context, 120, 'person@example.test')['failedSignatureAttempts'])->toBe(0);
});

it('reports uncertainty without atomic capability and does not emulate atomic increments', function (): void {
    $telemetry = new RiskTelemetry(Cache::nullStore(new CacheOptions(failOpen: false)), str_repeat('k', 32), shared: true);
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    expect($telemetry->recordFailure($context, 120, 'account'))->toBeFalse()
        ->and($telemetry->observe($context, 120, 'account'))->toBe([
            'familiar' => null, 'failedSignatureAttempts' => 0, 'concentrated' => false, 'reliable' => false,
        ]);
    expect(fn() => $telemetry->recordFailure($context, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RiskTelemetry(Cache::nullStore(), 'short'))->toThrow(InvalidArgumentException::class);
});

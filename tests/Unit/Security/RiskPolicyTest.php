<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
use Infocyph\Webrick\Request\Support\IpCidr;
use InvalidArgumentException;

it('evaluates exact score boundaries without I/O', function (int $score, string $action): void {
    $policy = new RiskPolicy(concentrationWeight: $score);
    $result = $policy->evaluate(RiskContext::fromTransport('tenant', 'login', '192.0.2.1'), concentrated: true);
    expect($result->score)->toBe($score)->and($result->action)->toBe($action);
})->with([[0, 'allow'], [29, 'allow'], [30, 'challenge'], [69, 'challenge'], [70, 'deny']]);

it('does not invent mismatches from unknown fingerprints or missing telemetry', function (): void {
    $fingerprint = 't13d1516h2_8daaf6152771_02713d6af862';
    $context = RiskContext::fromTransport('tenant', 'login', '10.0.0.1', [IpCidr::from('10.0.0.0/8')], ['192.0.2.1'], [$fingerprint], [], ['Browser/1']);
    $policy = new RiskPolicy();
    expect($policy->evaluate($context)->score)->toBe(0)
        ->and($policy->evaluate($context, familiar: false)->score)->toBe(25)
        ->and($policy->evaluate($context, familiar: false)->action)->toBe('allow');
    $known = new RiskPolicy(agentMarkers: [$fingerprint => ['OtherBrowser/']]);
    expect($known->evaluate($context)->score)->toBe(60)
        ->and($known->evaluate($context)->reasons)->toBe(['agent_fingerprint_mismatch'])
        ->and($known->evaluate($context, familiar: false)->action)->toBe('deny');
    $matched = new RiskPolicy(agentMarkers: [$fingerprint => ['browser/']]);
    expect($matched->evaluate($context)->score)->toBe(0);
});

it('floors verified trust relief and preserves independent hard denial', function (): void {
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $policy = new RiskPolicy();
    expect($policy->evaluate($context, verifiedTrust: true)->score)->toBe(0)
        ->and($policy->evaluate($context, failedSignatureAttempts: 3)->score)->toBe(25)
        ->and($policy->evaluate($context, failedSignatureAttempts: 10)->score)->toBe(50);
    $result = $policy->evaluate($context, failedSignatureAttempts: 10, concentrated: true, verifiedTrust: true, hardDeny: true);
    expect($result->score)->toBe(25)
        ->and($result->action)->toBe('deny')
        ->and($result->reasons)->toContain('hard_deny');
});

it('marks shadow assessments without changing the calculated verdict', function (): void {
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $result = new RiskPolicy(shadow: true)->evaluate($context, failedSignatureAttempts: 10, concentrated: true);
    expect($result->shadow)->toBeTrue()
        ->and($result->action)->toBe('deny')
        ->and($result->score)->toBe(75);
});

it('rejects invalid scoring configuration before evaluation', function (): void {
    expect(fn() => new RiskPolicy(challengeAt: 70, denyAt: 70))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RiskPolicy(trustRelief: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RiskPolicy(agentMarkers: ['invalid' => ['agent']]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RiskPolicy(highFailureAt: 2))->toThrow(InvalidArgumentException::class);
});

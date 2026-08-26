<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Configuration\OAuthConfigValidator;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;

it('defines bounded OAuth endpoint rate-limit defaults', function (): void {
    $config = new ConfigRepository(AuthDefaults::all());
    $factory = new OAuthHttpThrottleFactory($config);

    expect($factory->policy('authorization'))->toBe([
        'max' => 60,
        'window' => 60,
        'scope' => 'foundation.oauth.authorization',
    ])->and($factory->policy('token'))->toBe([
        'max' => 30,
        'window' => 60,
        'scope' => 'foundation.oauth.token',
    ])->and($factory->policy('revocation'))->toBe([
        'max' => 60,
        'window' => 60,
        'scope' => 'foundation.oauth.revocation',
    ])->and($factory->policy('introspection'))->toBe([
        'max' => 120,
        'window' => 60,
        'scope' => 'foundation.oauth.introspection',
    ]);
});

it('rejects unknown OAuth throttle endpoints', function (): void {
    $factory = new OAuthHttpThrottleFactory(new ConfigRepository(AuthDefaults::all()));

    expect(fn() => $factory->policy('jwks'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported OAuth rate-limit endpoint');
});

it('keeps OAuth rate-limit validation inert while OAuth is disabled', function (): void {
    $defaults = AuthDefaults::all();
    $defaults['auth']['oauth']['rate_limits']['token']['max'] = 0;
    $issues = new OAuthConfigValidator(new ConfigRepository($defaults))->validate(false);

    expect($issues)->toBe([]);
});

it('rejects non-positive OAuth endpoint rate limits when OAuth is enabled', function (): void {
    $defaults = AuthDefaults::all();
    $defaults['auth']['oauth']['enabled'] = true;
    $defaults['auth']['oauth']['rate_limits']['token']['max'] = 0;
    $issues = new OAuthConfigValidator(new ConfigRepository($defaults))->validate(false);
    $keys = array_map(static fn($issue): string => $issue->key, $issues);

    expect($keys)->toContain('auth.oauth.rate_limits.token.max');
});
